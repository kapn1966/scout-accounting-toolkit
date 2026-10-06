<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$configFile = $root . '/config/config.php';

if (!is_file($configFile)) {
    fwrite(
        STDERR,
        "Missing configuration file: {$configFile}\n"
    );
    exit(1);
}

$config = require $configFile;

return $config;