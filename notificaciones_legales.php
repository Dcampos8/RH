<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branding.php';
require_permission('legal_notifications_view');

function legal_ident(string $name): string
{
    return '[' . str_replace(']', ']]', $name) . ']';
}

function legal_snapshot(array $row): string
{
    return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
}

function legal_add_history(PDO $pdo, int $notificationId, string $action, ?array $before, ?array $after, string $reason): void
{
    $versionQuery = $pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM dbo.notificaciones_legales_historial WITH (UPDLOCK, HOLDLOCK) WHERE id_notificacion = ?');
    $versionQuery->execute([$notificationId]);
    $version = (int)$versionQuery->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO dbo.notificaciones_legales_historial (id_notificacion, version, accion, usuario_id, usuario, motivo_cambio, datos_anteriores, datos_nuevos) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([
        $notificationId,
        $version,
        $action,
        (int)($_SESSION['app_user_id'] ?? 0) ?: null,
        (string)($_SESSION['app_username'] ?? ''),
        $reason !== '' ? $reason : null,
        $before === null ? null : legal_snapshot($before),
        $after === null ? null : legal_snapshot($after),
    ]);
}

function legal_display_value(mixed $value): string
{
    if ($value === null || $value === '') return '—';
    if (is_resource($value)) return '[dato binario]';
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d H:i:s');
    $text = (string)$value;
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= 240) return $text;
    return function_exists('mb_substr') ? mb_substr($text, 0, 240, 'UTF-8') . '…' : substr($text, 0, 240) . '…';
}

$pdo = null;
$branding = branding_settings();
$error = '';
$notice = '';
$tableSchema = '';
$tableSql = '';
$columns = [];
$notificationRows = [];
$selectedRecord = null;
$historyRows = [];
$historyId = (int)($_GET['historial'] ?? 0);
$editingId = (int)($_GET['editar'] ?? 0);
$showGeneralHistory = isset($_GET['historial_general']);
$canManage = has_permission('legal_notifications_manage');

try {
    $pdo = db_admp24();
    $schemaQuery = $pdo->prepare("SELECT TOP (1) TABLE_SCHEMA FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY CASE WHEN TABLE_SCHEMA = N'dbo' THEN 0 ELSE 1 END, TABLE_SCHEMA");
    $schemaQuery->execute(['notificaciones_legales']);
    $tableSchema = (string)($schemaQuery->fetchColumn() ?: '');
    if ($tableSchema === '') throw new RuntimeException('No se encontró la tabla notificaciones_legales en ADMP24.');
    $tableSql = legal_ident($tableSchema) . '.' . legal_ident('notificaciones_legales');

    $columnQuery = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $columnQuery->execute([$tableSchema, 'notificaciones_legales']);
    foreach ($columnQuery->fetchAll(PDO::FETCH_ASSOC) as $column) $columns[(string)$column['COLUMN_NAME']] = $column;
    if (!isset($columns['id_notificacion'], $columns['expediente'])) {
        throw new RuntimeException('La tabla debe incluir id_notificacion y expediente.');
    }
    if (!$pdo->query("SELECT OBJECT_ID(N'dbo.notificaciones_legales_historial', N'U')")->fetchColumn()) {
        throw new RuntimeException('Falta crear el historial. Ejecuta sql/admp24_notificaciones_historial.sql en ADMP24.');
    }
} catch (Throwable $exception) {
    $error = 'No se pudo preparar el módulo ADMP24. ' . $exception->getMessage();
}

$readForm = static function (array $source) use (&$columns): array {
    $data = [];
    $stringFields = ['expediente', 'tipo_juicio', 'demandante', 'demandado', 'estatus', 'motivo', 'autoridad_juzgado', 'archivo'];
    foreach ($stringFields as $field) {
        if (!isset($columns[$field])) continue;
        $value = trim((string)($source[$field] ?? ''));
        if ($field === 'expediente' && $value === '') throw new InvalidArgumentException('El expediente es obligatorio.');
        if ($value === '' && $field !== 'expediente') {
            $data[$field] = null;
            continue;
        }
        $maximum = $columns[$field]['CHARACTER_MAXIMUM_LENGTH'];
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($maximum !== null && (int)$maximum !== -1 && $length > (int)$maximum) {
            throw new InvalidArgumentException($field . ' excede el máximo de ' . (int)$maximum . ' caracteres.');
        }
        $data[$field] = $value;
    }
    if (isset($columns['fecha_notificacion'])) {
        $value = trim((string)($source['fecha_notificacion'] ?? ''));
        if ($value === '') {
            $data['fecha_notificacion'] = null;
        } else {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('La fecha de notificación no es válida.');
            }
            $data['fecha_notificacion'] = $date->format('Y-m-d');
        }
    }
    return $data;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!$canManage) {
        http_response_code(403);
        exit('Tu cuenta puede consultar, pero no administrar notificaciones legales.');
    }
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = 'La solicitud venció. Recarga la página.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $reason = trim((string)($_POST['motivo_cambio'] ?? ''));
        try {
            $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
            if ($reasonLength > 1000) throw new InvalidArgumentException('El motivo del cambio no puede exceder 1000 caracteres.');

            if ($action === 'create') {
                $data = $readForm($_POST);
                if (isset($columns['fecha_registro'])) $data['fecha_registro'] = date('Y-m-d H:i:s');
                if (isset($columns['usuario_registro'])) $data['usuario_registro'] = (int)$_SESSION['app_user_id'];
                $pdo->beginTransaction();
                $identityQuery = $pdo->prepare("SELECT c.is_identity FROM sys.columns AS c JOIN sys.tables AS t ON t.object_id = c.object_id JOIN sys.schemas AS s ON s.schema_id = t.schema_id WHERE s.name = ? AND t.name = N'notificaciones_legales' AND c.name = N'id_notificacion'");
                $identityQuery->execute([$tableSchema]);
                $isIdentity = (bool)$identityQuery->fetchColumn();
                if (!$isIdentity) {
                    $currentMax = (int)$pdo->query("SELECT COALESCE(MAX(id_notificacion), 0) FROM $tableSql WITH (UPDLOCK, HOLDLOCK)")->fetchColumn();
                    $historyMax = (int)$pdo->query('SELECT COALESCE(MAX(id_notificacion), 0) FROM dbo.notificaciones_legales_historial WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
                    $data['id_notificacion'] = max($currentMax, $historyMax) + 1;
                }
                $insertFields = array_values(array_filter(array_keys($data), static fn(string $field): bool => isset($columns[$field])));
                $insertColumns = implode(', ', array_map('legal_ident', $insertFields));
                $placeholders = implode(', ', array_fill(0, count($insertFields), '?'));
                $insertSql = "INSERT INTO $tableSql ($insertColumns) OUTPUT INSERTED." . legal_ident('id_notificacion') . " VALUES ($placeholders)";
                $insert = $pdo->prepare($insertSql);
                $insert->execute(array_map(static fn(string $field) => $data[$field], $insertFields));
                $newId = (int)$insert->fetchColumn();
                $read = $pdo->prepare("SELECT * FROM $tableSql WHERE " . legal_ident('id_notificacion') . ' = ?');
                $read->execute([$newId]);
                $after = $read->fetch(PDO::FETCH_ASSOC);
                if (!$after) throw new RuntimeException('Se insertó la notificación pero no se pudo releer para guardar su versión.');
                legal_add_history($pdo, $newId, 'CREAR', null, $after, $reason);
                $pdo->commit();
                header('Location: notificaciones_legales.php?creada=' . $newId, true, 303);
                exit;
            }

            if ($action === 'edit') {
                $notificationId = (int)($_POST['id_notificacion'] ?? 0);
                if ($notificationId < 1) throw new InvalidArgumentException('ID de notificación no válido.');
                $pdo->beginTransaction();
                $read = $pdo->prepare("SELECT * FROM $tableSql WITH (UPDLOCK, HOLDLOCK) WHERE " . legal_ident('id_notificacion') . ' = ?');
                $read->execute([$notificationId]);
                $before = $read->fetch(PDO::FETCH_ASSOC);
                if (!$before) throw new InvalidArgumentException('No se encontró la notificación.');
                $data = $readForm($_POST);
                $editable = array_values(array_filter(array_keys($data), static fn(string $field): bool => !in_array($field, ['fecha_registro', 'usuario_registro', 'id_notificacion'], true)));
                $setters = implode(', ', array_map(static fn(string $field): string => legal_ident($field) . ' = ?', $editable));
                if ($setters !== '') {
                    $update = $pdo->prepare("UPDATE $tableSql SET $setters WHERE " . legal_ident('id_notificacion') . ' = ?');
                    $update->execute([...array_map(static fn(string $field) => $data[$field], $editable), $notificationId]);
                }
                $read->execute([$notificationId]);
                $after = $read->fetch(PDO::FETCH_ASSOC);
                if (!$after) throw new RuntimeException('No se pudo recuperar la notificación después del cambio.');
                $changed = legal_snapshot($before) !== legal_snapshot($after);
                if ($changed || $reason !== '') legal_add_history($pdo, $notificationId, 'EDITAR', $before, $after, $reason);
                $pdo->commit();
                header('Location: notificaciones_legales.php?editar=' . $notificationId . '&guardado=' . ($changed ? '1' : 'sin_cambios'), true, 303);
                exit;
            }

            if ($action === 'delete') {
                $notificationId = (int)($_POST['id_notificacion'] ?? 0);
                if ($notificationId < 1) throw new InvalidArgumentException('ID de notificación no válido.');
                $pdo->beginTransaction();
                $read = $pdo->prepare("SELECT * FROM $tableSql WITH (UPDLOCK, HOLDLOCK) WHERE " . legal_ident('id_notificacion') . ' = ?');
                $read->execute([$notificationId]);
                $before = $read->fetch(PDO::FETCH_ASSOC);
                if (!$before) throw new InvalidArgumentException('No se encontró la notificación para eliminar.');
                legal_add_history($pdo, $notificationId, 'ELIMINAR', $before, null, $reason);
                $delete = $pdo->prepare("DELETE FROM $tableSql WHERE " . legal_ident('id_notificacion') . ' = ?');
                $delete->execute([$notificationId]);
                $pdo->commit();
                header('Location: notificaciones_legales.php?historial=' . $notificationId . '&eliminada=1', true, 303);
                exit;
            }

            throw new InvalidArgumentException('Acción no válida.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'No se pudo guardar la notificación. SQL dice: ' . $exception->getMessage();
        }
    }
}

if (isset($_GET['creada'])) $notice = 'Notificación creada y versión 1 guardada en el historial.';
if (isset($_GET['guardado']) && $_GET['guardado'] === '1') $notice = 'Cambios guardados como nueva versión.';
if (isset($_GET['guardado']) && $_GET['guardado'] === 'sin_cambios') $notice = 'No hubo cambios; no se creó una versión duplicada.';
if (isset($_GET['eliminada'])) $notice = 'Notificación eliminada. Su historial y versiones se conservaron.';

if ($pdo && $error === '') {
    try {
        if ($historyId > 0) {
            $history = $pdo->prepare('SELECT TOP (500) id_historial, id_notificacion, version, accion, fecha_cambio_utc, usuario_id, usuario, motivo_cambio, datos_anteriores, datos_nuevos FROM dbo.notificaciones_legales_historial WHERE id_notificacion = ? ORDER BY version DESC');
            $history->execute([$historyId]);
            $historyRows = $history->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($showGeneralHistory) {
            $historyRows = $pdo->query('SELECT TOP (500) id_historial, id_notificacion, version, accion, fecha_cambio_utc, usuario_id, usuario, motivo_cambio, datos_anteriores, datos_nuevos FROM dbo.notificaciones_legales_historial ORDER BY id_historial DESC')->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $notificationRows = $pdo->query("SELECT TOP (500) * FROM $tableSql ORDER BY " . legal_ident('id_notificacion') . ' DESC')->fetchAll(PDO::FETCH_ASSOC);
            $versions = $pdo->query('SELECT id_notificacion, MAX(version) AS version FROM dbo.notificaciones_legales_historial GROUP BY id_notificacion')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($notificationRows as &$row) $row['_version'] = (int)($versions[$row['id_notificacion']] ?? 0);
            unset($row);
            if ($editingId > 0) {
                $selectEdit = $pdo->prepare("SELECT * FROM $tableSql WHERE " . legal_ident('id_notificacion') . ' = ?');
                $selectEdit->execute([$editingId]);
                $selectedRecord = $selectEdit->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    } catch (Throwable $exception) {
        $error = 'No se pudieron consultar las notificaciones o su historial. Verifica permisos de SELECT y la migración sql/admp24_notificaciones_historial.sql. Detalle: ' . $exception->getMessage();
    }
}

$formValues = $selectedRecord ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['create', 'edit'], true)) {
    foreach (['expediente', 'tipo_juicio', 'demandante', 'demandado', 'estatus', 'fecha_notificacion', 'motivo', 'autoridad_juzgado', 'archivo'] as $field) {
        if (isset($_POST[$field])) $formValues[$field] = $_POST[$field];
    }
}
$columnOrder = array_keys($columns);
$listColumns = array_values(array_filter(['id_notificacion', 'expediente', 'tipo_juicio', 'demandante', 'demandado', 'fecha_registro', 'estatus', 'fecha_notificacion', 'autoridad_juzgado'], static fn(string $field): bool => isset($columns[$field])));
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Notificaciones legales · ADMP24</title><link rel="stylesheet" href="assets/app.css"><link rel="icon" href="favicon.php?v=<?=time()?>" sizes="any"><style>
body{background:#f3f5f8;color:#17202a;margin:0;font:15px system-ui}main{width:auto;max-width:none;box-sizing:border-box;margin:24px 0;padding:0 24px}.card{background:#fff;border:1px solid #dce2e9;border-radius:12px;padding:18px;margin:18px 0}.toolbar,.actions{display:flex;gap:10px;align-items:end;flex-wrap:wrap}label{display:grid;gap:5px}input,select,textarea,button,a.button{font:inherit;padding:9px 12px;border:1px solid #bcc7d3;border-radius:7px}textarea{min-height:90px;min-width:min(500px,90vw)}button,a.button{background:#173b67;color:#fff;text-decoration:none;border:0;cursor:pointer}.error{color:#a32323;background:#fff0f0;padding:12px;border-radius:8px}.ok{color:#277341}.hint{font-size:13px;color:#637083}.scroll{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:9px;text-align:left;vertical-align:top;border-bottom:1px solid #e5e9ee}th{background:#edf2f7;white-space:nowrap}.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.form-grid textarea,.wide{grid-column:1/-1}.actions form{display:inline}.snapshots pre{white-space:pre-wrap;overflow-wrap:anywhere;max-height:380px;overflow:auto;background:#f6f7f9;padding:12px;border-radius:8px}.version{min-width:80px}
</style></head><body style="--brand:<?=h($branding['accent_color'])?>;--brand-dark:<?=h($branding['dark_color'])?>"><main>
<div class="brandbar"><img src="logo.php" alt="<?=h($branding['brand_name'])?>"><div class="brand-caption"><strong><?=h($branding['brand_name'])?></strong>Módulo independiente · ADMP24</div></div>
<h1>Notificaciones legales</h1><p><a href="index.php">Volver al checador</a> · <a href="notificaciones_legales.php?historial_general=1">Historial general</a></p>
<?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?><?php if ($notice): ?><p class="ok"><?=h($notice)?></p><?php endif; ?>
<?php if ($pdo && $tableSchema !== ''): ?><p class="hint">Base de datos ADMP24 · Tabla <?=h($tableSchema)?>.<?=h('notificaciones_legales')?> · Se muestran hasta 500 registros por vista.</p><?php endif; ?>
<?php if ($pdo && $tableSchema !== '' && $historyId === 0 && !$showGeneralHistory && $canManage): ?>
<section class="card"><h2><?=$selectedRecord ? 'Editar notificación #' . h($selectedRecord['id_notificacion']) : 'Capturar notificación'?></h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="<?=$selectedRecord ? 'edit' : 'create'?>"><?php if ($selectedRecord): ?><input type="hidden" name="id_notificacion" value="<?=h($selectedRecord['id_notificacion'])?>"><?php endif; ?>
<div class="form-grid">
<?php if (isset($columns['expediente'])): ?><label>Expediente *<input name="expediente" maxlength="50" value="<?=h($formValues['expediente'] ?? '')?>" required></label><?php endif; ?>
<?php if (isset($columns['tipo_juicio'])): ?><label>Tipo de juicio<input name="tipo_juicio" maxlength="100" value="<?=h($formValues['tipo_juicio'] ?? '')?>"></label><?php endif; ?>
<?php if (isset($columns['demandante'])): ?><label>Demandante<input name="demandante" maxlength="255" value="<?=h($formValues['demandante'] ?? '')?>"></label><?php endif; ?>
<?php if (isset($columns['demandado'])): ?><label>Demandado<input name="demandado" maxlength="255" value="<?=h($formValues['demandado'] ?? '')?>"></label><?php endif; ?>
<?php if (isset($columns['estatus'])): ?><label>Estatus<input name="estatus" maxlength="20" value="<?=h($formValues['estatus'] ?? 'Pendiente')?>"></label><?php endif; ?>
<?php if (isset($columns['fecha_notificacion'])): ?><label>Fecha de notificación<input type="date" name="fecha_notificacion" value="<?=h(isset($formValues['fecha_notificacion']) ? substr((string)$formValues['fecha_notificacion'], 0, 10) : '')?>"></label><?php endif; ?>
<?php if (isset($columns['autoridad_juzgado'])): ?><label class="wide">Autoridad / juzgado<input name="autoridad_juzgado" maxlength="255" value="<?=h($formValues['autoridad_juzgado'] ?? '')?>"></label><?php endif; ?>
<?php if (isset($columns['archivo'])): ?><label class="wide">Archivo (nombre o ruta; máximo 255 caracteres)<input name="archivo" maxlength="255" value="<?=h($formValues['archivo'] ?? '')?>"></label><?php endif; ?>
<?php if (isset($columns['motivo'])): ?><label class="wide">Motivo<textarea name="motivo"><?=h($formValues['motivo'] ?? '')?></textarea></label><?php endif; ?>
<label class="wide">Motivo de esta captura o cambio (opcional)<textarea name="motivo_cambio" maxlength="1000" placeholder="Quedará guardado en el historial de versiones"></textarea></label>
</div><div class="actions"><button><?=$selectedRecord ? 'Guardar nueva versión' : 'Guardar notificación'?></button><?php if ($selectedRecord): ?><a class="button" href="notificaciones_legales.php">Cancelar edición</a><?php endif; ?></div>
</form></section><?php endif; ?>

<?php if ($pdo && $tableSchema !== '' && $historyId === 0 && !$showGeneralHistory): ?>
<section class="card"><div class="toolbar"><label>Buscar en las notificaciones<input id="legal-search" type="search" placeholder="Expediente, persona, autoridad o estatus…" autocomplete="off"></label><span class="hint" id="legal-count"><?=count($notificationRows)?> registros</span></div></section>
<section class="card scroll"><table><thead><tr><?php foreach ($listColumns as $column): ?><th><?=h(str_replace('_', ' ', ucfirst($column)))?></th><?php endforeach; ?><th>Versión</th><th>Control</th></tr></thead><tbody id="legal-list">
<?php foreach ($notificationRows as $record): $searchValues = []; foreach ($listColumns as $field) $searchValues[] = legal_display_value($record[$field] ?? null); ?>
<tr class="legal-row" data-search="<?=h(implode(' ', $searchValues))?>"><?php foreach ($listColumns as $field): ?><td><?=h(legal_display_value($record[$field] ?? null))?></td><?php endforeach; ?><td class="version"><?=h($record['_version'])?></td><td><div class="actions"><a class="button" href="notificaciones_legales.php?historial=<?=h($record['id_notificacion'])?>">Historial</a><?php if ($canManage): ?><a class="button" href="notificaciones_legales.php?editar=<?=h($record['id_notificacion'])?>">Editar</a><form method="post" onsubmit="return confirmDelete(this, <?=h($record['id_notificacion'])?>)"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id_notificacion" value="<?=h($record['id_notificacion'])?>"><input type="hidden" name="motivo_cambio" value=""><button>Eliminar</button></form><?php endif; ?></div></td></tr>
<?php endforeach; ?><tr id="legal-no-results" hidden><td colspan="<?=count($listColumns)+2?>" class="hint">No hay notificaciones que coincidan.</td></tr>
</tbody></table></section><?php endif; ?>

<?php if ($historyId > 0 || $showGeneralHistory): ?><section class="card"><h2><?=$historyId > 0 ? 'Versiones de la notificación #' . h($historyId) : 'Historial general'?></h2><?php if (!$historyRows): ?><p>No hay versiones en el historial para esta consulta.</p><?php endif; ?><?php foreach ($historyRows as $item): ?><article class="card"><div class="toolbar"><strong>Notificación #<?=h($item['id_notificacion'])?> · Versión <?=h($item['version'])?> · <?=h($item['accion'])?></strong><span><?=h(format_utc_local($item['fecha_cambio_utc']))?></span><span>Usuario: <?=h($item['usuario'] ?: '—')?> (ID <?=h($item['usuario_id'] ?? '—')?>)</span></div><?php if ($item['motivo_cambio']): ?><p><b>Motivo:</b> <?=h($item['motivo_cambio'])?></p><?php endif; ?><div class="snapshots"><details><summary>Ver datos anteriores y nuevos</summary><?php if ($item['datos_anteriores'] !== null): ?><h3>Antes</h3><pre><?=h(json_encode(json_decode((string)$item['datos_anteriores'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))?></pre><?php endif; ?><?php if ($item['datos_nuevos'] !== null): ?><h3>Después</h3><pre><?=h(json_encode(json_decode((string)$item['datos_nuevos'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))?></pre><?php endif; ?></details></div></article><?php endforeach; ?><p><a class="button" href="notificaciones_legales.php">Volver al módulo</a></p></section><?php endif; ?>

</main><script>
const legalSearch=document.getElementById('legal-search');if(legalSearch){const legalRows=[...document.querySelectorAll('.legal-row')],count=document.getElementById('legal-count'),empty=document.getElementById('legal-no-results');legalSearch.addEventListener('input',()=>{const q=legalSearch.value.trim().toLocaleLowerCase('es-MX').normalize('NFD').replace(/[\u0300-\u036f]/g,'');let visible=0;for(const row of legalRows){const show=row.dataset.search.toLocaleLowerCase('es-MX').normalize('NFD').replace(/[\u0300-\u036f]/g,'').includes(q);row.hidden=!show;if(show)visible++;}count.textContent=visible+' de '+legalRows.length+' registros';empty.hidden=visible!==0;});}
function confirmDelete(form,id){const reason=prompt('Motivo de eliminación (opcional):','');if(reason===null)return false;form.querySelector('[name="motivo_cambio"]').value=reason;return confirm('¿Eliminar la notificación #'+id+'? El registro desaparecerá de la tabla, pero todas sus versiones quedarán guardadas en el historial.');}
</script></body></html>
