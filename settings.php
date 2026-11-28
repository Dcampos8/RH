<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/module_tabs.php';
require_once __DIR__ . '/branding.php';
require_permission('settings_manage');
$pdo = db();
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página.';
    } elseif (($_POST['action'] ?? '') === 'branding') {
        $name = trim((string)($_POST['brand_name'] ?? ''));
        $accent = trim((string)($_POST['accent_color'] ?? ''));
        $dark = trim((string)($_POST['dark_color'] ?? ''));
        $removeLogo = isset($_POST['remove_logo']);
        $removeFavicon = isset($_POST['remove_favicon']);
        $file = $_FILES['logo'] ?? null;
        $faviconFile = $_FILES['favicon'] ?? null;
        try {
            $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
            if ($name === '' || $nameLength > 128 || !preg_match('/^#[0-9a-fA-F]{6}$/', $accent) || !preg_match('/^#[0-9a-fA-F]{6}$/', $dark)) {
                throw new InvalidArgumentException('Escribe un nombre de 1 a 128 caracteres y dos colores hexadecimales válidos.');
            }
            $logoBytes = null;
            $logoMime = null;
            $hasUpload = is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $hasFaviconUpload = is_array($faviconFile) && (int)($faviconFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($hasUpload) {
                if ($removeLogo) throw new InvalidArgumentException('Elige subir un logo nuevo o quitar el actual, no ambas opciones.');
                if ((int)$file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] < 1 || (int)$file['size'] > 3 * 1024 * 1024 || !is_uploaded_file((string)$file['tmp_name'])) {
                    throw new InvalidArgumentException('El logo debe ser un archivo de hasta 3 MB.');
                }
                $logoBytes = file_get_contents((string)$file['tmp_name']);
                $imageInfo = $logoBytes === false ? false : getimagesizefromstring($logoBytes);
                $detectedMime = is_array($imageInfo) ? ($imageInfo['mime'] ?? false) : false;
                if ($logoBytes === false || !$imageInfo || !in_array($detectedMime, ['image/png', 'image/jpeg', 'image/webp'], true) || $imageInfo[0] > 6000 || $imageInfo[1] > 3000 || ($imageInfo[0] * $imageInfo[1]) > 12000000) {
                    throw new InvalidArgumentException('Usa un logo PNG, JPG o WebP válido de máximo 6000 × 3000 px y 3 MB.');
                }
                $logoMime = $detectedMime;
            }
            if ($hasFaviconUpload) {
                if ($removeFavicon) throw new InvalidArgumentException('Elige subir un favicon nuevo o quitar el actual, no ambas opciones.');
                if ((int)$faviconFile['error'] !== UPLOAD_ERR_OK || (int)$faviconFile['size'] < 1 || (int)$faviconFile['size'] > 3 * 1024 * 1024 || !is_uploaded_file((string)$faviconFile['tmp_name'])) {
                    throw new InvalidArgumentException('El favicon debe ser una imagen válida de hasta 3 MB.');
                }
                $faviconBytes = file_get_contents((string)$faviconFile['tmp_name']);
                $faviconInfo = $faviconBytes === false ? false : getimagesizefromstring($faviconBytes);
                $faviconMime = is_array($faviconInfo) ? ($faviconInfo['mime'] ?? false) : false;
                $allowedFaviconMimes = ['image/png', 'image/x-png', 'image/jpeg', 'image/webp', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon'];
                if ($faviconBytes === false || !$faviconInfo) {
                    throw new InvalidArgumentException('PHP no reconoce el archivo como una imagen válida. Ábrelo y guárdalo de nuevo como PNG o JPG.');
                }
                if (!in_array($faviconMime, $allowedFaviconMimes, true)) {
                    throw new InvalidArgumentException('Formato de imagen no compatible. Formato detectado: ' . (string)$faviconMime . '. Usa PNG, JPG, WebP, GIF o ICO.');
                }
                if ($faviconInfo[0] < 1 || $faviconInfo[1] < 1 || $faviconInfo[0] > 2048 || $faviconInfo[1] > 2048 || ($faviconInfo[0] * $faviconInfo[1]) > 4194304) {
                    throw new InvalidArgumentException('La imagen detectada mide ' . (int)$faviconInfo[0] . ' × ' . (int)$faviconInfo[1] . ' px. Reduce su tamaño a máximo 2048 × 2048 px y vuelve a subirla.');
                }
            }

            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE dbo.rh_branding SET brand_name = ?, accent_color = ?, dark_color = ?, updated_at = SYSUTCDATETIME() WHERE id = 1');
            $update->execute([$name, $accent, $dark]);
            if ($hasUpload) {
                $logoStream = fopen('php://temp', 'w+b');
                if ($logoStream === false || fwrite($logoStream, $logoBytes) === false) {
                    throw new RuntimeException('No se pudo preparar el logo para guardarlo.');
                }
                rewind($logoStream);
                $saveLogo = $pdo->prepare('UPDATE dbo.rh_branding SET logo_data = ?, logo_mime = ? WHERE id = 1');
                $saveLogo->bindParam(1, $logoStream, PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
                $saveLogo->bindValue(2, $logoMime, PDO::PARAM_STR);
                try {
                    $saveLogo->execute();
                } finally {
                    fclose($logoStream);
                }
            } elseif ($removeLogo) {
                $pdo->exec('UPDATE dbo.rh_branding SET logo_data = NULL, logo_mime = NULL WHERE id = 1');
            }
            if ($hasFaviconUpload) {
                $faviconStream = fopen('php://temp', 'w+b');
                if ($faviconStream === false || fwrite($faviconStream, $faviconBytes) === false) {
                    throw new RuntimeException('No se pudo preparar el favicon para guardarlo.');
                }
                rewind($faviconStream);
                $saveFavicon = $pdo->prepare('UPDATE dbo.rh_branding SET favicon_data = ?, favicon_mime = ? WHERE id = 1');
                $saveFavicon->bindParam(1, $faviconStream, PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
                $saveFavicon->bindValue(2, $faviconMime, PDO::PARAM_STR);
                try {
                    $saveFavicon->execute();
                } finally {
                    fclose($faviconStream);
                }
            } elseif ($removeFavicon) {
                $pdo->exec('UPDATE dbo.rh_branding SET favicon_data = NULL, favicon_mime = NULL WHERE id = 1');
            }
            audit_event('BRANDING_CHANGED', 'Se actualizaron el nombre, los colores, el logo o el favicon del portal.', (int)$_SESSION['app_user_id']);
            $pdo->commit();
            $notice = 'Marca guardada en CHECADOR.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'No se pudo guardar la marca. Ejecuta sql/branding.sql y revisa permisos. Detalle: ' . $exception->getMessage();
        }
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
                $pdo->beginTransaction();
                $exists = $pdo->query('SELECT COUNT(*) FROM dbo.rh_sync_config WHERE id = 1')->fetchColumn();
                if ((int)$exists !== 1) throw new RuntimeException('No existe la fila id=1 en dbo.rh_sync_config; vuelve a ejecutar sql/sync_config.sql en CHECADOR.');
                if ($password === '') {
                    $stmt = $pdo->prepare('UPDATE dbo.rh_sync_config SET device_ip = ?, device_port = ?, auto_enabled = ?, interval_minutes = ?, next_run_at = CASE WHEN ? = 1 THEN DATEADD(minute, CONVERT(int, ?), SYSUTCDATETIME()) ELSE NULL END, updated_at = SYSUTCDATETIME() WHERE id = 1');
                    $stmt->execute([$ip, $port, $enabled, $interval, $enabled, $interval]);
                } else {
                    $stmt = $pdo->prepare('UPDATE dbo.rh_sync_config SET device_ip = ?, device_port = ?, device_password = ?, auto_enabled = ?, interval_minutes = ?, next_run_at = CASE WHEN ? = 1 THEN DATEADD(minute, CONVERT(int, ?), SYSUTCDATETIME()) ELSE NULL END, updated_at = SYSUTCDATETIME() WHERE id = 1');
                    $stmt->execute([$ip, $port, $password, $enabled, $interval, $enabled, $interval]);
                }
                $pdo->commit();
                try {
                    audit_event('CONFIG_CHANGED', 'Se actualizaron los ajustes del reloj y la sincronización automática.', (int)$_SESSION['app_user_id']);
                } catch (Throwable $auditException) {
                    error_log('[RH settings audit] ' . $auditException->getMessage());
                }
                $notice = 'Configuración guardada en CHECADOR.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[RH settings sync] ' . $exception->getMessage());
                $error = 'No se pudo guardar la clave/configuración. SQL dice: ' . $exception->getMessage();
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
$branding = branding_settings($pdo);
$hasCustomLogo = false;
$hasCustomFavicon = false;
try {
    $hasCustomLogo = (bool)$pdo->query('SELECT CASE WHEN logo_data IS NULL THEN 0 ELSE 1 END FROM dbo.rh_branding WHERE id = 1')->fetchColumn();
    $hasCustomFavicon = (bool)$pdo->query('SELECT CASE WHEN favicon_data IS NULL THEN 0 ELSE 1 END FROM dbo.rh_branding WHERE id = 1')->fetchColumn();
} catch (Throwable $exception) {
    $error = $error ?: 'Ejecuta sql/favicon.sql para agregar los campos de favicon a dbo.rh_branding.';
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configuración · Checador</title><style>body{font:15px system-ui;background:#f3f5f8;color:#17202a;margin:0}main{width:auto;max-width:none;box-sizing:border-box;margin:24px 0;padding:0 24px}.card{background:#fff;border:1px solid #dce2e9;border-radius:12px;padding:20px;margin:18px 0}form{display:grid;gap:14px}label{display:grid;gap:6px}input,select,button,a{font:inherit;padding:10px 12px;border:1px solid #bcc7d3;border-radius:7px}button,a{background:#173b67;color:#fff;text-decoration:none;border:0;cursor:pointer}.inline{display:flex;gap:9px;align-items:center}.inline input{width:auto}.hint{font-size:13px;color:#637083}.error{color:#a32323}.ok{color:#277341}</style><link rel="stylesheet" href="assets/app.css"><link rel="icon" href="favicon.php?v=<?=time()?>" sizes="any"></head><body style="--brand:<?=h($branding['accent_color'])?>;--brand-dark:<?=h($branding['dark_color'])?>"><main><div class="brandbar"><img src="logo.php" alt="<?=h($branding['brand_name'])?>"><div class="brand-caption"><strong><?=h($branding['brand_name'])?></strong>Recursos Humanos · CHECADOR</div></div><?php render_module_tabs('checador'); ?><h1>Configuración de sincronización</h1><p><a href="index.php">Volver al reporte</a></p><?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><?php if($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?><section class="card"><h2>Marca del portal</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="branding"><label>Nombre de la empresa<input name="brand_name" maxlength="128" value="<?=h($branding['brand_name'])?>" required></label><label>Color principal<input type="color" name="accent_color" value="<?=h($branding['accent_color'])?>"></label><label>Color secundario<input type="color" name="dark_color" value="<?=h($branding['dark_color'])?>"></label><label>Logo (PNG, JPG o WebP; máximo 3 MB)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label><label>Favicon de pestaña (PNG, JPG, WebP, GIF o ICO; máximo 3 MB, hasta 2048 × 2048 px)<input type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon,.ico"></label><?php if($hasCustomLogo): ?><div class="logo-preview"><img src="logo.php?v=<?=time()?>" alt="Logo actual"></div><label class="inline"><input type="checkbox" name="remove_logo" value="1"> Quitar logo personalizado y usar el predeterminado</label><?php endif; ?><?php if($hasCustomFavicon): ?><div class="logo-preview favicon-preview"><img src="favicon.php?v=<?=time()?>" alt="Favicon actual"></div><label class="inline"><input type="checkbox" name="remove_favicon" value="1"> Quitar favicon personalizado y usar el predeterminado</label><?php endif; ?><button>Guardar marca</button></form><p class="hint">El logo, el favicon, el nombre y los colores se guardan en CHECADOR y aparecen en todas las pantallas.</p></section><?php if($config): ?><section class="card"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="sync"><label>IP del reloj<input name="device_ip" value="<?=h($config['device_ip'])?>" required></label><label>Puerto TCP<input type="number" name="device_port" min="1" max="65535" value="<?=h($config['device_port'])?>" required></label><label>Clave de comunicación del reloj<input type="password" name="device_password" maxlength="64" autocomplete="new-password" placeholder="Deja vacío para conservar la actual"><span class="hint">No es la contraseña de la página web. Se guarda en CHECADOR y no se muestra al volver a abrir esta pantalla.</span></label><label class="inline"><input type="checkbox" name="auto_enabled" value="1" <?=$config['auto_enabled'] ? 'checked' : ''?>> Sincronización automática</label><label>Intervalo<select name="interval_minutes"><option value="15" <?=$config['interval_minutes'] == 15 ? 'selected' : ''?>>Cada 15 minutos</option><option value="30" <?=$config['interval_minutes'] == 30 ? 'selected' : ''?>>Cada 30 minutos</option><option value="60" <?=$config['interval_minutes'] == 60 ? 'selected' : ''?>>Cada hora</option></select></label><button>Guardar</button></form><p class="hint">Última ejecución: <?=h(format_utc_local($config['last_run_at']))?> · Próxima: <?=h(format_utc_local($config['next_run_at']))?></p><p class="hint">Windows debe tener instalada y activa la tarea RH_F19_Synchronization; esta tarea revisa el intervalo cada minuto. Las horas se muestran en Ciudad de México.</p></section><?php endif; ?></main></body></html>
