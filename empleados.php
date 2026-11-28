<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/module_tabs.php';
require_once __DIR__ . '/branding.php';
require_permission('employee_view');

$canManageEmployees = has_permission('employee_create');
$pdo = db();
$branding = branding_settings($pdo);
$error = '';
$notice = '';
$created = $_SESSION['employee_created'] ?? null;
unset($_SESSION['employee_created']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageEmployees) {
        http_response_code(403);
        exit('No tienes permiso para administrar empleados.');
    }
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'row_status') {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $status = filter_var($_POST['emp_active'] ?? null, FILTER_VALIDATE_INT);
                if ($employeeId < 1 || !in_array($status, [0, 1], true)) throw new InvalidArgumentException('Selecciona un empleado y un estatus válido.');
                $lookup = $pdo->prepare('SELECT emp_pin, emp_active FROM dbo.hr_employee WHERE id = ?');
                $lookup->execute([$employeeId]);
                $employee = $lookup->fetch(PDO::FETCH_ASSOC);
                if (!$employee) throw new InvalidArgumentException('No se encontró el empleado.');
                $update = $pdo->prepare('UPDATE dbo.hr_employee SET emp_active = ? WHERE id = ?');
                $update->execute([$status, $employeeId]);
                audit_event('EMPLOYEE_STATUS_CHANGED', 'Se cambió el estatus del empleado nómina ' . $employee['emp_pin'] . ' a ' . ($status === 1 ? 'activo' : 'inactivo') . '.', (int)$_SESSION['app_user_id']);
                header('Location: empleados.php?estado=actualizado', true, 303);
                exit;
            }

            if ($action === 'bulk_status') {
                $ids = $_POST['employee_ids'] ?? [];
                $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0))) : [];
                $status = filter_var($_POST['bulk_status'] ?? null, FILTER_VALIDATE_INT);
                if (!$ids || count($ids) > 500 || !in_array($status, [0, 1], true)) throw new InvalidArgumentException('Selecciona de 1 a 500 empleados y el estatus que quieres asignar.');
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $count = $pdo->prepare("SELECT COUNT(*) FROM dbo.hr_employee WHERE id IN ($marks)");
                $count->execute($ids);
                $updatedCount = (int)$count->fetchColumn();
                if ($updatedCount < 1) throw new InvalidArgumentException('No se encontraron empleados en la selección.');
                $stmt = $pdo->prepare("UPDATE dbo.hr_employee SET emp_active = ? WHERE id IN ($marks)");
                $stmt->execute([$status, ...$ids]);
                audit_event('EMPLOYEE_BULK_STATUS_CHANGED', 'Se cambió el estatus a ' . ($status === 1 ? 'activo' : 'inactivo') . ' en ' . $updatedCount . ' empleados.', (int)$_SESSION['app_user_id']);
                header('Location: empleados.php?actualizados=' . $updatedCount, true, 303);
                exit;
            }

            if ($action === 'create') {
                $pinText = trim((string)($_POST['emp_pin'] ?? ''));
                $first = trim((string)($_POST['emp_firstname'] ?? ''));
                $last = trim((string)($_POST['emp_lastname'] ?? ''));
                $firstLength = function_exists('mb_strlen') ? mb_strlen($first, 'UTF-8') : strlen($first);
                $lastLength = function_exists('mb_strlen') ? mb_strlen($last, 'UTF-8') : strlen($last);
                if (!ctype_digit($pinText) || (int)$pinText < 1 || (int)$pinText > 2147483647 || $first === '' || $last === '' || $firstLength > 128 || $lastLength > 128) {
                    throw new InvalidArgumentException('Escribe un número de nómina válido y nombre y apellido de hasta 128 caracteres.');
                }

                $pdo->beginTransaction();
                $exists = $pdo->prepare('SELECT COUNT(*) FROM dbo.hr_employee WITH (UPDLOCK, HOLDLOCK) WHERE emp_pin = ?');
                $exists->execute([(int)$pinText]);
                if ((int)$exists->fetchColumn() > 0) throw new InvalidArgumentException('Ese número de nómina ya está registrado en CHECADOR.');
                $identity = (int)$pdo->query("SELECT COLUMNPROPERTY(OBJECT_ID('dbo.hr_employee'), 'id', 'IsIdentity')")->fetchColumn() === 1;
                $employeeCode = sprintf('%04d', random_int(0, 9999));
                $fields = ['emp_pin', 'emp_code', 'emp_firstname', 'emp_lastname', 'emp_active'];
                $values = [(int)$pinText, $employeeCode, $first, $last, 1];
                if (!$identity) {
                    $nextId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM dbo.hr_employee WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
                    array_unshift($fields, 'id');
                    array_unshift($values, $nextId);
                }
                $columns = implode(', ', array_map(static fn($field) => '[' . $field . ']', $fields));
                $placeholders = implode(', ', array_fill(0, count($fields), '?'));
                $insert = $pdo->prepare("INSERT INTO dbo.hr_employee ($columns) VALUES ($placeholders)");
                $insert->execute($values);
                audit_event('EMPLOYEE_CREATED', 'Se dio de alta empleado con número de nómina ' . $pinText . ' (' . $first . ' ' . $last . ').', (int)$_SESSION['app_user_id']);
                $pdo->commit();
                $_SESSION['employee_created'] = ['pin' => $pinText, 'code' => $employeeCode, 'name' => $first . ' ' . $last];
                header('Location: empleados.php', true, 303);
                exit;
            }

            throw new InvalidArgumentException('Acción no válida.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'No se pudo guardar el cambio. Detalle SQL: ' . $exception->getMessage();
        }
    }
}

if (isset($_GET['estado'])) $notice = 'Estatus del empleado actualizado.';
if (isset($_GET['actualizados'])) $notice = (int)$_GET['actualizados'] . ' empleados actualizados.';
try {
    $employees = $pdo->query('SELECT id, emp_pin, emp_code, emp_firstname, emp_lastname, emp_active FROM dbo.hr_employee ORDER BY emp_firstname, emp_lastname')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $employees = [];
    $error = $error ?: 'No se pudo consultar hr_employee.';
}
$totalEmployees = count($employees);
$activeEmployees = count(array_filter($employees, static fn(array $employee): bool => (int)$employee['emp_active'] === 1));
$inactiveEmployees = $totalEmployees - $activeEmployees;
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Empleados · Checador</title>
<style>body{font:15px system-ui;background:#f3f5f8;color:#17202a;margin:0}main{width:auto;max-width:none;box-sizing:border-box;margin:24px 0;padding:0 24px}.card{background:#fff;border:1px solid #dce2e9;border-radius:12px;padding:20px;margin:18px 0}form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}label{display:grid;gap:6px}input,select,button,a{font:inherit;padding:9px 12px;border:1px solid #bcc7d3;border-radius:7px}button,a{background:#173b67;color:#fff;text-decoration:none;border:0;cursor:pointer}.error{color:#a32323}.ok{color:#277341}.hint{font-size:13px;color:#637083}.credential{font-size:20px;font-weight:700;letter-spacing:2px}.scroll{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px;border-bottom:1px solid #e5e9ee;vertical-align:middle}th{white-space:nowrap;background:#edf2f7}.toolbar{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.toolbar label{flex:1;min-width:220px}.toolbar input{width:100%;box-sizing:border-box}.toolbar select{min-width:170px}.stats{display:flex;gap:22px;flex-wrap:wrap}.stats b{font-size:19px}.row-status{display:flex;gap:7px;align-items:center}.row-status select{min-width:120px}.employee-row.inactive{background:#fafafa;color:#697586}.select-cell{width:36px}.select-cell input{min-height:auto}.bulk-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.bulk-form label{min-width:200px}.badge{display:inline-block;padding:4px 9px;border-radius:20px;font-size:12px;font-weight:700}.badge-active{background:#e5f5ec;color:#197444}.badge-inactive{background:#f0f1f3;color:#616b78}.empty-state{padding:18px;text-align:center;color:#637083}</style>
<link rel="stylesheet" href="assets/app.css"><link rel="icon" href="favicon.php?v=<?=time()?>" sizes="any"></head>
<body style="--brand:<?=h($branding['accent_color'])?>;--brand-dark:<?=h($branding['dark_color'])?>"><main>
<div class="brandbar"><img src="logo.php" alt="<?=h($branding['brand_name'])?>"><div class="brand-caption"><strong><?=h($branding['brand_name'])?></strong>Recursos Humanos · CHECADOR</div></div><?php render_module_tabs('checador'); ?>
<h1>Empleados del checador</h1><p><a href="index.php">Volver al reporte</a><?php if (has_permission('schedule_view')): ?> · <a href="horarios.php">Asignar horarios</a><?php endif; ?></p>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><?php if ($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?>
<?php if ($created): ?><section class="card"><p class="ok">Empleado creado en CHECADOR. Para mandarlo al F19, pulsa “Sincronizar ahora” en el reporte.</p><p><?=h($created['name'])?> · Número de nómina / emp_pin: <?=h($created['pin'])?></p><p>Código aleatorio / emp_code: <span class="credential"><?=h($created['code'])?></span></p><p class="hint">emp_pin identifica al usuario en el F19; emp_code es el código interno aleatorio de 4 dígitos.</p></section><?php endif; ?>
<?php if ($canManageEmployees): ?><section class="card"><h2>Agregar empleado</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="create"><label>Número de nómina (emp_pin)<input type="number" name="emp_pin" min="1" max="2147483647" required></label><label>Nombre<input name="emp_firstname" maxlength="128" required></label><label>Apellido<input name="emp_lastname" maxlength="128" required></label><button>Crear empleado</button></form><p class="hint">emp_pin se usa como PIN/ID del F19; emp_code se genera aleatoriamente con 4 dígitos. emp_pwd no se usa en esta alta.</p></section><?php endif; ?>
<section class="card stats"><div><b id="total-count"><?=$totalEmployees?></b><br>personal</div><div><b id="active-count"><?=$activeEmployees?></b><br>activos</div><div><b id="inactive-count"><?=$inactiveEmployees?></b><br>inactivos</div><div><b id="shown-count"><?=$totalEmployees?></b><br>mostrados</div></section>
<section class="card"><div class="toolbar"><label>Buscar por nómina, código o nombre<input id="employee-search" type="search" placeholder="Escribe para filtrar…" autocomplete="off"></label><label>Mostrar<select id="status-filter"><option value="all">Todos los estatus</option><option value="1">Solo activos</option><option value="0">Solo inactivos</option></select></label><button type="button" id="clear-filters">Limpiar filtros</button></div></section>
<?php if ($canManageEmployees): ?><section class="card"><form id="bulk-form" class="bulk-form" method="post" onsubmit="return confirmBulkStatus(this)"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="bulk_status"><label>Cambiar estatus de seleccionados<select name="bulk_status" required><option value="">Elige estatus</option><option value="1">Activo</option><option value="0">Inactivo</option></select></label><button>Aplicar a seleccionados</button><button type="button" id="select-visible">Seleccionar visibles</button><button type="button" id="clear-selection">Quitar selección</button><span class="hint" id="selection-count">0 seleccionados</span></form></section><?php endif; ?>
<section class="card scroll"><h2>Personal de CHECADOR</h2><table><thead><tr><?php if ($canManageEmployees): ?><th class="select-cell">Sel.</th><?php endif; ?><th>Nómina / emp_pin</th><th>Código / emp_code</th><th>Nombre</th><th>Estado</th><?php if ($canManageEmployees): ?><th>Acción</th><?php endif; ?></tr></thead><tbody id="employee-list">
<?php foreach ($employees as $employee): $active = (int)$employee['emp_active'] === 1; $employeeName = trim($employee['emp_firstname'] . ' ' . $employee['emp_lastname']); ?>
<tr class="employee-row<?=$active ? '' : ' inactive'?>" data-status="<?=$active ? '1' : '0'?>" data-search="<?=h($employee['emp_pin'] . ' ' . $employee['emp_code'] . ' ' . $employeeName)?>">
<?php if ($canManageEmployees): ?><td class="select-cell"><input class="employee-select" type="checkbox" name="employee_ids[]" value="<?=h($employee['id'])?>" form="bulk-form" aria-label="Seleccionar <?=h($employeeName)?>"></td><?php endif; ?>
<td><?=h($employee['emp_pin'])?></td><td><?=h($employee['emp_code'])?></td><td><?=h($employeeName)?></td><td><span class="badge <?=$active ? 'badge-active' : 'badge-inactive'?>"><?=$active ? 'Activo' : 'Inactivo'?></span></td>
<?php if ($canManageEmployees): ?><td><form class="row-status" method="post" onsubmit="return confirm('¿Guardar el nuevo estatus de este empleado?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="row_status"><input type="hidden" name="employee_id" value="<?=h($employee['id'])?>"><select name="emp_active" aria-label="Estatus de <?=h($employeeName)?>"><option value="1" <?=$active ? 'selected' : ''?>>Activo</option><option value="0" <?=!$active ? 'selected' : ''?>>Inactivo</option></select><button>Guardar</button></form></td><?php endif; ?></tr>
<?php endforeach; ?>
<tr id="no-results" hidden><td colspan="<?= $canManageEmployees ? 6 : 4 ?>" class="empty-state">No hay empleados que coincidan con la búsqueda.</td></tr>
</tbody></table></section>
</main><script>
const rows=[...document.querySelectorAll('.employee-row')], searchBox=document.getElementById('employee-search'), statusFilter=document.getElementById('status-filter');
const normalizeText=value=>value.toLocaleLowerCase('es-MX').normalize('NFD').replace(/[\u0300-\u036f]/g,'');
function applyEmployeeFilters(){const query=normalizeText(searchBox.value.trim()),status=statusFilter.value;let shown=0;for(const row of rows){const visible=normalizeText(row.dataset.search).includes(query)&&(status==='all'||row.dataset.status===status);row.hidden=!visible;if(!visible){const checkbox=row.querySelector('.employee-select');if(checkbox)checkbox.checked=false;}if(visible)shown++;}document.getElementById('shown-count').textContent=shown;document.getElementById('no-results').hidden=shown!==0;updateSelectionCount();}
function updateSelectionCount(){const checks=[...document.querySelectorAll('.employee-select')];const selected=checks.filter(check=>check.checked).length;const counter=document.getElementById('selection-count');if(counter)counter.textContent=selected+' seleccionados';}
function confirmBulkStatus(form){const selected=document.querySelectorAll('input.employee-select[form="bulk-form"]:checked').length;if(!selected){alert('Selecciona al menos un empleado.');return false;}const status=form.querySelector('[name="bulk_status"]').value;if(status!=='0'&&status!=='1'){alert('Elige el estatus que quieres aplicar.');return false;}return confirm('¿Cambiar a '+(status==='1'?'ACTIVO':'INACTIVO')+' '+selected+' empleado(s)?');}
searchBox.addEventListener('input',applyEmployeeFilters);statusFilter.addEventListener('change',applyEmployeeFilters);document.getElementById('clear-filters').addEventListener('click',()=>{searchBox.value='';statusFilter.value='all';applyEmployeeFilters();searchBox.focus();});
document.querySelectorAll('.employee-select').forEach(check=>check.addEventListener('change',updateSelectionCount));
const selectVisible=document.getElementById('select-visible');if(selectVisible)selectVisible.addEventListener('click',()=>{rows.filter(row=>!row.hidden).forEach(row=>{const check=row.querySelector('.employee-select');if(check)check.checked=true;});updateSelectionCount();});
const clearSelection=document.getElementById('clear-selection');if(clearSelection)clearSelection.addEventListener('click',()=>{document.querySelectorAll('.employee-select').forEach(check=>check.checked=false);updateSelectionCount();});
applyEmployeeFilters();
</script></body></html>
