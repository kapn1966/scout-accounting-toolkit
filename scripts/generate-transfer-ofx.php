#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    fwrite(STDERR, "Run this developer utility through WP-CLI with plugins and themes skipped.\n");
    exit(1);
}

$batch_arg = getenv('SAT_TRANSFER_BATCH_ID');
$output_arg = getenv('SAT_TRANSFER_OUTPUT_DIR');
if ($batch_arg === false || $output_arg === false || !ctype_digit($batch_arg) || (int)$batch_arg < 1) {
    fwrite(STDERR, "Set SAT_TRANSFER_BATCH_ID to a generated or confirmed transfer batch ID and SAT_TRANSFER_OUTPUT_DIR to an existing writable directory.\n");
    exit(1);
}

$output_dir = realpath($output_arg);
if ($output_dir === false || !is_dir($output_dir) || !is_writable($output_dir)) {
    fwrite(STDERR, "SAT_TRANSFER_OUTPUT_DIR must be an existing writable directory.\n");
    exit(1);
}

$includes = dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes';
require_once $includes . '/class-sat-settings.php';
require_once $includes . '/class-sat-db.php';
require_once $includes . '/class-sat-ofx-writer.php';
require_once $includes . '/class-sat-transfer-ofx.php';

$batch_id = (int)$batch_arg;
$destination = $output_dir . DIRECTORY_SEPARATOR . 'sat-transfer-batch-' . $batch_id . '.zip';
$temporary = tempnam($output_dir, '.sat-transfer-batch-');
if ($temporary === false) {
    fwrite(STDERR, "Could not create a temporary bundle in the selected output directory.\n");
    exit(1);
}

try {
    if (!chmod($temporary, 0600)) {
        throw new RuntimeException('Could not secure the temporary transfer bundle.');
    }
    SAT_Transfer_OFX::instance()->write_bundle_for_batch($batch_id, $temporary);
    if (file_exists($destination) || !link($temporary, $destination)) {
        throw new RuntimeException('The bundle already exists or could not be moved into the output directory.');
    }
    if (!unlink($temporary)) {
        throw new RuntimeException('Bundle was written, but its private temporary link could not be removed.');
    }
    echo 'Wrote frozen transfer batch bundle: ' . $destination . "\n";
} catch (Throwable $e) {
    if (is_file($temporary)) {
        unlink($temporary);
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
