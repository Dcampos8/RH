<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/db.php';
$username = trim((string)getenv('APP_ADMIN_USERNAME'));
$password = (string)getenv('APP_ADMIN_PASSWORD');
if ($username === '' || $password === '' || strlen($password) < 12) {
    fwrite(STDERR, "Define APP_ADMIN_USERNAME y APP_ADMIN_PASSWORD (12+ caracteres) en el entorno antes de ejecutar este script.\n");
    exit(2);
}
try {
    $pdo = db();
    $count = (int)$pdo->query("SELECT COUNT(*) FROM dbo.rh_app_user WHERE role = 'admin'")->fetchColumn();
    if ($count > 0) {
        fwrite(STDERR, "Ya existe un administrador; crea usuarios desde users.php.\n");
        exit(3);
    }
    $stmt = $pdo->prepare("INSERT INTO dbo.rh_app_user (username, password_hash, role, is_active, created_at) VALUES (?, ?, 'admin', 1, SYSUTCDATETIME())");
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    echo "Administrador creado en CHECADOR.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "No se pudo crear el administrador. Revisa sql/install.sql y la conexión de CHECADOR.\n");
    exit(1);
}
