# Sistema de reloj checador

La raíz de este proyecto (`RH`) es la carpeta pública del sitio. Los PHP quedan aquí en la raíz; el sincronizador Python está en `sync/`. La aplicación usa PHP y SQL Server. El puente comunica la BD `CHECADOR` del servidor `HPSERVER1\COMPAC` (`192.168.10.38`) con el reloj ZKTeco F19 (`192.168.10.149`). La ficha del fabricante identifica al F19 y confirma TCP/IP y servidor web: [ficha técnica F19](https://www.zkteco-ea.com/wp-content/uploads/2023/05/f19_brochure.pdf).

## Funciones

- Reporte por fechas de todas las checadas disponibles en `att_punches`, agrupadas por empleado/día.
- Muestra primera entrada, última salida y todas las marcas intermedias, con tipo, estado y reloj de origen.
- Usa `att_day_details.shift_ID` y `att_shift.shift_start` para obtener el horario.
- Tolerancia de 10 minutos; después cobra $3 MXN por cada minuto empezado. Ejemplo: turno 08:00, checada 08:10 = $0 y 08:11 = $3.
- Exportación CSV.
- Sincronización manual de altas de usuarios y checadas en ambos sentidos.
- Inicio de sesión y administración de cuentas con credenciales guardadas con hash en tablas de `CHECADOR`.
- Historial de sincronizaciones guardado en `CHECADOR`.

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

1. Configura IIS/Apache para servir la raíz de RH y usar `index.php` como página inicial.
2. Copia `config.example.php` como `config.php` y configura la conexión SQL, o define `CHECADOR_DSN`, `CHECADOR_SQL_USER` y `CHECADOR_SQL_PASSWORD` como variables de entorno del servicio web. La raíz también contiene `.git`; instala las reglas de `.htaccess`/`web.config` y confirma que el servidor web bloquee `.git`, `sync/`, `sql/` y los archivos de configuración.
3. Ejecuta una vez `sql/install.sql` dentro de la base `CHECADOR`. Crea las tablas `rh_app_user` y `rh_sync_log`; no reemplaza ni borra tablas actuales.
4. Crea la primera cuenta administradora desde PowerShell con variables de entorno y PHP CLI:

   ```powershell
   $env:APP_ADMIN_USERNAME = 'admin'
   $env:APP_ADMIN_PASSWORD = 'pon-aqui-una-clave-larga-de-12-o-mas-caracteres'
   php sql/create_admin.php
   Remove-Item Env:APP_ADMIN_USERNAME
   Remove-Item Env:APP_ADMIN_PASSWORD
   ```

   El script solo funciona por CLI y solo si todavía no existe un administrador. Después, inicia sesión y administra cuentas en `users.php`.
5. La cuenta SQL de PHP requiere `SELECT` sobre las tablas de reporte y las dos tablas `rh_*`; también `INSERT` y `UPDATE` en `rh_app_user` para administrar accesos. La cuenta del sincronizador requiere los permisos indicados abajo y `INSERT` en `rh_sync_log`.

## Instalación del sincronizador Python

En una cuenta controlada del servidor web, crea un entorno virtual e instala solo las dependencias de sincronización:

```powershell
py -m venv sync\.venv
sync\.venv\Scripts\python.exe -m pip install -r sync\requirements.txt
```

Configura estas variables en el servicio web para que PHP y el sincronizador las reciban:

- `PYTHON_EXECUTABLE`: ruta completa a `sync\.venv\Scripts\python.exe`.
- `CHECADOR_SQL_SERVER`, `CHECADOR_SQL_DATABASE`, `CHECADOR_SQL_USER`, `CHECADOR_SQL_PASSWORD`, `CHECADOR_SQL_DRIVER`.
- `ZK_DEVICE_IP=192.168.10.149`, `ZK_DEVICE_PORT=4370` y `ZK_DEVICE_PASSWORD` (clave del protocolo del reloj, `0` si no tiene una).

La cuenta SQL usada por el sincronizador necesita `SELECT` sobre empleados, terminal y checadas, además de `INSERT` en `dbo.hr_employee`, `dbo.att_punches` y `dbo.rh_sync_log`. El proceso detecta si `id` es identity; si no lo es, asigna el siguiente ID dentro de una transacción. La cuenta del servicio web también debe tener permiso para iniciar Python.

El puente usa [`pyzk`](https://github.com/fananimi/pyzk), una librería comunitaria no oficial. No se usan los datos de acceso web mostrados en la captura. El error de la ruta `/csl/check` no participa en el proceso.

## Tablas que se esperan

Se usan las tablas ya presentes en el esquema proporcionado: `att_punches`, `att_terminal`, `att_day_details`, `att_shift`, `hr_employee` y `hr_department`. La instalación agrega `rh_app_user` para las cuentas del sitio y `rh_sync_log` para el historial de sincronización. Se espera que `hr_employee.emp_pin` coincida con el User ID del F19, y que los IDs sean numéricos porque `emp_pin` es INT en el esquema.

## Consideraciones para turnos

La entrada del día se toma de la primera marca cronológica. Si no hay turno asignado en `att_day_details`, muestra “Sin horario” y no calcula descuento. Turnos nocturnos y cambios de turno durante el día requieren confirmar cómo los representa la instalación actual en `att_day_details`.
