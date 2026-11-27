<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branding.php';
$branding = branding_settings();

if (!empty($_SESSION['app_user_id'])) {
    header('Location: ' . default_user_route(), true, 303);
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página e intenta de nuevo.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            $stmt = db()->prepare('SELECT id, username, user_pwd FROM dbo.sys_user WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $storedPassword = $user ? rtrim((string)$user['user_pwd']) : '';
            $hashInfo = password_get_info($storedPassword);
            $validPassword = $user && $password !== '' && $storedPassword !== '' && (password_verify($password, $storedPassword)
                || (empty($hashInfo['algo']) && hash_equals($storedPassword, $password)));
            if ($validPassword) {
                session_regenerate_id(true);
                $_SESSION['app_user_id'] = (int)$user['id'];
                $_SESSION['app_username'] = (string)$user['username'];
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                try {
                    audit_event('LOGIN_OK', 'Inicio de sesión correcto.', (int)$user['id'], (string)$user['username']);
                } catch (Throwable $auditException) {
                    error_log('[RH login] Sesión válida, pero falló la auditoría: ' . $auditException->getMessage());
                }
                header('Location: ' . default_user_route(), true, 303);
                exit;
            }
            $error = 'Usuario o contraseña incorrectos.';
        } catch (Throwable $exception) {
            error_log('[RH login] Falló la consulta de acceso a CHECADOR: ' . $exception->getMessage());
            $error = 'No se pudo validar el acceso. Verifica la conexión con CHECADOR.';
        }
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso · Checador</title>
<style>body{font:16px system-ui;background:#f3f5f8;color:#17202a;min-height:100vh;display:grid;place-items:center;margin:0}.box{background:white;border:1px solid #dce2e9;border-radius:14px;padding:28px;width:min(360px,calc(100% - 40px));box-sizing:border-box}h1{margin-top:0}label{display:grid;gap:7px;margin:16px 0}input,button{font:inherit;padding:11px;border:1px solid #bcc7d3;border-radius:7px}button{width:100%;background:#173b67;color:white;border:0;cursor:pointer}.error{background:#fff0f0;color:#a32323;padding:10px;border-radius:8px}</style><link rel="stylesheet" href="assets/app.css"><link rel="icon" href="favicon.php?v=<?=time()?>" sizes="any"></head><body style="--brand:<?=h($branding['accent_color'])?>;--brand-dark:<?=h($branding['dark_color'])?>"><main class="box"><div class="brandbar"><img src="logo.php" alt="<?=h($branding['brand_name'])?>"><div class="brand-caption"><strong><?=h($branding['brand_name'])?></strong>Recursos Humanos · CHECADOR</div></div><h1>Reloj checador</h1><p>Inicia sesión para continuar.</p><?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><label>Usuario<input name="username" autocomplete="username" maxlength="100" required autofocus></label><label>Contraseña<input type="password" name="password" autocomplete="current-password" required></label><button>Entrar</button></form></main></body></html>
