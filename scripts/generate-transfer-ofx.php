#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) {
    fwrite(STDERR, "Run this developer utility through WP-CLI with plugins and themes skipped.\n");
    exit(1);
}

function sat_transfer_ofx_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function sat_transfer_ofx_payload(array $observation): array
{
    $payload = json_decode((string)($observation['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Observation #' . $observation['observation_id'] . ' has invalid OFX payload metadata.');
    }
    return $payload;
}

function sat_transfer_ofx_account(array $observation, array $payload, array $banks, array $configured): array
{
    $source = (string)$observation['source_system'];
    if (!isset($banks[$source])) {
        throw new RuntimeException('Observation #' . $observation['observation_id'] . ' does not belong to a configured SAT payment account.');
    }
    $bank = $banks[$source];
    $account_id = (string)($bank['acctid'] ?? '');
    if ($account_id === '' || !isset($configured[$account_id])) {
        throw new RuntimeException('Configured account ID is missing for ' . $source . '.');
    }
    if ((string)($payload['account_id'] ?? '') !== $account_id) {
        throw new RuntimeException('Stored account identity does not match the configured account for ' . $source . '.');
    }
    if ((string)($payload['statement_type'] ?? '') !== (string)$bank['type']) {
        throw new RuntimeException('Stored statement type does not match the configured account type for ' . $source . '.');
    }
    $currency = strtoupper(trim((string)($payload['currency'] ?? '')));
    if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
        throw new RuntimeException('A valid stored currency is required for ' . $source . '.');
    }
    $metadata = [
        'type' => (string)$bank['type'],
        'label' => (string)$bank['label'],
        'acctid' => $account_id,
        'currency' => $currency,
    ];
    if ($metadata['type'] === 'bank') {
        foreach (['bank_id' => 'bank_id', 'branch_id' => 'branch_id', 'account_type' => 'account_type'] as $key => $field) {
            $metadata[$key] = trim((string)($payload[$field] ?? ''));
            if ($metadata[$key] === '') {
                throw new RuntimeException('Stored ' . strtoupper(str_replace('_', ' ', $field)) . ' is required for ' . $source . '.');
            }
        }
    }
    return $metadata;
}

function sat_transfer_ofx_clean_source(string $source): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $source);
    return trim((string)$safe, '_-');
}

function sat_transfer_ofx_write(string $path, string $contents): void
{
    if (is_file($path)) {
        $existing = file_get_contents($path);
        if ($existing === $contents) {
            return;
        }
        throw new RuntimeException('Refusing to overwrite a different file: ' . $path);
    }
    if (file_exists($path)) {
        throw new RuntimeException('Output path already exists and is not a regular file: ' . $path);
    }
    $temporary = tempnam(dirname($path), '.sat-transfer-ofx-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create a temporary output file in ' . dirname($path) . '.');
    }
    try {
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Could not write the complete OFX file: ' . $path);
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Could not move the generated OFX file into place: ' . $path);
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

$match_arg = getenv('SAT_TRANSFER_MATCH_ID');
$output_arg = getenv('SAT_TRANSFER_OUTPUT_DIR');
if ($match_arg === false || $output_arg === false || !ctype_digit($match_arg) || (int)$match_arg < 1) {
    sat_transfer_ofx_fail(
        'Set SAT_TRANSFER_MATCH_ID to an accepted match ID and SAT_TRANSFER_OUTPUT_DIR '
        . 'to an existing writable directory, then invoke this file with WP-CLI eval.'
    );
}

$output_dir = realpath($output_arg);
if ($output_dir === false || !is_dir($output_dir) || !is_writable($output_dir)) {
    sat_transfer_ofx_fail('SAT_TRANSFER_OUTPUT_DIR must be an existing writable directory.');
}

try {
    $includes = dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes';
    require_once $includes . '/class-sat-settings.php';
    require_once $includes . '/class-sat-db.php';
    require_once $includes . '/class-sat-ofx-writer.php';

    $match_id = (int)$match_arg;
    $db = SAT_DB::pdo();
    $q = $db->prepare(
        'SELECT m.match_id, m.left_observation_id, m.right_observation_id,
                o.observation_id, o.source_system, o.source_key, o.transaction_date,
                o.amount, o.description, o.payload_json, r.review_status
         FROM inbox_matches m
         JOIN inbox_observations o
           ON o.observation_id IN (m.left_observation_id, m.right_observation_id)
         LEFT JOIN inbox_reviews r ON r.bank_observation_id = o.observation_id
         WHERE m.match_id = ?
         ORDER BY o.observation_id'
    );
    $q->execute([$match_id]);
    $observations = $q->fetchAll();
    if (count($observations) !== 2
        || (int)$observations[0]['observation_id'] === (int)$observations[1]['observation_id']) {
        throw new RuntimeException('Match #' . $match_id . ' is not a two-observation match.');
    }
    if ((int)$observations[0]['left_observation_id'] !== (int)$observations[1]['left_observation_id']
        || (int)$observations[0]['right_observation_id'] !== (int)$observations[1]['right_observation_id']) {
        throw new RuntimeException('The observations do not belong to the same accepted match.');
    }
    $linked = $db->prepare(
        'SELECT COUNT(*) FROM inbox_matches
         WHERE left_observation_id IN (?, ?) OR right_observation_id IN (?, ?)'
    );
    $ids = [(int)$observations[0]['observation_id'], (int)$observations[1]['observation_id']];
    $linked->execute([$ids[0], $ids[1], $ids[0], $ids[1]]);
    if ((int)$linked->fetchColumn() !== 1) {
        throw new RuntimeException('Each transfer observation must belong to this one accepted match only.');
    }

    $banks = SAT_Settings::banks();
    $configured = SAT_Settings::get('accounts');
    $payloads = [];
    $account_metadata = [];
    foreach ($observations as $index => $observation) {
        if (in_array((string)($observation['review_status'] ?? ''), ['excluded', 'exported'], true)) {
            throw new RuntimeException('Observation #' . $observation['observation_id'] . ' is excluded or already exported.');
        }
        $payloads[$index] = sat_transfer_ofx_payload($observation);
        $account_metadata[$index] = sat_transfer_ofx_account($observation, $payloads[$index], $banks, $configured);
    }
    if ((string)$observations[0]['source_system'] === (string)$observations[1]['source_system']
        || $account_metadata[0]['acctid'] === $account_metadata[1]['acctid']) {
        throw new RuntimeException('Transfer observations must belong to two different configured SAT payment accounts.');
    }
    if ($account_metadata[0]['currency'] !== $account_metadata[1]['currency']) {
        throw new RuntimeException('Transfer account currencies do not match.');
    }
    $amount_a = SAT_OFX_Writer::amount_cents((string)$observations[0]['amount']);
    $amount_b = SAT_OFX_Writer::amount_cents((string)$observations[1]['amount']);
    if ($amount_a === 0 || $amount_a !== -$amount_b) {
        throw new RuntimeException('Transfer amounts must be non-zero, equal and opposite.');
    }

    $pair_identity = [];
    foreach ($observations as $observation) {
        $pair_identity[] = [
            'source_system' => (string)$observation['source_system'],
            'source_key' => (string)$observation['source_key'],
        ];
    }
    $files = [];
    foreach ($observations as $index => $observation) {
        $other_index = $index === 0 ? 1 : 0;
        $payload = $payloads[$index];
        $transaction = [
            'source_system' => (string)$observation['source_system'],
            'source_key' => (string)$observation['source_key'],
            'pair_identity' => $pair_identity,
            'date' => (string)$observation['transaction_date'],
            'amount' => (string)$observation['amount'],
            'other_account' => (string)$account_metadata[$other_index]['label'],
            'description' => (string)($observation['description'] ?? ''),
            'memo' => (string)($payload['memo'] ?? ''),
            'statement_start' => (string)($payload['statement_start'] ?? ''),
            'statement_end' => (string)($payload['statement_end'] ?? ''),
        ];
        $contents = SAT_OFX_Writer::serialize($account_metadata[$index], $transaction);
        $safe_source = sat_transfer_ofx_clean_source((string)$observation['source_system']);
        if ($safe_source === '') {
            throw new RuntimeException('Configured source name cannot be used for an output filename.');
        }
        $path = $output_dir . DIRECTORY_SEPARATOR . 'sat-transfer-' . $match_id . '-' . $safe_source . '.ofx';
        $files[] = [
            'path' => $path,
            'contents' => $contents,
            'account' => $account_metadata[$index]['label'],
            'amount' => (string)$observation['amount'],
        ];
    }

    foreach ($files as $file) {
        sat_transfer_ofx_write($file['path'], $file['contents']);
    }
    echo 'Accepted transfer match #' . $match_id . ' (' . number_format(abs($amount_a) / 100, 2) . ' '
        . $account_metadata[0]['currency'] . ").\n";
    foreach ($files as $file) {
        echo $file['account'] . ' [' . $file['amount'] . ']: ' . $file['path'] . "\n";
    }
} catch (Throwable $e) {
    sat_transfer_ofx_fail($e->getMessage());
}
