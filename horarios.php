<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_admin();

$pdo = db();
$error = '';
$notice = '';
$days = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'turno') {
                $name = trim((string)($_POST['shift_name'] ?? ''));
                $start = (string)($_POST['shift_start'] ?? '');
                $end = (string)($_POST['shift_end'] ?? '');
                if ($name === '' || strlen($name) > 128 || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)) {
                    throw new InvalidArgumentException('Escribe el nombre del turno y horas válidas.');
                }
                $insert = $pdo->prepare('INSERT INTO dbo.att_shift (shift_name, shift_start, shift_end) VALUES (?, CONVERT(datetime, ?, 108), CONVERT(datetime, ?, 108))');
                $insert->execute([$name, $start . ':00', $end . ':00']);
                audit_event('SHIFT_CREATED', 'Se creó el turno ' . $name . ' (' . $start . '-' . $end . ').', (int)$_SESSION['app_user_id']);
                $notice = 'Turno guardado en att_shift.';
            } elseif ($action === 'asignar') {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $shiftId = (int)($_POST['shift_id'] ?? 0);
                $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['valid_from'] ?? ''));
                $toText = trim((string)($_POST['valid_to'] ?? ''));
                $to = $toText === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $toText);
                $weekdays = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['weekday'] ?? [])), static fn($day) => $day >= 1 && $day <= 7)));
                if ($employeeId < 1 || $shiftId < 1 || !$from || ($toText !== '' && !$to) || ($to && $to < $from) || !$weekdays) {
                    throw new InvalidArgumentException('Selecciona empleado, turno, vigencia y al menos un día válido.');
                }
                $pdo->beginTransaction();
                $overlap = $pdo->prepare('SELECT COUNT(*) FROM dbo.rh_employee_schedule WHERE employee_id = ? AND weekday = ? AND (? IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)');
                $add = $pdo->prepare('INSERT INTO dbo.rh_employee_schedule (employee_id, shift_id, weekday, valid_from, valid_to) VALUES (?, ?, ?, ?, ?)');
                foreach ($weekdays as $weekday) {
                    $toValue = $to?->format('Y-m-d');
                    $fromValue = $from->format('Y-m-d');
                    $overlap->execute([$employeeId, $weekday, $toValue, $toValue, $fromValue]);
                    if ((int)$overlap->fetchColumn() > 0) {
                        throw new InvalidArgumentException('Ya existe un turno asignado en uno de esos días y fechas. Quita o ajusta la asignación anterior primero.');
                    }
                    $add->execute([$employeeId, $shiftId, $weekday, $fromValue, $toValue]);
                }
                $pdo->commit();
                audit_event('SCHEDULE_ASSIGNED', 'Asignación de horario a empleado ID ' . $employeeId . ', turno ID ' . $shiftId . '.', (int)$_SESSION['app_user_id']);
                $notice = 'Horario asignado.';
            } elseif ($action === 'borrar') {
                $id = (int)($_POST['assignment_id'] ?? 0);
                $delete = $pdo->prepare('DELETE FROM dbo.rh_employee_schedule WHERE id = ?');
                $delete->execute([$id]);
                audit_event('SCHEDULE_REMOVED', 'Se quitó asignación ID ' . $id . '.', (int)$_SESSION['app_user_id']);
                $notice = 'Asignación eliminada.';
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'No se pudo guardar. Verifica que exista la tabla de asignaciones y los permisos SQL.';
        }
    }
}

try {
    $employees = $pdo->query("SELECT id, emp_pin, emp_firstname, emp_lastname FROM dbo.hr_employee WHERE emp_active = 1 ORDER BY emp_firstname, emp_lastname")->fetchAll(PDO::FETCH_ASSOC);
    $shifts = $pdo->query('SELECT id, shift_name, CONVERT(char(5), shift_start, 108) AS hora_inicio, CONVERT(char(5), shift_end, 108) AS hora_fin FROM dbo.att_shift ORDER BY shift_name')->fetchAll(PDO::FETCH_ASSOC);
    $assignments = $pdo->query('SELECT a.id, e.emp_firstname, e.emp_lastname, e.emp_pin, s.shift_name, CONVERT(char(5), s.shift_start, 108) AS hora_inicio, CONVERT(char(5), s.shift_end, 108) AS hora_fin, a.weekday, a.valid_from, a.valid_to FROM dbo.rh_employee_schedule a JOIN dbo.hr_employee e ON e.id=a.employee_id JOIN dbo.att_shift s ON s.id=a.shift_id ORDER BY e.emp_firstname, e.emp_lastname, a.weekday, a.valid_from')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $employees = $shifts = $assignments = [];
    $error = $error ?: 'Ejecuta sql/schedule_assignments.sql en CHECADOR para habilitar la asignación de horarios.';
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Horarios · Checador</title><style>body{font:15px system-ui;background:#f3f5f8;color:#17202a;margin:0}main{max-width:1100px;margin:32px auto;padding:0 18px}.card{background:#fff;border:1px solid #dce2e9;border-radius:12px;padding:20px;margin:18px 0}form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}label{display:grid;gap:6px}input,select,button,a{font:inherit;padding:9px 12px;border:1px solid #bcc7d3;border-radius:7px}button,a{background:#173b67;color:#fff;text-decoration:none;border:0;cursor:pointer}.days{display:flex;gap:10px;flex-wrap:wrap}.error{color:#a32323}.ok{color:#277341}.hint{font-size:13px;color:#637083}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px;border-bottom:1px solid #e5e9ee}.scroll{overflow:auto}</style></head><body><main><h1>Turnos y horarios</h1><p><a href="index.php">Volver al reporte</a></p><?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><?php if($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?><section class="card"><h2>Crear turno</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="turno"><label>Nombre<input name="shift_name" maxlength="128" placeholder="Ej. Turno matutino" required></label><label>Entrada<input name="shift_start" type="time" required></label><label>Salida<input name="shift_end" type="time" required></label><button>Guardar turno</button></form></section><section class="card"><h2>Asignar horario a empleado</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="asignar"><label>Empleado<select name="employee_id" required><option value="">Selecciona</option><?php foreach($employees as $e): ?><option value="<?=h($e['id'])?>"><?=h(trim($e['emp_firstname'].' '.$e['emp_lastname']))?> · PIN <?=h($e['emp_pin'])?></option><?php endforeach; ?></select></label><label>Turno<select name="shift_id" required><option value="">Selecciona</option><?php foreach($shifts as $s): ?><option value="<?=h($s['id'])?>"><?=h($s['shift_name'])?> (<?=h($s['hora_inicio'])?>–<?=h($s['hora_fin'])?>)</option><?php endforeach; ?></select></label><label>Válido desde<input type="date" name="valid_from" required></label><label>Válido hasta (vacío = sin fecha final)<input type="date" name="valid_to"></label><div><label>Días de trabajo</label><div class="days"><?php foreach($days as $n=>$day): ?><label><span><input type="checkbox" name="weekday[]" value="<?=$n?>" <?=$n<=5?'checked':''?>> <?=h($day)?></span></label><?php endforeach; ?></div></div><button>Asignar</button></form><p class="hint">Una persona no puede tener dos turnos asignados el mismo día durante fechas que se traslapen.</p></section><section class="card scroll"><h2>Asignaciones</h2><table><thead><tr><th>Empleado</th><th>PIN</th><th>Día</th><th>Turno</th><th>Vigencia</th><th></th></tr></thead><tbody><?php foreach($assignments as $a): ?><tr><td><?=h(trim($a['emp_firstname'].' '.$a['emp_lastname']))?></td><td><?=h($a['emp_pin'])?></td><td><?=h($days[(int)$a['weekday']]??'')?></td><td><?=h($a['shift_name'])?> (<?=h($a['hora_inicio'])?>–<?=h($a['hora_fin'])?>)</td><td><?=h($a['valid_from'])?> a <?=h($a['valid_to']?:'indefinido')?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="borrar"><input type="hidden" name="assignment_id" value="<?=h($a['id'])?>"><button>Quitar</button></form></td></tr><?php endforeach; ?></tbody></table></section></main></body></html>
