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
        $action = (string)($_POST['action'] ?? 'create');
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            if (!preg_match('/^[\pL\pN._@-]{3,100}$/u', $username) || strtolower($username) === 'admin' || strlen($password) < 12) {
                throw new InvalidArgumentException('Usuario de 3 a 100 caracteres y contraseña de al menos 12 caracteres.');
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            if (strlen($hash) > 64) throw new RuntimeException('El hash excede la capacidad de sys_user.user_pwd.');
            if ($action === 'create') {
                $check = db()->prepare('SELECT COUNT(*) FROM dbo.sys_user WHERE username = ?');
                $check->execute([$username]);
                if ((int)$check->fetchColumn() > 0) throw new InvalidArgumentException('Ese usuario ya existe.');
                $identity = (int)db()->query("SELECT COLUMNPROPERTY(OBJECT_ID('dbo.sys_user'), 'id', 'IsIdentity')")->fetchColumn() === 1;
                $fields = ['username', 'user_pwd'];
                $values = [$username, $hash];
                if (!$identity) {
                    $nextId = (int)db()->query('SELECT COALESCE(MAX(id), 0) + 1 FROM dbo.sys_user WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
                    array_unshift($fields, 'id');
                    array_unshift($values, $nextId);
                }
                $columns = implode(', ', array_map(static fn($field) => '[' . $field . ']', $fields));
                $params = implode(', ', array_fill(0, count($fields), '?'));
                $insert = db()->prepare("INSERT INTO dbo.sys_user ($columns) VALUES ($params)");
                $insert->execute($values);
                audit_event('USER_CREATED', 'Se creó cuenta de plataforma: ' . $username, (int)$_SESSION['app_user_id']);
                $notice = 'Cuenta creada en sys_user.';
            }
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
                ? $exception->getMessage() : 'No se pudo guardar la cuenta en sys_user.';
        }
    }
}
$users = db()->query('SELECT id, username FROM dbo.sys_user ORDER BY username')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Usuarios · Checador</title><style>body{font:15px system-ui;background:#f3f5f8;color:#17202a;margin:0}main{width:auto;max-width:none;box-sizing:border-box;margin:24px 0;padding:0 24px}.card{background:white;border:1px solid #dce2e9;border-radius:12px;padding:18px;margin:18px 0}form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}label{display:grid;gap:5px}input,button,a{font:inherit;padding:9px 12px;border:1px solid #bcc7d3;border-radius:7px}button,a{background:#173b67;color:white;text-decoration:none;cursor:pointer;border:0}.error{color:#a32323}.ok{color:#277341}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:9px;border-bottom:1px solid #e5e9ee}</style></head><body><main><h1>Cuentas de acceso</h1><p><a href="index.php">Volver al reporte</a></p><?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><?php if($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?><section class="card"><h2>Crear cuenta</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="create"><label>Usuario<input name="username" minlength="3" maxlength="100" required></label><label>Contraseña (12+ caracteres)<input type="password" name="password" minlength="12" required></label><button>Crear en sys_user</button></form></section><section class="card"><h2>Usuarios de sys_user</h2><table><thead><tr><th>ID</th><th>Usuario</th></tr></thead><tbody><?php foreach($users as $user): ?><tr><td><?=h($user['id'])?></td><td><?=h($user['username'])?></td></tr><?php endforeach; ?></tbody></table></section><p>Solo la cuenta <b>admin</b> puede crear cuentas. Las nuevas contraseñas quedan con bcrypt en sys_user.user_pwd.</p></main></body></html>
