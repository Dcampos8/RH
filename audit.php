<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function audit_event(string $action, string $details, ?int $userId = null, ?string $username = null): void
{
    $pdo = db();
    if ($username === null && $userId !== null) {
        $stmt = $pdo->prepare('SELECT username FROM dbo.sys_user WHERE id = ?');
        $stmt->execute([$userId]);
        $username = $stmt->fetchColumn() ?: null;
    }
    $identity = (int)$pdo->query("SELECT COLUMNPROPERTY(OBJECT_ID('dbo.sys_logs_auditoria'), 'id', 'IsIdentity')")->fetchColumn() === 1;
    $fields = ['fecha_registro', 'usuario_accion', 'accion', 'detalles_nuevos', 'ip_maquina'];
    $values = [gmdate('Y-m-d H:i:s'), $username, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null];
    if (!$identity) {
        $nextId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM dbo.sys_logs_auditoria WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
        array_unshift($fields, 'id');
        array_unshift($values, $nextId);
    }
    $columns = implode(', ', array_map(static fn($field) => '[' . $field . ']', $fields));
    $params = implode(', ', array_fill(0, count($fields), '?'));
    $stmt = $pdo->prepare("INSERT INTO dbo.sys_logs_auditoria ($columns) VALUES ($params)");
    $stmt->execute($values);
}
