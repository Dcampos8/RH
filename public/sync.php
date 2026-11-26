<?php
declare(strict_types=1);
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_POST['csrf'], $_SESSION['csrf'])
    || !hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf'])) {
    http_response_code(400);
    exit('Solicitud no válida. Regresa al reporte e intenta de nuevo.');
}

$script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sync_device.py';
$python = getenv('PYTHON_EXECUTABLE') ?: 'python';
$command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' 2>&1';
$output = shell_exec($command);
if (!is_string($output) || trim($output) === '') {
    $_SESSION['sync_message'] = 'No se pudo iniciar el sincronizador Python. Revisa PHP y la configuración del proceso.';
} elseif (str_starts_with(trim($output), 'OK:')) {
    $_SESSION['sync_message'] = trim($output);
} else {
    // No mostramos detalles técnicos ni credenciales en la página.
    $_SESSION['sync_message'] = 'La sincronización falló. Revisa el registro del servidor para ver el detalle.';
}
header('Location: index.php', true, 303);
