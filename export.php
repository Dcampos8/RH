<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/attendance_helpers.php';
require_permission('report_view');
$from = $_GET['desde'] ?? '';
$to = $_GET['hasta'] ?? '';
$start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
$end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
if (!$start || !$end || $end < $start || $start->diff($end)->days > 366) {
    http_response_code(400);
    exit('Rango de fechas no válido.');
}
try {
    $pdo = db();
    $sql = <<<'SQL'
SELECT p.emp_id, e.emp_firstname, e.emp_lastname, COALESCE(d.dept_name, N'') AS departamento,
       CONVERT(date, p.punch_time) AS fecha, p.punch_time, p.punch_type, p.workstate,
       p.terminal_id, t.terminal_name, s.shift_name, CONVERT(time(0), s.shift_start) AS hora_inicio,
       CONVERT(time(0), s.shift_end) AS hora_fin
FROM dbo.att_punches AS p
JOIN dbo.hr_employee AS e ON e.id = p.emp_id
LEFT JOIN dbo.hr_department AS d ON d.id = e.emp_dept
LEFT JOIN dbo.att_terminal AS t ON t.id = p.terminal_id
OUTER APPLY (SELECT TOP (1) detail.shift_ID FROM dbo.att_day_details AS detail
             WHERE detail.employee_id = p.emp_id AND CONVERT(date, detail.att_date) = CONVERT(date, p.punch_time)
             ORDER BY detail.sortindex) AS dd
OUTER APPLY (SELECT TOP (1) assignment.shift_id FROM dbo.rh_employee_schedule AS assignment
             WHERE assignment.employee_id = p.emp_id
               AND assignment.valid_from <= CONVERT(date, p.punch_time)
               AND (assignment.valid_to IS NULL OR assignment.valid_to >= CONVERT(date, p.punch_time))
               AND assignment.weekday = (DATEDIFF(day, CONVERT(date, '19000101', 112), CONVERT(date, p.punch_time)) % 7) + 1
             ORDER BY assignment.valid_from DESC, assignment.id DESC) AS es
LEFT JOIN dbo.att_shift AS s ON s.id = COALESCE(dd.shift_ID, es.shift_id)
WHERE p.punch_time >= :desde AND p.punch_time < DATEADD(day, 1, :hasta)
ORDER BY e.emp_firstname, e.emp_lastname, p.punch_time, p.id
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':desde' => $start->format('Y-m-d'), ':hasta' => $end->format('Y-m-d')]);
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) $groups[$p['emp_id'] . '|' . $p['fecha']][] = $p;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="checadas-' . $start->format('Y-m-d') . '-' . $end->format('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fecha', 'Empleado', 'Departamento', 'Horario', 'Entrada', 'Salida comida', 'Regreso comida', 'Duración comida', 'Salida', 'Número checadas', 'Estado', 'Minutos tarde', 'Descuento MXN']);
    foreach ($groups as $punches) {
        $first = $punches[0];
        $firstAt = new DateTimeImmutable((string)$first['punch_time']);
        $summary = summarize_attendance_punches($punches);
        $exitAt = $summary['exit_index'] !== null
            ? new DateTimeImmutable((string)$punches[$summary['exit_index']]['punch_time']) : null;
        $late = calculate_late_minutes($firstAt, (string)$first['fecha'], $first['hora_inicio'] ?? null);
        $status = $late === null ? 'Sin horario' : ($late > 0 ? 'Retardo' : 'A tiempo');
        $mealOut = $summary['meal_out_index'] !== null ? new DateTimeImmutable((string)$punches[$summary['meal_out_index']]['punch_time']) : null;
        $mealBack = $summary['meal_back_index'] !== null ? new DateTimeImmutable((string)$punches[$summary['meal_back_index']]['punch_time']) : null;
        $values = [(new DateTimeImmutable((string)$first['fecha']))->format('Y-m-d'),
            trim($first['emp_firstname'] . ' ' . $first['emp_lastname']), $first['departamento'],
            $first['shift_name'] ?: ($first['hora_inicio'] ?: 'Sin horario'), $firstAt->format('H:i:s'),
            $mealOut ? $mealOut->format('H:i:s') : '', $mealBack ? $mealBack->format('H:i:s') : '',
            $summary['meal_minutes'] === null ? '' : sprintf('%02d:%02d', intdiv($summary['meal_minutes'], 60), $summary['meal_minutes'] % 60),
            $exitAt?->format('H:i:s') ?? '', count($punches), $status, $late ?? '',
            number_format(($late ?? 0) * 3, 2, '.', '')];
        foreach ($values as &$value) {
            $value = (string)$value;
            if (preg_match('/^[=+@\-]/', $value)) $value = "'" . $value;
        }
        unset($value);
        fputcsv($out, $values);
    }
    fclose($out);
} catch (Throwable $exception) {
    http_response_code(503);
    exit('No se pudo generar el CSV. Revisa la conexión y los permisos SQL.');
}
