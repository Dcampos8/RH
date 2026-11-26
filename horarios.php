<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_admin();

$pdo = db();
$error = '';
$notice = '';
$days = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
$shortDays = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

function schedule_days_label(array $numbers, array $shortDays): string
{
    sort($numbers);
    if ($numbers === [1, 2, 3, 4, 5]) return 'Lunes a viernes';
    $ranges = [];
    for ($i = 0; $i < count($numbers);) {
        $start = $end = $numbers[$i];
        while (isset($numbers[$i + 1]) && $numbers[$i + 1] === $end + 1) $end = $numbers[++$i];
        $ranges[] = $start === $end ? $shortDays[$start] : $shortDays[$start] . '–' . $shortDays[$end];
        $i++;
    }
    return implode(', ', $ranges);
}

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
                $shiftByGroup = [
                    'Lunes a viernes' => ['shift' => (int)($_POST['shift_weekdays'] ?? 0), 'days' => [1, 2, 3, 4, 5]],
                    'Sábado' => ['shift' => (int)($_POST['shift_saturday'] ?? 0), 'days' => [6]],
                    'Domingo' => ['shift' => (int)($_POST['shift_sunday'] ?? 0), 'days' => [7]],
                ];
                $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['valid_from'] ?? ''));
                $toText = trim((string)($_POST['valid_to'] ?? ''));
                $to = $toText === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $toText);
                $selectedGroups = array_filter($shiftByGroup, static fn($group) => $group['shift'] > 0);
                if ($employeeId < 1 || !$from || ($toText !== '' && !$to) || ($to && $to < $from) || !$selectedGroups) {
                    throw new InvalidArgumentException('Selecciona empleado, vigencia y al menos un turno: lunes a viernes, sábado o domingo.');
                }
                $pdo->beginTransaction();
                $overlap = $pdo->prepare('SELECT COUNT(*) FROM dbo.rh_employee_schedule WHERE employee_id = ? AND weekday = ? AND (? IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)');
                $add = $pdo->prepare('INSERT INTO dbo.rh_employee_schedule (employee_id, shift_id, weekday, valid_from, valid_to) VALUES (?, ?, ?, ?, ?)');
                $fromValue = $from->format('Y-m-d');
                $toValue = $to?->format('Y-m-d');
                foreach ($selectedGroups as $group) {
                    foreach ($group['days'] as $weekday) {
                        $overlap->execute([$employeeId, $weekday, $toValue, $toValue, $fromValue]);
                        if ((int)$overlap->fetchColumn() > 0) {
                            throw new InvalidArgumentException('Ya existe un horario en esos días y fechas. Ajusta o elimina la asignación anterior.');
                        }
                        $add->execute([$employeeId, $group['shift'], $weekday, $fromValue, $toValue]);
                    }
                }
                $pdo->commit();
                audit_event('SCHEDULE_ASSIGNED', 'Se asignó horario al empleado ID ' . $employeeId . ' para ' . implode(', ', array_keys($selectedGroups)) . '.', (int)$_SESSION['app_user_id']);
                $notice = 'Horario asignado.';
            } elseif ($action === 'borrar') {
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['assignment_ids'] ?? [])), static fn($id) => $id > 0)));
                if (!$ids) throw new InvalidArgumentException('No se seleccionaron asignaciones para eliminar.');
                $delete = $pdo->prepare('DELETE FROM dbo.rh_employee_schedule WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                $delete->execute($ids);
                audit_event('SCHEDULE_REMOVED', 'Se quitaron asignaciones de horario: ' . implode(',', $ids) . '.', (int)$_SESSION['app_user_id']);
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
    $assignmentRows = $pdo->query('SELECT a.id, a.employee_id, e.emp_firstname, e.emp_lastname, e.emp_pin, a.shift_id, s.shift_name, CONVERT(char(5), s.shift_start, 108) AS hora_inicio, CONVERT(char(5), s.shift_end, 108) AS hora_fin, a.weekday, a.valid_from, a.valid_to FROM dbo.rh_employee_schedule a JOIN dbo.hr_employee e ON e.id=a.employee_id JOIN dbo.att_shift s ON s.id=a.shift_id ORDER BY e.emp_firstname, e.emp_lastname, a.valid_from, a.weekday')->fetchAll(PDO::FETCH_ASSOC);
    $assignmentGroups = [];
    foreach ($assignmentRows as $assignment) {
        $key = $assignment['employee_id'] . '|' . $assignment['valid_from'] . '|' . ($assignment['valid_to'] ?? '');
        if (!isset($assignmentGroups[$key])) {
            $assignmentGroups[$key] = ['employee_id' => $assignment['employee_id'], 'emp_pin' => $assignment['emp_pin'], 'name' => trim($assignment['emp_firstname'] . ' ' . $assignment['emp_lastname']), 'valid_from' => $assignment['valid_from'], 'valid_to' => $assignment['valid_to'], 'ids' => [], 'shifts' => []];
        }
        $assignmentGroups[$key]['ids'][] = (int)$assignment['id'];
        $shiftKey = (string)$assignment['shift_id'];
        if (!isset($assignmentGroups[$key]['shifts'][$shiftKey])) {
            $assignmentGroups[$key]['shifts'][$shiftKey] = ['name' => $assignment['shift_name'], 'start' => $assignment['hora_inicio'], 'end' => $assignment['hora_fin'], 'days' => []];
        }
        $assignmentGroups[$key]['shifts'][$shiftKey]['days'][] = (int)$assignment['weekday'];
    }
    foreach ($assignmentGroups as &$group) {
        $group['schedule_lines'] = [];
        foreach ($group['shifts'] as $shift) {
            $group['schedule_lines'][] = schedule_days_label($shift['days'], $shortDays) . ': ' . $shift['name'] . ' (' . $shift['start'] . '–' . $shift['end'] . ')';
        }
    }
    unset($group);
    $assignments = array_values($assignmentGroups);
} catch (Throwable $exception) {
    $employees = $shifts = $assignments = [];
    $error = $error ?: 'Ejecuta sql/schedule_assignments.sql en CHECADOR para habilitar la asignación de horarios.';
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Horarios · Checador</title><style>body{font:15px system-ui;background:#f3f5f8;color:#17202a;margin:0}main{width:auto;max-width:none;box-sizing:border-box;margin:24px 0;padding:0 24px}.card{background:#fff;border:1px solid #dce2e9;border-radius:12px;padding:20px;margin:18px 0}form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}label{display:grid;gap:6px}input,select,button,a{font:inherit;padding:9px 12px;border:1px solid #bcc7d3;border-radius:7px}button,a{background:#173b67;color:#fff;text-decoration:none;border:0;cursor:pointer}.days{display:flex;gap:10px;flex-wrap:wrap}.error{color:#a32323}.ok{color:#277341}.hint{font-size:13px;color:#637083}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px;border-bottom:1px solid #e5e9ee}.scroll{overflow:auto}</style><link rel="stylesheet" href="assets/app.css"></head><body><main><div class="brandbar"><img src="assets/logo.svg" alt="Transportes Aldez"><div class="brand-caption"><strong>Control de asistencia</strong>Recursos Humanos · CHECADOR</div></div><h1>Turnos y horarios</h1><p><a href="index.php">Volver al reporte</a></p><?php if($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><?php if($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?><section class="card"><h2>Crear turno</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="turno"><label>Nombre<input name="shift_name" maxlength="128" placeholder="Ej. Turno matutino" required></label><label>Entrada<input name="shift_start" type="time" required></label><label>Salida<input name="shift_end" type="time" required></label><button>Guardar turno</button></form></section><section class="card"><h2>Asignar horario al empleado</h2><p class="hint">Registra un solo bloque por persona y vigencia; puedes poner un turno para lunes a viernes y otros para sábado o domingo.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="asignar"><label>Empleado<select name="employee_id" required><option value="">Selecciona</option><?php foreach($employees as $e): ?><option value="<?=h($e['id'])?>"><?=h(trim($e['emp_firstname'].' '.$e['emp_lastname']))?> · PIN <?=h($e['emp_pin'])?></option><?php endforeach; ?></select></label><label>Turno lunes a viernes<select name="shift_weekdays"><option value="">Sin asignar</option><?php foreach($shifts as $s): ?><option value="<?=h($s['id'])?>"><?=h($s['shift_name'])?> (<?=h($s['hora_inicio'])?>–<?=h($s['hora_fin'])?>)</option><?php endforeach; ?></select></label><label>Turno sábado<select name="shift_saturday"><option value="">Sin asignar</option><?php foreach($shifts as $s): ?><option value="<?=h($s['id'])?>"><?=h($s['shift_name'])?> (<?=h($s['hora_inicio'])?>–<?=h($s['hora_fin'])?>)</option><?php endforeach; ?></select></label><label>Turno domingo<select name="shift_sunday"><option value="">Sin asignar</option><?php foreach($shifts as $s): ?><option value="<?=h($s['id'])?>"><?=h($s['shift_name'])?> (<?=h($s['hora_inicio'])?>–<?=h($s['hora_fin'])?>)</option><?php endforeach; ?></select></label><label>Válido desde<input type="date" name="valid_from" required></label><label>Válido hasta (vacío = sin fecha final)<input type="date" name="valid_to"></label><button>Asignar horarios</button></form><p class="hint">Cada persona puede tener un turno distinto entre semana, sábado y domingo. Para dejar un día libre, selecciona “Sin asignar”.</p></section><section class="card scroll"><h2>Asignaciones por empleado</h2><table><thead><tr><th>Empleado</th><th>PIN</th><th>Horario por día</th><th>Vigencia</th><th></th></tr></thead><tbody><?php foreach($assignments as $a): ?><tr><td><?=h($a['name'])?></td><td><?=h($a['emp_pin'])?></td><td class="schedule-lines"><?php foreach($a['schedule_lines'] as $line): ?><div class="schedule-line"><?=h($line)?></div><?php endforeach; ?></td><td><?=h($a['valid_from'])?> a <?=h($a['valid_to']?:'indefinido')?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="borrar"><?php foreach($a['ids'] as $assignmentId): ?><input type="hidden" name="assignment_ids[]" value="<?=h($assignmentId)?>"><?php endforeach; ?><button>Quitar</button></form></td></tr><?php endforeach; ?></tbody></table></section></main></body></html>
