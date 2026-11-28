<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_sqlsrv')) {
        throw new RuntimeException('Falta habilitar PDO_SQLSRV en PHP.');
    }
    $configPath = __DIR__ . '/config.php';
    $config = require is_file($configPath) ? $configPath : __DIR__ . '/config.example.php';
    $pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
    ]);
    return $pdo;
}

function db_admp24(): PDO
{
    static $pdoAdmp24 = null;
    if ($pdoAdmp24 instanceof PDO) return $pdoAdmp24;
    if (!extension_loaded('pdo_sqlsrv')) {
        throw new RuntimeException('Falta habilitar PDO_SQLSRV en PHP.');
    }
    $configPath = __DIR__ . '/config.php';
    $config = require is_file($configPath) ? $configPath : __DIR__ . '/config.example.php';
    $dsn = getenv('ADMP24_DSN') ?: ($config['dsn_admp24'] ?? '');
    if ($dsn === '') {
        $dsn = preg_replace_callback('/(^|;)Database\s*=\s*[^;]*/i', static fn(array $match): string => $match[1] . 'Database=ADMP24', (string)$config['dsn'], 1, $replacements);
        if ($replacements === 0) $dsn = rtrim((string)$config['dsn'], ';') . ';Database=ADMP24';
    }
    $pdoAdmp24 = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
    ]);
    return $pdoAdmp24;
}
