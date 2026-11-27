<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_permission('sync_run');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_POST['csrf'], $_SESSION['csrf'])
    || !hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf'])) {
    http_response_code(400);
    exit('Solicitud no válida. Regresa al reporte e intenta de nuevo.');
}

$script = __DIR__ . DIRECTORY_SEPARATOR . 'sync' . DIRECTORY_SEPARATOR . 'sync_device.py';
$venvPython = __DIR__ . DIRECTORY_SEPARATOR . 'sync' . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
$python = getenv('PYTHON_EXECUTABLE') ?: (is_file($venvPython) ? $venvPython : 'python');
$command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --actor-id ' . (int)$_SESSION['app_user_id'] . ' 2>&1';
$outputLines = [];
exec($command, $outputLines, $exitCode);
$outputText = trim(implode("\n", $outputLines));
$success = $exitCode === 0 && count(array_filter($outputLines, static function ($line) {
    return str_starts_with(trim((string)$line), 'OK:') || str_starts_with(trim((string)$line), 'SKIP:');
})) > 0;
if (!$success) {
    try {
        $detail = $outputText !== '' ? substr($outputText, 0, 1800) : 'El proceso no devolvió detalles.';
        audit_event('SYNC_ERROR', 'Falló la sincronización. Detalle: ' . $detail, (int)$_SESSION['app_user_id']);
    } catch (Throwable $exception) { /* El puente registra el error si alcanza SQL. */ }
}
header('Location: ' . default_user_route(), true, 303);
