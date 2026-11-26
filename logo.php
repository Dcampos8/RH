<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
try {
    $stmt = db()->query('SELECT logo_data, logo_mime FROM dbo.rh_branding WHERE id = 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $image = $row['logo_data'] ?? null;
    $mime = (string)($row['logo_mime'] ?? '');
    if (is_resource($image)) $image = stream_get_contents($image);
    if (is_string($image) && $image !== '' && in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($image));
        echo $image;
        exit;
    }
} catch (Throwable $exception) { /* Use the bundled logo as a fallback. */ }

header('Content-Type: image/svg+xml; charset=utf-8');
readfile(__DIR__ . '/assets/logo.svg');
