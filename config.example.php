<?php
// Copia este archivo como config.php y edítalo en el servidor web.
// config.php está excluido de Git para no publicar contraseñas.
return [
    'dsn' => getenv('CHECADOR_DSN') ?: 'sqlsrv:Server=192.168.10.38\\COMPAC;Database=CHECADOR;Encrypt=0;TrustServerCertificate=1',
    'user' => getenv('CHECADOR_SQL_USER') ?: '',
    'password' => getenv('CHECADOR_SQL_PASSWORD') ?: '',
];
