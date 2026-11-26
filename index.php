<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$from = $_GET['desde'] ?? $today;
$to = $_GET['hasta'] ?? $today;
$rows = [];
$error = '';

try {
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$start || !$end || $end < $start || $start->diff($end)->days > 366) {
        throw new InvalidArgumentException('El rango de fechas no es válido o excede 367 días.');
    }
    if (!extension_loaded('pdo_sqlsrv')) {
        throw new RuntimeException('Falta habilitar la extensión PDO_SQLSRV de PHP.');
    }
    $pdo = db();
    $sql = <<<'SQL'
SELECT p.emp_id, e.emp_pin, e.emp_firstname, e.emp_lastname,
       COALESCE(d.dept_name, N'') AS departamento,
       CONVERT(date, p.punch_time) AS fecha,
       p.punch_time, p.punch_type, p.workstate, p.workcode,
       p.terminal_id, t.terminal_name, s.shift_name,
       CONVERT(time(0), s.shift_start) AS hora_inicio
FROM dbo.att_punches AS p
JOIN dbo.hr_employee AS e ON e.id = p.emp_id
LEFT JOIN dbo.hr_department AS d ON d.id = e.emp_dept
LEFT JOIN dbo.att_terminal AS t ON t.id = p.terminal_id
OUTER APPLY (
  SELECT TOP (1) detail.shift_ID
  FROM dbo.att_day_details AS detail
  WHERE detail.employee_id = p.emp_id
    AND CONVERT(date, detail.att_date) = CONVERT(date, p.punch_time)
  ORDER BY detail.sortindex
) AS dd
LEFT JOIN dbo.att_shift AS s ON s.id = dd.shift_ID
WHERE p.punch_time >= :desde AND p.punch_time < DATEADD(day, 1, :hasta)
ORDER BY e.emp_firstname, e.emp_lastname, p.punch_time, p.id
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':desde' => $start->format('Y-m-d'), ':hasta' => $end->format('Y-m-d')]);
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $key = $record['emp_id'] . '|' . $record['fecha'];
        $groups[$key][] = $record;
    }
    foreach ($groups as $punches) {
        $first = $punches[0];
        $firstAt = new DateTimeImmutable((string)$punches[0]['punch_time']);
        $lastAt = new DateTimeImmutable((string)$punches[count($punches) - 1]['punch_time']);
        $lateMinutes = 0;
        if (!empty($first['hora_inicio'])) {
            $scheduled = new DateTimeImmutable($first['fecha'] . ' ' . $first['hora_inicio']);
            $graceEnd = $scheduled->modify('+10 minutes');
            if ($firstAt > $graceEnd) {
                $secondsLate = $firstAt->getTimestamp() - $graceEnd->getTimestamp();
                $lateMinutes = (int)ceil($secondsLate / 60);
            }
        }
        $details = [];
        foreach ($punches as $punch) {
            $at = new DateTimeImmutable((string)$punch['punch_time']);
            $details[] = $at->format('H:i:s') . ' · tipo ' . ($punch['punch_type'] ?: '—')
                . ' · estado ' . ($punch['workstate'] ?: '—') . ' · reloj '
                . ($punch['terminal_name'] ?: ($punch['terminal_id'] ?: '—'));
        }
        $rows[] = [
            'fecha' => (new DateTimeImmutable((string)$first['fecha']))->format('Y-m-d'),
            'empleado' => trim($first['emp_firstname'] . ' ' . $first['emp_lastname']),
            'departamento' => $first['departamento'],
            'horario' => $first['shift_name'] ?: ($first['hora_inicio'] ?: 'Sin horario'),
            'entrada' => $firstAt->format('H:i:s'),
            'salida' => count($punches) > 1 ? $lastAt->format('H:i:s') : '—',
            'minutos' => $lateMinutes,
            'descuento' => $lateMinutes * 3,
            'cantidad' => count($punches),
            'detalle' => $details,
        ];
    }
} catch (Throwable $exception) {
    $error = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
        ? $exception->getMessage() : 'No se pudo consultar CHECADOR. Revisa la conexión y los permisos de SQL.';
}

$syncMessage = null;
try {
    $syncMessage = db()->query("SELECT TOP (1) detalles_nuevos AS result_text, accion AS status, fecha_registro AS finished_at FROM dbo.sys_logs_auditoria WHERE accion IN ('SYNC_OK', 'SYNC_ERROR') ORDER BY id DESC")->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $exception) { /* Los logs usan la tabla de auditoría existente. */ }
$totalPenalty = array_sum(array_column($rows, 'descuento'));
$totalPunches = array_sum(array_column($rows, 'cantidad'));
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reloj checador</title><style>
:root{font:15px system-ui,Segoe UI,sans-serif;color:#17202a;background:#f3f5f8}body{margin:0}main{max-width:1250px;margin:32px auto;padding:0 18px}h1{margin-bottom:4px}.muted,.hint{color:#637083}.card{background:#fff;border:1px solid #dce2e9;border-radius:12px;padding:18px;margin:18px 0}form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}label{display:grid;gap:6px}input,button,a.button{font:inherit;padding:9px 12px;border:1px solid #bcc7d3;border-radius:7px;background:#fff}button,a.button{background:#173b67;color:#fff;border:0;text-decoration:none;cursor:pointer}.scroll{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px;border-bottom:1px solid #e5e9ee;vertical-align:top}th{background:#edf2f7;white-space:nowrap}.details{min-width:360px;font-size:13px;color:#475569}.error{color:#a32323;background:#fff0f0;padding:12px;border-radius:8px}.stats{display:flex;gap:26px;flex-wrap:wrap}.hint{font-size:13px}.sync{display:inline}.sync button{background:#277341}
</style></head><body><main>
<h1>Reporte de checador</h1><div class="muted">CHECADOR · SQL Server · ZKTeco F19 (192.168.10.149) · <?=h($_SESSION['app_username'])?></div>
<nav><?php if (strtolower((string)$_SESSION['app_username']) === 'admin'): ?><a href="users.php">Usuarios</a> <a href="settings.php">Configuración</a><?php endif; ?> <form class="sync" method="post" action="logout.php"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button>Cerrar sesión</button></form></nav>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>
<?php if (!empty($syncMessage)): ?><p class="card">Última sincronización · <?=h($syncMessage['status'])?> · <?=h($syncMessage['finished_at'])?><br><?=h($syncMessage['result_text'])?></p><?php endif; ?>
<section class="card"><form method="get"><label>Desde<input type="date" name="desde" value="<?=h($from)?>" required></label><label>Hasta<input type="date" name="hasta" value="<?=h($to)?>" required></label><button>Consultar</button><a class="button" href="export.php?desde=<?=h($from)?>&amp;hasta=<?=h($to)?>">Descargar CSV</a></form>
<p class="hint">Tolerancia de 10 minutos. Después, $3 MXN por cada minuto empezado de retraso.</p></section>
<?php if (strtolower((string)$_SESSION['app_username']) === 'admin'): ?><section class="card"><form class="sync" method="post" action="sync.php" onsubmit="return confirm('Sincronizar checadas y usuarios entre CHECADOR y el F19. ¿Continuar?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button>Sincronizar ahora</button></form><p class="hint">La sincronización automática se configura en Configuración. No transfiere huellas ni limpia el reloj.</p></section><?php endif; ?>
<section class="card stats"><div><b><?=count($rows)?></b><br>registros empleado/día</div><div><b>$<?=number_format((float)$totalPenalty, 2)?></b><br>descuento calculado</div><div><b><?=$totalPunches?></b><br>checadas</div></section>
<section class="card scroll"><table><thead><tr><th>Fecha</th><th>Empleado</th><th>Departamento</th><th>Horario</th><th>Entrada</th><th>Salida</th><th>Min. tarde</th><th>Descuento</th><th>Todas las checadas</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?=h($row['fecha'])?></td><td><?=h($row['empleado'])?></td><td><?=h($row['departamento'])?></td><td><?=h($row['horario'])?></td><td><?=h($row['entrada'])?></td><td><?=h($row['salida'])?></td><td><?=h($row['minutos'])?></td><td>$<?=number_format((float)$row['descuento'], 2)?></td><td class="details"><?php foreach ($row['detalle'] as $line): ?><?=h($line)?><br><?php endforeach; ?></td></tr><?php endforeach; ?>
</tbody></table></section></main></body></html>
