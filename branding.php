<?php
declare(strict_types=1);

function branding_settings(?PDO $connection = null): array
{
    static $cached = null;
    if ($cached !== null && $connection === null) return $cached;

    $defaults = ['brand_name' => 'Transportes Aldez', 'accent_color' => '#ed2429', 'dark_color' => '#c91d22'];
    try {
        $pdo = $connection ?? db();
        $row = $pdo->query('SELECT brand_name, accent_color, dark_color FROM dbo.rh_branding WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $defaults['brand_name'] = trim((string)$row['brand_name']) ?: $defaults['brand_name'];
            foreach (['accent_color', 'dark_color'] as $field) {
                if (preg_match('/^#[0-9a-fA-F]{6}$/', trim((string)$row[$field]))) $defaults[$field] = trim((string)$row[$field]);
            }
        }
    } catch (Throwable $exception) {
        // Keep default branding available while the database or branding table is not ready.
    }
    if ($connection === null) $cached = $defaults;
    return $defaults;
}
