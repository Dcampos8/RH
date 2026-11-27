<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branding.php';
require_permission('employee_view');
$canCreateEmployees = has_permission('employee_create');
$branding = branding_settings();

$pdo = db();
$error = '';
$created = $_SESSION['employee_created'] ?? null;
unset($_SESSION['employee_created']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canCreateEmployees) {
        http_response_code(403);
        exit('No tienes permiso para dar de alta empleados.');
    }
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página.';
    } else {
        $pinText = trim((string)($_POST['emp_pin'] ?? ''));
        $first = trim((string)($_POST['emp_firstname'] ?? ''));
        $last = trim((string)($_POST['emp_lastname'] ?? ''));
        $firstLength = function_exists('mb_strlen') ? mb_strlen($first, 'UTF-8') : strlen($first);
        $lastLength = function_exists('mb_strlen') ? mb_strlen($last, 'UTF-8') : strlen($last);
        if (!ctype_digit($pinText) || (int)$pinText < 1 || (int)$pinText > 2147483647 || $first === '' || $last === '' || $firstLength > 128 || $lastLength > 128) {
            $error = 'Escribe un PIN numérico válido y nombre y apellido de hasta 128 caracteres.';
        } else {
            try {
                $pdo->beginTransaction();
                $exists = $pdo->prepare('SELECT COUNT(*) FROM dbo.hr_employee WITH (UPDLOCK, HOLDLOCK) WHERE emp_pin = ?');
                $exists->execute([(int)$pinText]);
                if ((int)$exists->fetchColumn() > 0) {
                    throw new InvalidArgumentException('Ese PIN ya está registrado en CHECADOR.');
                }
                $identity = (int)$pdo->query("SELECT COLUMNPROPERTY(OBJECT_ID('dbo.hr_employee'), 'id', 'IsIdentity')")->fetchColumn() === 1;
                $fields = ['emp_pin', 'emp_code', 'emp_firstname', 'emp_lastname', 'emp_pwd', 'emp_active'];
                $values = [(int)$pinText, $pinText, $first, $last, sprintf('%04d', random_int(0, 9999)), 1];
                if (!$identity) {
                    $nextId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM dbo.hr_employee WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
                    array_unshift($fields, 'id');
                    array_unshift($values, $nextId);
                }
                $columns = implode(', ', array_map(static fn($field) => '[' . $field . ']', $fields));
                $params = implode(', ', array_fill(0, count($fields), '?'));
                $insert = $pdo->prepare("INSERT INTO dbo.hr_employee ($columns) VALUES ($params)");
                $insert->execute($values);
                audit_event('EMPLOYEE_CREATED', 'Se dio de alta empleado PIN ' . $pinText . ' (' . $first . ' ' . $last . ').', (int)$_SESSION['app_user_id']);
                $pdo->commit();
                $_SESSION['employee_created'] = ['pin' => $pinText, 'name' => $first . ' ' . $last, 'password' => $values[array_search('emp_pwd', $fields, true)]];
                header('Location: empleados.php', true, 303);
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $exception instanceof InvalidArgumentException
                    ? $exception->getMessage()
                    : 'No se pudo crear el empleado. Detalle SQL: ' . $exception->getMessage();
            }
        }
    }
}

try {
    $employees = $pdo->query('SELECT emp_pin, emp_firstname, emp_lastname, emp_active FROM dbo.hr_employee ORDER BY emp_firstname, emp_lastname')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $employees = [];
    $error = $error ?: 'No se pudo consultar hr_employee.';
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Empleados · Checador</title><style>body{font:15px system-ui;background:#f3f5f8;color:#17202a;margin:0}main{width:auto;max-width:none;box-sizing:border-box;margin:24px 0;padding:0 24px}.card{background:#fff;border:1px solid #dce2e9;border-radius:12px;padding:20px;margin:18px 0}form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}label{display:grid;gap:6px}input,button,a{font:inherit;padding:9px 12px;border:1px solid #bcc7d3;border-radius:7px}button,a{background:#173b67;color:#fff;text-decoration:none;border:0;cursor:pointer}.error{color:#a32323}.ok{color:#277341}.hint{font-size:13px;color:#637083}.credential{font-size:20px;font-weight:700;letter-spacing:2px}.scroll{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px;border-bottom:1px solid #e5e9ee}</style><link rel="stylesheet" href="assets/app.css"></head><body style="--brand:<?=h($branding['accent_color'])?>;--brand-dark:<?=h($branding['dark_color'])?>"><main><div class="brandbar"><img src="logo.php" alt="<?=h($branding['brand_name'])?>"><div class="brand-caption"><strong><?=h($branding['brand_name'])?></strong>Recursos Humanos · CHECADOR</div></div><h1>Empleados del checador</h1><p><a href="index.php">Volver al reporte</a><?php if (has_permission('schedule_view')): ?> · <a href="horarios.php">Asignar horarios</a><?php endif; ?></p>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>
<?php if ($created): ?><section class="card"><p class="ok">Empleado creado en CHECADOR. Para mandarlo al F19, pulsa “Sincronizar ahora” en el reporte.</p><p><?=h($created['name'])?> · PIN <?=h($created['pin'])?></p><p>Contraseña aleatoria de 4 dígitos para el reloj: <span class="credential"><?=h($created['password'])?></span></p><p class="hint">Guárdala ahora; se muestra una sola vez. La sincronización enviará esta contraseña junto con el usuario al F19.</p></section><?php endif; ?>
<?php if ($canCreateEmployees): ?><section class="card"><h2>Agregar empleado</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><label>PIN del checador<input type="number" name="emp_pin" min="1" max="2147483647" required></label><label>Nombre<input name="emp_firstname" maxlength="128" required></label><label>Apellido<input name="emp_lastname" maxlength="128" required></label><button>Crear empleado</button></form><p class="hint">El PIN identifica al empleado en el F19. La contraseña aleatoria se genera al guardar y se envía al dispositivo al sincronizar.</p></section><?php endif; ?>
<section class="card scroll"><h2>Personal de CHECADOR</h2><table><thead><tr><th>PIN</th><th>Nombre</th><th>Estado</th></tr></thead><tbody><?php foreach ($employees as $employee): ?><tr><td><?=h($employee['emp_pin'])?></td><td><?=h(trim($employee['emp_firstname'] . ' ' . $employee['emp_lastname']))?></td><td><?=((int)$employee['emp_active'] === 1) ? 'Activo' : 'Inactivo'?></td></tr><?php endforeach; ?></tbody></table></section></main></body></html>
