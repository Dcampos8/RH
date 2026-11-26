<?php
declare(strict_types=1);

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

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
