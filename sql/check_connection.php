<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/db.php';

try {
    $pdo = db();
    echo 'OK: conexión a SQL y base ' . $pdo->query('SELECT DB_NAME()')->fetchColumn() . PHP_EOL;
    $user = $pdo->query("SELECT TOP (1) username FROM dbo.sys_user WHERE username = N'admin'")->fetchColumn();
    echo $user ? "OK: se encontró la cuenta admin en sys_user." . PHP_EOL : "AVISO: no se encontró admin en sys_user." . PHP_EOL;
    $audit = $pdo->query("SELECT OBJECT_ID(N'dbo.sys_logs_auditoria', N'U')")->fetchColumn();
    echo $audit ? "OK: se encontró sys_logs_auditoria." . PHP_EOL : "AVISO: no se encontró sys_logs_auditoria." . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
