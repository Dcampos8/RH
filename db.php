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
