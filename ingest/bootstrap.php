<?php
/**
 * Shared start-up for the command-line tools: loads config/config.php from the project root
 * (the folder above ingest/) and opens the database connection.
 *
 * Usage:  [$config, $pdo] = require dirname(__DIR__) . '/bootstrap.php';   (from ingest/<tool>/)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Missing configuration file: {$configFile}\n");
    exit(1);
}
$config = require $configFile;

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['database']['host'], $config['database']['name']),
    $config['database']['user'],
    $config['database']['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

return [$config, $pdo];
