<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();
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
       p.terminal_id, t.terminal_name, s.shift_name, CONVERT(time(0), s.shift_start) AS hora_inicio
FROM dbo.att_punches AS p
JOIN dbo.hr_employee AS e ON e.id = p.emp_id
LEFT JOIN dbo.hr_department AS d ON d.id = e.emp_dept
LEFT JOIN dbo.att_terminal AS t ON t.id = p.terminal_id
OUTER APPLY (SELECT TOP (1) detail.shift_ID FROM dbo.att_day_details AS detail
             WHERE detail.employee_id = p.emp_id AND CONVERT(date, detail.att_date) = CONVERT(date, p.punch_time)
             ORDER BY detail.sortindex) AS dd
LEFT JOIN dbo.att_shift AS s ON s.id = dd.shift_ID
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
    fputcsv($out, ['Fecha', 'Empleado', 'Departamento', 'Horario', 'Entrada', 'Salida', 'Minutos tarde', 'Descuento MXN', 'Número checadas', 'Detalle checadas']);
    foreach ($groups as $punches) {
        $first = $punches[0];
        $firstAt = new DateTimeImmutable((string)$first['punch_time']);
        $lastAt = new DateTimeImmutable((string)$punches[count($punches) - 1]['punch_time']);
        $late = 0;
        if (!empty($first['hora_inicio'])) {
            $graceEnd = new DateTimeImmutable($first['fecha'] . ' ' . $first['hora_inicio']);
            $graceEnd = $graceEnd->modify('+10 minutes');
            if ($firstAt > $graceEnd) $late = (int)ceil(($firstAt->getTimestamp() - $graceEnd->getTimestamp()) / 60);
        }
        $details = [];
        foreach ($punches as $p) {
            $at = new DateTimeImmutable((string)$p['punch_time']);
            $details[] = $at->format('H:i:s') . ' tipo=' . ($p['punch_type'] ?: '—') . ' estado=' . ($p['workstate'] ?: '—')
                . ' reloj=' . ($p['terminal_name'] ?: ($p['terminal_id'] ?: '—'));
        }
        $values = [(new DateTimeImmutable((string)$first['fecha']))->format('Y-m-d'),
            trim($first['emp_firstname'] . ' ' . $first['emp_lastname']), $first['departamento'],
            $first['shift_name'] ?: ($first['hora_inicio'] ?: 'Sin horario'), $firstAt->format('H:i:s'),
            count($punches) > 1 ? $lastAt->format('H:i:s') : '', $late, number_format($late * 3, 2, '.', ''),
            count($punches), implode('; ', $details)];
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
