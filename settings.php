<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_admin();
$pdo = db();
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página.';
    } else {
        $ip = trim((string)($_POST['device_ip'] ?? ''));
        $port = filter_var($_POST['device_port'] ?? null, FILTER_VALIDATE_INT);
        $interval = filter_var($_POST['interval_minutes'] ?? null, FILTER_VALIDATE_INT);
        $enabled = isset($_POST['auto_enabled']) ? 1 : 0;
        $password = trim((string)($_POST['device_password'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || !$port || $port < 1 || $port > 65535 || !in_array($interval, [15, 30, 60], true) || strlen($password) > 64 || ($password !== '' && !ctype_digit($password))) {
            $error = 'Revisa la IP, el puerto, el intervalo o la clave de comunicación.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE dbo.rh_sync_config SET device_ip = ?, device_port = ?, device_password = CASE WHEN ? = N'' THEN device_password ELSE ? END, auto_enabled = ?, interval_minutes = ?, next_run_at = CASE WHEN ? = 1 THEN COALESCE(next_run_at, SYSUTCDATETIME()) ELSE NULL END, updated_at = SYSUTCDATETIME() WHERE id = 1");
                $stmt->execute([$ip, $port, $password, $password, $enabled, $interval, $enabled]);
                audit_event('CONFIG_CHANGED', 'Se actualizaron los ajustes del reloj y la sincronización automática.', (int)$_SESSION['app_user_id']);
                $notice = 'Configuración guardada en CHECADOR.';
            } catch (Throwable $exception) {
                $error = 'No se pudo guardar. Ejecuta sql/sync_config.sql y verifica permisos UPDATE/SELECT.';
            }
        }
    }
}
try {
    $config = $pdo->query('SELECT device_ip, device_port, auto_enabled, interval_minutes, last_run_at, next_run_at FROM dbo.rh_sync_config WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $config = null;
    $error = $error ?: 'Falta ejecutar sql/sync_config.sql.';
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configuración · Checador</title><style>body{font:15px system-ui;background:#f3f5f8;color:#17202a;margin:0}main{width:auto;max-width:none;box-sizing:border-box;margin:24px 0;padding:0 24px}.card{background:#fff;border:1px solid #dce2e9;border-radius:12px;padding:20px;margin:18px 0}form{display:grid;gap:14px}label{display:grid;gap:6px}input,select,button,a{font:inherit;padding:10px 12px;border:1px solid #bcc7d3;border-radius:7px}button,a{background:#173b67;color:#fff;text-decoration:none;border:0;cursor:pointer}.inline{display:flex;gap:9px;align-items:center}.inline input{width:auto}.hint{font-size:13px;color:#637083}.error{color:#a32323}.ok{color:#277341}</style><link rel="stylesheet" href="assets/app.css"></head><body><main><div class="brandbar"><img src="assets/logo.svg" alt="Transportes Aldez"><div class="brand-caption"><strong>Control de asistencia</strong>Recursos Humanos · CHECADOR</div></div><h1>Configuración de sincronización</h1><p><a href="index.php">Volver al reporte</a></p><?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><?php if($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?><?php if($config): ?><section class="card"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><label>IP del reloj<input name="device_ip" value="<?=h($config['device_ip'])?>" required></label><label>Puerto TCP<input type="number" name="device_port" min="1" max="65535" value="<?=h($config['device_port'])?>" required></label><label>Clave de comunicación del reloj<input type="password" name="device_password" maxlength="64" autocomplete="new-password" placeholder="Deja vacío para conservar la actual"><span class="hint">No es la contraseña de la página web. Se guarda en CHECADOR y no se muestra al volver a abrir esta pantalla.</span></label><label class="inline"><input type="checkbox" name="auto_enabled" value="1" <?=$config['auto_enabled'] ? 'checked' : ''?>> Sincronización automática</label><label>Intervalo<select name="interval_minutes"><option value="15" <?=$config['interval_minutes'] == 15 ? 'selected' : ''?>>Cada 15 minutos</option><option value="30" <?=$config['interval_minutes'] == 30 ? 'selected' : ''?>>Cada 30 minutos</option><option value="60" <?=$config['interval_minutes'] == 60 ? 'selected' : ''?>>Cada hora</option></select></label><button>Guardar</button></form><p class="hint">Última ejecución: <?=h($config['last_run_at'] ?: 'Todavía no ejecutada')?> · Próxima: <?=h($config['next_run_at'] ?: 'Pendiente')?></p></section><?php endif; ?></main></body></html>
