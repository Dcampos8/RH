<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_admin();
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $name = trim((string)($_POST['username'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
                if (!preg_match('/^[\pL\pN._@-]{3,100}$/u', $name) || strlen($password) < 12) {
                    throw new InvalidArgumentException('Usuario de 3 a 100 caracteres; contraseña de al menos 12 caracteres.');
                }
                $stmt = db()->prepare('INSERT INTO dbo.rh_app_user (username, password_hash, role, is_active, created_at) VALUES (?, ?, ?, 1, SYSUTCDATETIME())');
                $stmt->execute([$name, password_hash($password, PASSWORD_DEFAULT), $role]);
                $notice = 'Usuario creado.';
            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id === (int)$_SESSION['app_user_id']) throw new InvalidArgumentException('No puedes desactivar tu propio usuario.');
                $stmt = db()->prepare('UPDATE dbo.rh_app_user SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?');
                $stmt->execute([$id]);
                $notice = 'Estado actualizado.';
            }
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'No se pudo guardar el usuario. Puede que el nombre ya exista.';
        }
    }
}
$users = db()->query('SELECT id, username, role, is_active, created_at FROM dbo.rh_app_user ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Usuarios · Checador</title><style>body{font:15px system-ui;background:#f3f5f8;color:#17202a;margin:0}main{max-width:900px;margin:32px auto;padding:0 18px}.card{background:white;border:1px solid #dce2e9;border-radius:12px;padding:18px;margin:18px 0}form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}label{display:grid;gap:5px}input,select,button,a{font:inherit;padding:9px 12px;border:1px solid #bcc7d3;border-radius:7px}button,a{background:#173b67;color:white;text-decoration:none;cursor:pointer;border:0}.error{color:#a32323}.ok{color:#277341}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:9px;border-bottom:1px solid #e5e9ee}</style></head><body><main><h1>Usuarios de acceso</h1><p><a href="index.php">Volver al reporte</a></p><?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><?php if($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?><section class="card"><h2>Crear usuario</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="create"><label>Usuario<input name="username" minlength="3" maxlength="100" required></label><label>Contraseña (12+ caracteres)<input type="password" name="password" minlength="12" required></label><label>Rol<select name="role"><option value="user">Usuario</option><option value="admin">Administrador</option></select></label><button>Crear</button></form></section><section class="card"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach($users as $user): ?><tr><td><?=h($user['username'])?></td><td><?=h($user['role'])?></td><td><?=$user['is_active'] ? 'Activo' : 'Inactivo'?></td><td><?php if((int)$user['id'] !== (int)$_SESSION['app_user_id']): ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=h($user['id'])?>"><button><?=$user['is_active'] ? 'Desactivar' : 'Activar'?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section></main></body></html>
