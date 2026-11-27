<?php
declare(strict_types=1);

date_default_timezone_set('America/Mexico_City');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Lax']);
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}

function require_login(): void
{
    if (empty($_SESSION['app_user_id'])) {
        header('Location: login.php', true, 303);
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (strtolower((string)($_SESSION['app_username'] ?? '')) !== 'admin') {
        http_response_code(403);
        exit('No tienes permiso para esta sección.');
    }
}

function permission_catalog(): array
{
    return [
        'report_view' => 'Ver reportes y exportar a CSV',
        'employee_view' => 'Ver empleados del checador',
        'employee_create' => 'Dar de alta empleados y cambiar su estatus',
        'schedule_view' => 'Ver horarios asignados',
        'schedule_manage' => 'Crear turnos y administrar horarios',
        'platform_users_manage' => 'Crear cuentas de acceso',
        'settings_manage' => 'Cambiar configuración y marca del portal',
        'sync_run' => 'Ejecutar sincronización con el reloj',
    ];
}

function is_admin_user(): bool
{
    return strtolower((string)($_SESSION['app_username'] ?? '')) === 'admin';
}

function current_user_permissions(): array
{
    static $cache = [];
    $userId = (int)($_SESSION['app_user_id'] ?? 0);
    if (is_admin_user()) return array_fill_keys(array_keys(permission_catalog()), true);
    if (isset($cache[$userId])) return $cache[$userId];

    $permissions = array_fill_keys(array_keys(permission_catalog()), false);
    $permissions['report_view'] = true; // Existing and new accounts keep access to the report by default.
    if ($userId > 0) {
        try {
            $columns = implode(', ', array_map(static fn($key) => '[' . $key . ']', array_keys(permission_catalog())));
            $stmt = db()->prepare("SELECT $columns FROM dbo.rh_user_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                foreach (permission_catalog() as $key => $_label) $permissions[$key] = (bool)$row[$key];
            }
        } catch (Throwable $exception) {
            // A missing permission table never grants elevated access.
        }
    }
    if ($permissions['employee_create']) $permissions['employee_view'] = true;
    if ($permissions['schedule_manage']) $permissions['schedule_view'] = true;
    return $cache[$userId] = $permissions;
}

function has_permission(string $permission): bool
{
    if (!array_key_exists($permission, permission_catalog())) return false;
    if (is_admin_user()) return true;
    return current_user_permissions()[$permission] ?? false;
}

function require_permission(string $permission): void
{
    require_login();
    if (!has_permission($permission)) {
        http_response_code(403);
        exit('No tienes permiso para esta sección. Solicita acceso al administrador.');
    }
}

function default_user_route(): string
{
    if (is_admin_user() || has_permission('report_view')) return 'index.php';
    foreach ([
        'employee_view' => 'empleados.php',
        'schedule_view' => 'horarios.php',
        'platform_users_manage' => 'users.php',
        'settings_manage' => 'settings.php',
    ] as $permission => $route) {
        if (has_permission($permission)) return $route;
    }
    return 'sin_acceso.php';
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_utc_local(mixed $value): string
{
    if ($value === null || trim((string)$value) === '') return '—';
    try {
        return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('America/Mexico_City'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable $exception) {
        return (string)$value;
    }
}
