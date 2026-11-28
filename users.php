<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/module_tabs.php';
require_once __DIR__ . '/branding.php';
require_permission('platform_users_manage');

$pdo = db();
$branding = branding_settings($pdo);
$isAdmin = is_admin_user();
$error = '';
$notice = '';
$selectedId = (int)($_GET['edit'] ?? 0);
$passwordFlash = $_SESSION['user_password_flash'] ?? null;
unset($_SESSION['user_password_flash']);

function check_password_hash_fits(PDO $pdo, string $hash): void
{
    $stmt = $pdo->query("SELECT c.max_length, t.name AS data_type FROM sys.columns AS c JOIN sys.types AS t ON t.user_type_id = c.user_type_id WHERE c.object_id = OBJECT_ID(N'dbo.sys_user') AND c.name = N'user_pwd'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$column) throw new RuntimeException('No se encontró dbo.sys_user.user_pwd.');
    $maxBytes = (int)$column['max_length'];
    $maxChars = $maxBytes === -1 ? PHP_INT_MAX
        : (in_array(strtolower((string)$column['data_type']), ['nvarchar', 'nchar'], true) ? intdiv($maxBytes, 2) : $maxBytes);
    if (strlen($hash) > $maxChars) {
        throw new RuntimeException('sys_user.user_pwd permite ' . $maxChars . ' caracteres; bcrypt requiere ' . strlen($hash) . '. Ejecuta sql/expand_sys_user_password.sql.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            if (!preg_match('/^[\pL\pN._@-]{3,100}$/u', $username) || strtolower($username) === 'admin') {
                throw new InvalidArgumentException('Usuario de 3 a 100 caracteres. El nombre admin está reservado.');
            }
            if ($action === 'create') {
                if (!$isAdmin) {
                    http_response_code(403);
                    exit('Solo admin puede crear cuentas y establecer su contraseña.');
                }
                if (strlen($password) < 12 || strlen($password) > 72) throw new InvalidArgumentException('La contraseña debe tener entre 12 y 72 bytes.');
                $hash = password_hash($password, PASSWORD_BCRYPT);
                check_password_hash_fits($pdo, $hash);

                $check = $pdo->prepare('SELECT COUNT(*) FROM dbo.sys_user WHERE username = ?');
                $check->execute([$username]);
                if ((int)$check->fetchColumn() > 0) throw new InvalidArgumentException('Ese usuario ya existe.');

                $identity = (int)$pdo->query("SELECT COLUMNPROPERTY(OBJECT_ID('dbo.sys_user'), 'id', 'IsIdentity')")->fetchColumn() === 1;
                $fields = ['username', 'user_pwd'];
                $values = [$username, $hash];
                if (!$identity) {
                    $nextId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM dbo.sys_user WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
                    array_unshift($fields, 'id');
                    array_unshift($values, $nextId);
                }
                $columns = implode(', ', array_map(static fn($field) => '[' . $field . ']', $fields));
                $placeholders = implode(', ', array_fill(0, count($fields), '?'));
                $insert = $pdo->prepare("INSERT INTO dbo.sys_user ($columns) VALUES ($placeholders)");
                $insert->execute($values);
                audit_event('USER_CREATED', 'Se creó cuenta de plataforma: ' . $username, (int)$_SESSION['app_user_id']);
                header('Location: users.php?creado=1', true, 303);
                exit;
            }

            if ($action === 'edit') {
                $selectedId = (int)($_POST['user_id'] ?? 0);
                $lookup = $pdo->prepare("SELECT username FROM dbo.sys_user WHERE id = ? AND LOWER(username) <> N'admin'");
                $lookup->execute([$selectedId]);
                $oldUsername = $lookup->fetchColumn();
                if (!$oldUsername) throw new InvalidArgumentException('No se encontró la cuenta o la cuenta admin está protegida.');
                if ($password !== '' && !$isAdmin) {
                    http_response_code(403);
                    exit('Solo admin puede cambiar contraseñas.');
                }
                $check = $pdo->prepare('SELECT COUNT(*) FROM dbo.sys_user WHERE username = ? AND id <> ?');
                $check->execute([$username, $selectedId]);
                if ((int)$check->fetchColumn() > 0) throw new InvalidArgumentException('Ese nombre de usuario ya está ocupado.');

                if ($password !== '') {
                    if (strlen($password) < 12 || strlen($password) > 72) throw new InvalidArgumentException('La contraseña nueva debe tener entre 12 y 72 bytes.');
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    check_password_hash_fits($pdo, $hash);
                    $update = $pdo->prepare('UPDATE dbo.sys_user SET username = ?, user_pwd = ? WHERE id = ?');
                    $update->execute([$username, $hash, $selectedId]);
                    $_SESSION['user_password_flash'] = ['username' => $username, 'password' => $password];
                    $auditText = 'Se editó la cuenta ' . $oldUsername . ' como ' . $username . ' y se restableció su contraseña.';
                } else {
                    $update = $pdo->prepare('UPDATE dbo.sys_user SET username = ? WHERE id = ?');
                    $update->execute([$username, $selectedId]);
                    $auditText = 'Se editó la cuenta ' . $oldUsername . ' como ' . $username . '.';
                }
                audit_event('USER_UPDATED', $auditText, (int)$_SESSION['app_user_id']);
                header('Location: users.php?edit=' . $selectedId . '&guardado=1', true, 303);
                exit;
            }
            throw new InvalidArgumentException('Acción no válida.');
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
                ? $exception->getMessage() : 'No se pudo guardar el usuario. SQL dice: ' . $exception->getMessage();
        }
    }
}

$users = $pdo->query('SELECT id, username FROM dbo.sys_user ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
$selectedUser = null;
foreach ($users as $user) if ((int)$user['id'] === $selectedId && strtolower((string)$user['username']) !== 'admin') $selectedUser = $user;
if (isset($_GET['creado'])) $notice = 'Cuenta creada en sys_user.';
if (isset($_GET['guardado'])) $notice = 'Cambios guardados.';
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Usuarios · Checador</title><style>
body{font:15px system-ui;background:#f3f5f8;color:#17202a;margin:0}main{width:auto;max-width:none;box-sizing:border-box;margin:24px 0;padding:0 24px}.card{background:white;border:1px solid #dce2e9;border-radius:12px;padding:18px;margin:18px 0}form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}label{display:grid;gap:5px}input,button,a{font:inherit;padding:9px 12px;border:1px solid #bcc7d3;border-radius:7px}button,a{background:#173b67;color:white;text-decoration:none;cursor:pointer;border:0}.error{color:#a32323}.ok{color:#277341}.hint{color:#637083;font-size:13px}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:9px;border-bottom:1px solid #e5e9ee}.password-value{font-size:20px;font-weight:700;letter-spacing:1px;word-break:break-all}.password-field{display:flex;gap:6px;align-items:center}.password-field input{min-width:240px}.danger-note{color:#70450c;background:#fff8e8;padding:12px;border-radius:8px}
</style><link rel="stylesheet" href="assets/app.css"><link rel="icon" href="favicon.php?v=<?=time()?>" sizes="any"></head>
<body style="--brand:<?=h($branding['accent_color'])?>;--brand-dark:<?=h($branding['dark_color'])?>"><main>
<div class="brandbar"><img src="logo.php" alt="<?=h($branding['brand_name'])?>"><div class="brand-caption"><strong><?=h($branding['brand_name'])?></strong>Administración de cuentas</div></div><?php render_module_tabs('checador'); ?>
<h1>Cuentas de acceso</h1><p><a href="index.php">Volver al reporte</a></p>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>
<?php if ($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?>
<?php if ($passwordFlash && $isAdmin): ?><section class="card"><h2>Contraseña nueva guardada</h2><p>Cuenta: <?=h($passwordFlash['username'])?></p><p class="password-value"><?=h($passwordFlash['password'])?></p><p class="hint">Solo se muestra esta vez. Guárdala ahora.</p></section><?php endif; ?>
<?php if ($isAdmin): ?><section class="card"><h2>Crear cuenta</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="create"><label>Usuario<input name="username" minlength="3" maxlength="100" required></label><label>Contraseña (12–72 caracteres)<span class="password-field"><input id="new-password" type="password" name="password" minlength="12" maxlength="72" required><button type="button" onclick="togglePassword('new-password',this)">Ver</button></span></label><button>Crear en sys_user</button></form><p class="hint">La contraseña se guarda con bcrypt. Solo admin puede establecerla.</p></section><?php endif; ?>
<section class="card"><h2>Usuarios de sys_user</h2><table><thead><tr><th>ID</th><th>Usuario</th><th>Acción</th></tr></thead><tbody>
<?php foreach ($users as $user): ?><tr><td><?=h($user['id'])?></td><td><?=h($user['username'])?></td><td><?php if (strtolower((string)$user['username']) === 'admin'): ?>Cuenta protegida<?php else: ?><a href="users.php?edit=<?=h($user['id'])?>">Editar</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></section>
<?php if ($selectedUser): ?><section class="card"><h2>Editar cuenta: <?=h($selectedUser['username'])?></h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="user_id" value="<?=h($selectedUser['id'])?>"><label>Usuario<input name="username" value="<?=h($selectedUser['username'])?>" minlength="3" maxlength="100" required></label><?php if ($isAdmin): ?><label>Nueva contraseña (opcional; 12–72 caracteres)<span class="password-field"><input id="edit-password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password"><button type="button" onclick="togglePassword('edit-password',this)">Ver</button></span></label><p class="hint">La contraseña anterior no se puede recuperar porque está cifrada. Déjalo vacío para conservarla; al establecer una nueva, admin la verá una sola vez después de guardar.</p><?php endif; ?><button>Guardar cambios</button></form></section><?php endif; ?>
<p class="danger-note">Las contraseñas existentes no se pueden consultar. Solo admin puede crear cuentas y establecer contraseñas; al cambiarlas, la nueva se muestra una vez a admin.</p>
</main><script>function togglePassword(id,button){const input=document.getElementById(id);input.type=input.type==='password'?'text':'password';button.textContent=input.type==='password'?'Ver':'Ocultar';}</script></body></html>
