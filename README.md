# Sistema de reloj checador

La aplicación de consulta está en PHP y SQL Server. El único componente Python es el sincronizador que comunica la BD `CHECADOR` del servidor `HPSERVER1\COMPAC` (`192.168.10.38`) con el reloj ZKTeco F19 (`192.168.10.149`). La ficha del fabricante identifica al F19 y confirma TCP/IP y servidor web: [ficha técnica F19](https://www.zkteco-ea.com/wp-content/uploads/2023/05/f19_brochure.pdf).

## Funciones

- Reporte por fechas de todas las checadas disponibles en `att_punches`, agrupadas por empleado/día.
- Muestra primera entrada, última salida y todas las marcas intermedias, con tipo, estado y reloj de origen.
- Usa `att_day_details.shift_ID` y `att_shift.shift_start` para obtener el horario.
- Tolerancia de 10 minutos; después cobra $3 MXN por cada minuto empezado. Ejemplo: turno 08:00, checada 08:10 = $0 y 08:11 = $3.
- Exportación CSV.
- Sincronización manual de altas de usuarios y checadas en ambos sentidos.

El descuento solo se calcula para el reporte. El sistema no lo guarda como deducción de nómina.

## Qué sincroniza

- **SQL → reloj:** crea en el reloj usuarios activos de `hr_employee` que aún no estén en el F19, usando `emp_pin` como ID y nombre/apellidos como nombre visible.
- **Reloj → SQL:** crea en `hr_employee` las cuentas numéricas que existen en el F19 y faltan en SQL. El nombre se separa en nombre y apellido; `emp_active` queda en 1.
- **Reloj → SQL:** importa marcas del F19 a `att_punches`, evitando duplicar empleado/hora que ya esté almacenado.

No sincroniza huellas, contraseñas ni plantillas biométricas. Las personas agregadas al reloj desde SQL deberán registrar su huella directamente en el F19. En esta primera versión, la sincronización agrega usuarios nuevos; no sobrescribe los datos de usuarios que ya existen en ambos lados.

## Requisitos

- IIS o Apache con PHP 8.1+ y extensiones `PDO_SQLSRV` y `SQLSRV` instaladas.
- SQL Server ODBC Driver 18 para el sincronizador.
- Python 3.11+ y acceso de red desde el servidor web a SQL Server y al reloj.
- El reloj F19 debe tener IP `192.168.10.149`, acceso TCP/IP habilitado y su clave de comunicación conocida. La clave de la web del dispositivo es distinta.

## Instalación de la web

1. Configura IIS/Apache para servir la carpeta `public/`. No pongas la raíz del repositorio completa como raíz pública.
2. Copia `config.example.php` como `config.php` y configura la conexión SQL, o define `CHECADOR_DSN`, `CHECADOR_SQL_USER` y `CHECADOR_SQL_PASSWORD` como variables de entorno del servicio web.
3. El usuario SQL necesita `SELECT` sobre `att_punches`, `hr_employee`, `hr_department`, `att_terminal`, `att_day_details` y `att_shift` para mostrar los reportes.

## Instalación del sincronizador Python

En una cuenta controlada del servidor web, crea un entorno virtual e instala solo las dependencias de sincronización:

```powershell
py -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements-sync.txt
```

Configura estas variables en el servicio web para que PHP y el sincronizador las reciban:

- `PYTHON_EXECUTABLE`: ruta completa a `.venv\Scripts\python.exe`.
- `CHECADOR_SQL_SERVER`, `CHECADOR_SQL_DATABASE`, `CHECADOR_SQL_USER`, `CHECADOR_SQL_PASSWORD`, `CHECADOR_SQL_DRIVER`.
- `ZK_DEVICE_IP=192.168.10.149`, `ZK_DEVICE_PORT=4370` y `ZK_DEVICE_PASSWORD` (clave del protocolo del reloj, `0` si no tiene una).

La cuenta SQL usada por el sincronizador necesita `SELECT` sobre empleados, terminal y checadas, además de `INSERT` en `dbo.hr_employee` y `dbo.att_punches`. El proceso detecta si `id` es identity; si no lo es, asigna el siguiente ID dentro de una transacción. La cuenta del servicio web también debe tener permiso para iniciar Python.

El puente usa [`pyzk`](https://github.com/fananimi/pyzk), una librería comunitaria no oficial. No se usan los datos de acceso web mostrados en la captura. El error de la ruta `/csl/check` no participa en el proceso.

## Tablas que se esperan

Se usan las tablas ya presentes en el esquema proporcionado: `att_punches`, `att_terminal`, `att_day_details`, `att_shift`, `hr_employee` y `hr_department`. No se crean tablas nuevas. Se espera que `hr_employee.emp_pin` coincida con el User ID del F19, y que los IDs sean numéricos porque `emp_pin` es INT en el esquema.

## Consideraciones para turnos

La entrada del día se toma de la primera marca cronológica. Si no hay turno asignado en `att_day_details`, muestra “Sin horario” y no calcula descuento. Turnos nocturnos y cambios de turno durante el día requieren confirmar cómo los representa la instalación actual en `att_day_details`.
