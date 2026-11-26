"""Puente de sincronización SQL Server <-> ZKTeco F19. Sin funciones de reporte."""
from __future__ import annotations

import os
import sys
from datetime import datetime

import pyodbc
from zk import ZK


def sql_connection():
    server = os.getenv("CHECADOR_SQL_SERVER", r"192.168.10.38\COMPAC")
    database = os.getenv("CHECADOR_SQL_DATABASE", "CHECADOR")
    user = os.getenv("CHECADOR_SQL_USER", "")
    password = os.getenv("CHECADOR_SQL_PASSWORD", "")
    driver = os.getenv("CHECADOR_SQL_DRIVER", "ODBC Driver 18 for SQL Server")
    if user:
        auth = f"UID={user};PWD={password};"
    else:
        auth = "Trusted_Connection=yes;"
    return pyodbc.connect(
        f"DRIVER={{{driver}}};SERVER={server};DATABASE={database};{auth}"
        "Encrypt=no;TrustServerCertificate=yes;Connection Timeout=8;"
    )


def allocate_id(cursor, table: str) -> tuple[bool, int | None]:
    cursor.execute("SELECT COLUMNPROPERTY(OBJECT_ID(?), 'id', 'IsIdentity')", table)
    identity = cursor.fetchone()[0] == 1
    if identity:
        return True, None
    # Bloquea el rango mientras dura la transacción, para evitar IDs duplicados.
    cursor.execute(f"SELECT COALESCE(MAX(id), 0) + 1 FROM {table} WITH (UPDLOCK, HOLDLOCK)")
    return False, int(cursor.fetchone()[0])


def main() -> int:
    device_ip = os.getenv("ZK_DEVICE_IP", "192.168.10.149")
    device_port = int(os.getenv("ZK_DEVICE_PORT", "4370"))
    device_password = int(os.getenv("ZK_DEVICE_PASSWORD", "0"))
    client = ZK(device_ip, port=device_port, timeout=10, password=device_password,
                force_udp=False, ommit_ping=True)
    device = None
    try:
        device = client.connect()
        # Solo lecturas del terminal al obtener usuarios/checadas. La única
        # escritura al terminal es crear cuentas que existen en CHECADOR.
        terminal_users = device.get_users() or []
        attendance = device.get_attendance() or []
        by_pin = {str(getattr(u, "user_id", "")).strip(): u for u in terminal_users}

        with sql_connection() as connection:
            cursor = connection.cursor()
            cursor.execute("SELECT id, emp_pin, emp_firstname, emp_lastname, emp_active FROM dbo.hr_employee")
            employees = cursor.fetchall()
            by_db_pin = {str(row.emp_pin).strip(): row for row in employees}
            is_identity, next_emp_id = allocate_id(cursor, "dbo.hr_employee")

            imported_users = 0
            for user in terminal_users:
                pin = str(getattr(user, "user_id", "")).strip()
                if not pin.isdigit() or pin in by_db_pin:
                    continue
                # hr_employee.emp_pin es INT; registros sin PIN numérico no se
                # insertan automáticamente para evitar asignaciones ambiguas.
                name = str(getattr(user, "name", "") or "Usuario reloj").strip()
                parts = name.split(maxsplit=1)
                first_name = parts[0][:128] if parts else "Usuario"
                last_name = parts[1][:128] if len(parts) > 1 else "Reloj"
                fields = ["emp_pin", "emp_code", "emp_firstname", "emp_lastname", "emp_active"]
                values = [int(pin), pin[:128], first_name, last_name, 1]
                if not is_identity:
                    fields.insert(0, "id")
                    values.insert(0, next_emp_id)
                    next_emp_id += 1
                cursor.execute(
                    f"INSERT INTO dbo.hr_employee ({', '.join('[' + f + ']' for f in fields)}) "
                    f"VALUES ({', '.join('?' for _ in fields)})", values,
                )
                imported_users += 1

            pushed_users = 0
            occupied_uids = [int(getattr(u, "uid", 0) or 0) for u in terminal_users]
            next_uid = max(occupied_uids, default=0) + 1
            for employee in employees:
                pin = str(employee.emp_pin).strip()
                if not pin.isdigit() or int(employee.emp_active) != 1 or pin in by_pin:
                    continue
                name = (str(employee.emp_firstname or "") + " " + str(employee.emp_lastname or "")).strip()[:24]
                device.set_user(uid=next_uid, user_id=pin, name=name, privilege=0,
                                password="", group_id="", card=0)
                next_uid += 1
                pushed_users += 1

            # Volvemos a leer para incluir también a los usuarios recién
            # registrados desde el reloj antes de importar sus checadas.
            cursor.execute("SELECT id, emp_pin FROM dbo.hr_employee")
            emp_id_by_pin = {str(pin).strip(): emp_id for emp_id, pin in cursor.fetchall()}
            cursor.execute("SELECT TOP (1) id FROM dbo.att_terminal WHERE terminal_tcpip = ?", device_ip)
            terminal_row = cursor.fetchone()
            terminal_id = terminal_row[0] if terminal_row else None
            is_punch_identity, next_punch_id = allocate_id(cursor, "dbo.att_punches")
            cursor.execute("SELECT emp_id, punch_time FROM dbo.att_punches")
            known_marks = {(emp_id, stamp) for emp_id, stamp in cursor.fetchall()}
            imported_marks = skipped_marks = unmatched_marks = 0

            for mark in attendance:
                emp_id = emp_id_by_pin.get(str(mark.user_id).strip())
                if emp_id is None:
                    unmatched_marks += 1
                    continue
                stamp = mark.timestamp.replace(microsecond=0)
                if (emp_id, stamp) in known_marks:
                    skipped_marks += 1
                    continue
                fields = ["emp_id", "punch_time", "workcode", "workstate", "terminal_id", "punch_type"]
                values = [emp_id, stamp, str(getattr(mark, "workcode", "") or ""),
                          str(getattr(mark, "status", "") or ""), terminal_id,
                          str(getattr(mark, "punch", "") or "")]
                if not is_punch_identity:
                    fields.insert(0, "id")
                    values.insert(0, next_punch_id)
                    next_punch_id += 1
                cursor.execute(
                    f"INSERT INTO dbo.att_punches ({', '.join('[' + f + ']' for f in fields)}) "
                    f"VALUES ({', '.join('?' for _ in fields)})", values,
                )
                known_marks.add((emp_id, stamp))
                imported_marks += 1

            connection.commit()
        print(
            f"OK: usuarios reloj→SQL {imported_users}; usuarios SQL→reloj {pushed_users}; "
            f"checadas nuevas {imported_marks}; ya existentes {skipped_marks}; "
            f"sin empleado asociado {unmatched_marks}."
        )
        return 0
    except Exception as exc:
        print(f"Error de sincronización: {exc}", file=sys.stderr)
        return 1
    finally:
        if device is not None:
            try:
                device.disconnect()
            except Exception:
                pass


if __name__ == "__main__":
    raise SystemExit(main())
