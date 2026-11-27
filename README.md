# Reloj checador RH

La raíz `RH` es la carpeta pública y contiene la aplicación PHP. La sincronización del F19 se ejecuta con el único componente Python, en `sync/`. Los datos de empleados y checadas permanecen en la base existente `CHECADOR` (`HPSERVER1\COMPAC`, `192.168.10.38`); el reloj es ZKTeco F19 (`192.168.10.149`).

## Base de datos y acceso

- El inicio de sesión utiliza `dbo.sys_user`, que ya existe en CHECADOR. Se usa `username` y `user_pwd`; no se crea una tabla de usuarios paralela.
- La cuenta `admin` conserva acceso total. Desde **Permisos** puede activar por separado reportes, vista/alta de empleados, vista/administración de horarios, cuentas de plataforma, configuración y sincronización para cada cuenta de `sys_user`.
- Las cuentas nuevas y existentes empiezan con acceso al reporte solamente. Cada sección y acción vuelve a validar el permiso en PHP; los enlaces ocultos no son la única protección.
- Las cuentas nuevas se insertan en `sys_user`. La aplicación guarda sus contraseñas con bcrypt dentro de `user_pwd` (la columna existente es de 64 caracteres). Las contraseñas existentes en texto directo también se pueden validar; no se cambian automáticamente.
- Las cuentas creadas aquí son para este portal; el programa de escritorio original podría usar otro formato de contraseña.
- Las acciones se guardan en `dbo.sys_logs_auditoria`, tabla existente en el esquema recibido.
- `sql/sync_config.sql` crea solamente `dbo.rh_sync_config`, donde se guardan la IP, puerto, clave de comunicación, intervalo y estado de sincronización automática. No toca las tablas de RH.

## Reporte y sincronización

- Reporte por fechas de las checadas de `att_punches`, agrupadas por empleado y día. Muestra entrada, salida y todas las marcas intermedias.
- El horario se obtiene primero de `att_day_details.shift_ID`; si no hay uno, usa la asignación por empleado, día de semana y vigencia de `rh_employee_schedule`.
- En **Horarios**, `admin` crea turnos en `att_shift` y los asigna por persona, días de semana y vigencia. Un par de marcas antes del fin del turno se clasifica como salida y regreso de comida; hasta que haya una checada cercana al fin del turno, la salida queda vacía. Las checadas posteriores se muestran como salida tardía o como entrada/salida extra, con la duración extra calculada. La heurística usa una ventana de 90 minutos al cierre del turno; si hay varias checadas extra, se interpreta la primera y la última como entrada y salida extra.
- En **Empleados del checador**, `admin` da de alta al personal en `hr_employee` con su PIN; el sistema genera una contraseña aleatoria de cuatro dígitos en `emp_pwd` y la sincronización la manda al F19. Se muestra una sola vez al registrar al empleado.
- Tolerancia de 10 minutos; al llegar al minuto 11 se calcula $3 MXN por cada minuto completo de retardo. El cálculo es informativo y no se escribe como deducción de nómina.
- El sincronizador crea en el reloj los usuarios activos de SQL que aún faltan en el F19; también importa usuarios numéricos nuevos del reloj a `hr_employee` y sus checadas a `att_punches`.
- No copia huellas ni plantillas biométricas. Las huellas de las cuentas creadas en el reloj se registran directamente en el F19.

## Instalación inicial

1. Configura IIS o Apache para servir RH y usar `index.php` como documento inicial. El servidor necesita PHP 8.1+, `PDO_SQLSRV` y SQL Server ODBC Driver 18.
2. Configura la conexión PHP a SQL Server en `config.php` (copia `config.example.php`) o con las variables `CHECADOR_DSN`, `CHECADOR_SQL_USER` y `CHECADOR_SQL_PASSWORD`.
3. Ejecuta una vez `sql/sync_config.sql` en la base CHECADOR.
4. Ejecuta una vez `sql/schedule_assignments.sql` en CHECADOR para crear la tabla de asignación de horarios.
5. Ejecuta una vez `sql/expand_sys_user_password.sql` para ampliar `sys_user.user_pwd` a 255 caracteres y guardar hashes seguros.
6. Ejecuta `sql/branding.sql` para activar el editor de logo, nombre, colores y favicon, guardados en CHECADOR.
7. Ejecuta una vez `sql/favicon.sql` para agregar los campos del favicon a una tabla `rh_branding` ya existente. Si aún no habías creado esa tabla, `sql/branding.sql` ya incluye esos campos y este paso adicional no es necesario.
8. Ejecuta una vez `sql/user_permissions.sql` para crear la tabla de permisos por cuenta.
9. Inicia sesión usando la cuenta `admin` existente en `sys_user`. La contraseña se valida contra el valor actual de `user_pwd`.
10. Abre **Permisos** y activa para cada usuario solo las secciones y acciones que necesite. La cuenta `admin` siempre conserva todos los permisos. En **Empleados del checador**, da de alta al personal con un PIN que no exista y pulsa **Sincronizar ahora** para enviarlo al reloj. En **Horarios**, crea turnos y asígnalos al personal activo. `users.php` es únicamente para cuentas de acceso a la plataforma.

Al publicar RH como raíz web, bloquea el acceso HTTP a `.git/`, `sync/`, `sql/`, `config.php`, `config.example.php`, `.gitignore`, `.gitattributes` y `README.md`. Se incluyen `.htaccess` y `web.config`; confirma las reglas de acuerdo con tu IIS/Apache.

## Configurar sincronización automática

Entra como `admin`, abre **Configuración**, deja activa la sincronización automática y selecciona 15 minutos (es el valor inicial). También puedes ajustar IP, puerto y clave de comunicación del F19. Esa clave no es el usuario ni la contraseña del sitio web.

Para que Windows revise el horario, instala Python 3.11+, ODBC Driver 18 y las dependencias del puente:

```powershell
py -m venv sync\.venv
sync\.venv\Scripts\python.exe -m pip install -r sync\requirements.txt
```

Define `PYTHON_EXECUTABLE` para el servicio PHP. Configura las variables `CHECADOR_SQL_SERVER`, `CHECADOR_SQL_DATABASE`, `CHECADOR_SQL_USER` y `CHECADOR_SQL_PASSWORD` para la cuenta Windows que usará la tarea (o usa autenticación integrada si esa cuenta tiene permiso en SQL). Después abre PowerShell con esa cuenta y ejecuta:

```powershell
.\sync\install-task.ps1
```

El instalador solicita la cuenta de Windows que ejecutará la tarea. El Programador de tareas despierta el puente cada minuto; el intervalo efectivo (15, 30 o 60 minutos) lo consulta desde `rh_sync_config`. Al guardar un nuevo intervalo, la próxima ejecución se programa desde ese momento. Puedes pausar la tarea desde Configuración sin quitar la tarea de Windows. Para desinstalarla: `Unregister-ScheduledTask -TaskName RH_F19_Synchronization -Confirm:$false`.

El proceso de Windows necesita acceso a SQL Server y al puerto configurado del reloj. La cuenta SQL requiere `SELECT` en `hr_employee`, `att_terminal`, `att_punches` y `sys_user`; `INSERT` en `hr_employee`, `att_punches` y `sys_logs_auditoria`; además de `SELECT`/`UPDATE` en `rh_sync_config`. La aplicación PHP requiere `SELECT` en tablas de reporte, `sys_user`, `sys_logs_auditoria`, `rh_sync_config`, `rh_branding`, `att_shift` y `rh_employee_schedule` y `rh_user_permissions`; `INSERT` en `sys_logs_auditoria` y `rh_user_permissions`; `UPDATE` en `rh_user_permissions` y `rh_branding`; para administrar cuentas `INSERT`/`UPDATE` en `sys_user`; y para horarios `INSERT` en `att_shift` más `SELECT`/`INSERT`/`DELETE` en `rh_employee_schedule`.

El puente usa [`pyzk`](https://github.com/fananimi/pyzk), librería comunitaria no oficial. Verifica primero la clave de comunicación y la conectividad del F19. La ruta web `/csl/check` de la captura no se usa.
