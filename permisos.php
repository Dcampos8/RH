<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branding.php';
require_admin();

$pdo = db();
$catalog = permission_catalog();
$error = '';
$notice = isset($_GET['guardado']) ? 'Permisos guardados.' : '';
$users = [];
$selectedId = (int)($_GET['usuario'] ?? $_POST['user_id'] ?? 0);

try {
    $users = $pdo->query("SELECT id, username FROM dbo.sys_user WHERE LOWER(username) <> N'admin' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $error = 'No se pudieron consultar las cuentas de sys_user.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página.';
    } else {
        try {
            $target = $pdo->prepare("SELECT username FROM dbo.sys_user WHERE id = ? AND LOWER(username) <> N'admin'");
            $target->execute([$selectedId]);
            $targetUsername = $target->fetchColumn();
            if (!$targetUsername) throw new InvalidArgumentException('Selecciona una cuenta distinta de admin.');

            $values = [];
            foreach ($catalog as $permission => $_label) $values[$permission] = isset($_POST['permissions'][$permission]) ? 1 : 0;
            if ($values['employee_create']) $values['employee_view'] = 1;
            if ($values['schedule_manage']) $values['schedule_view'] = 1;
            if ($values['legal_notifications_manage']) $values['legal_notifications_view'] = 1;

            $existing = $pdo->prepare('SELECT COUNT(*) FROM dbo.rh_user_permissions WHERE user_id = ?');
            $existing->execute([$selectedId]);
            $fields = array_keys($catalog);
            if ((int)$existing->fetchColumn() > 0) {
                $setters = array_map(static fn($field) => '[' . $field . '] = ?', $fields);
                $update = $pdo->prepare('UPDATE dbo.rh_user_permissions SET ' . implode(', ', $setters) . ', updated_by = ?, updated_at = SYSUTCDATETIME() WHERE user_id = ?');
                $update->execute([...array_values($values), (int)$_SESSION['app_user_id'], $selectedId]);
            } else {
                $columns = implode(', ', array_map(static fn($field) => '[' . $field . ']', array_merge(['user_id'], $fields, ['updated_by'])));
                $placeholders = implode(', ', array_fill(0, count($fields) + 2, '?'));
                $insert = $pdo->prepare('INSERT INTO dbo.rh_user_permissions (' . $columns . ') VALUES (' . $placeholders . ')');
                $insert->execute([$selectedId, ...array_values($values), (int)$_SESSION['app_user_id']]);
            }
            audit_event('USER_PERMISSIONS_CHANGED', 'Se actualizaron permisos de ' . $targetUsername . '.', (int)$_SESSION['app_user_id']);
            header('Location: permisos.php?usuario=' . $selectedId . '&guardado=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'No se pudieron guardar los permisos. Ejecuta sql/user_permissions.sql y revisa permisos SQL. Detalle: ' . $exception->getMessage();
        }
    }
}

$selectedUser = null;
$permissions = array_fill_keys(array_keys($catalog), false);
$permissions['report_view'] = true;
if ($selectedId > 0) {
    foreach ($users as $user) if ((int)$user['id'] === $selectedId) $selectedUser = $user;
    if ($selectedUser) {
        try {
            $stmt = $pdo->prepare('SELECT ' . implode(', ', array_keys($catalog)) . ' FROM dbo.rh_user_permissions WHERE user_id = ?');
            $stmt->execute([$selectedId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) foreach ($catalog as $permission => $_label) $permissions[$permission] = (bool)$row[$permission];
        } catch (Throwable $exception) {
            $error = $error ?: 'Ejecuta sql/user_permissions.sql para habilitar el panel de permisos.';
        }
    }
}
$branding = branding_settings($pdo);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Permisos · Checador</title><link rel="stylesheet" href="assets/app.css"><style>.permission-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.permission-option{display:flex;align-items:flex-start;gap:11px;padding:14px;border:1px solid var(--line);border-radius:12px;background:#fff}.permission-option input{min-height:auto;margin-top:4px;accent-color:var(--brand)}.permission-option strong{display:block;color:var(--ink);font-size:14px}.permission-option small{display:block;margin-top:4px;color:var(--muted);font-weight:400}.permission-groups{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px}.permission-groups h2{font-size:16px}</style><link rel="icon" href="favicon.php?v=<?=time()?>" sizes="any"></head><body style="--brand:<?=h($branding['accent_color'])?>;--brand-dark:<?=h($branding['dark_color'])?>"><main><div class="brandbar"><img src="logo.php" alt="<?=h($branding['brand_name'])?>"><div class="brand-caption"><strong><?=h($branding['brand_name'])?></strong>Administración de accesos</div></div><h1>Panel de permisos</h1><p><a href="index.php">Volver al reporte</a></p>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><?php if ($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?>
<section class="card"><form method="get"><label>Cuenta<select name="usuario" required><option value="">Selecciona un usuario</option><?php foreach ($users as $user): ?><option value="<?=h($user['id'])?>" <?=$selectedId === (int)$user['id'] ? 'selected' : ''?>><?=h($user['username'])?></option><?php endforeach; ?></select></label><button>Editar permisos</button></form></section>
<?php if ($selectedUser): ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="user_id" value="<?=h($selectedId)?>"><div class="permission-groups"><section class="card"><h2>Reportes y personal</h2><div class="permission-grid"><?php foreach (['report_view', 'employee_view', 'employee_create', 'schedule_view', 'schedule_manage'] as $key): ?><label class="permission-option"><input type="checkbox" name="permissions[<?=h($key)?>]" value="1" <?=$permissions[$key] ? 'checked' : ''?>><span><strong><?=h($catalog[$key])?></strong><small><?php if ($key === 'employee_create'): ?>También habilita la vista de empleados.<?php elseif ($key === 'schedule_manage'): ?>También habilita la vista de horarios.<?php else: ?>Permite entrar a esta sección.<?php endif; ?></small></span></label><?php endforeach; ?></div></section><section class="card"><h2>Administración y módulos</h2><div class="permission-grid"><?php foreach (['platform_users_manage', 'settings_manage', 'sync_run', 'legal_notifications_view', 'legal_notifications_manage'] as $key): ?><label class="permission-option"><input type="checkbox" name="permissions[<?=h($key)?>]" value="1" <?=$permissions[$key] ? 'checked' : ''?>><span><strong><?=h($catalog[$key])?></strong><small>Se valida también al abrir la página o ejecutar la acción.</small></span></label><?php endforeach; ?></div></section></div><section class="card"><button>Guardar permisos de <?=h($selectedUser['username'])?></button><p class="hint">La cuenta admin conserva todos los permisos y no se puede limitar desde este panel.</p></section></form><?php endif; ?>
</main></body></html>
