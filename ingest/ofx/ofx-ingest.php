<?php
declare(strict_types=1);

/**
 * 403TO OFX Inbox Ingester
 *
 * Usage:
 *   php ofx-ingest.php file1.ofx [file2.ofx ...]
 *
 * Files may have an additional .txt extension.
 *
 * Supported accounts:
 *   952161CHEQCAD2   => Operating
 *   952161CHEQCAD1   => CG Funds
 *   4058210012517361 => GC Visa
 *
 * Supported statement formats:
 *   OFX 1.x SGML bank statements using BANKACCTFROM
 *   OFX 2.x XML credit-card statements using CCACCTFROM
 *
 * This script writes only to:
 *   inbox_import_batches
 *   inbox_observations
 *
 * It does not match, classify, or export transactions to Wave.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This ingester must be run from the command line.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(
        STDERR,
        "Usage: php ofx-ingest.php file1.ofx [file2.ofx ...]\n"
    );
    exit(1);
}

$config = require __DIR__ . '/config/config.php';

$accountMap = [
    '952161CHEQCAD2' => [
        'account_name' => 'Operating',
        'source_system' => 'ofx_operating',
        'statement_type' => 'bank',
    ],
    '952161CHEQCAD1' => [
        'account_name' => 'CG Funds',
        'source_system' => 'ofx_cg_funds',
        'statement_type' => 'bank',
    ],
    '4058210012517361' => [
        'account_name' => 'GC Visa',
        'source_system' => 'ofx_visa',
        'statement_type' => 'credit_card',
    ],
];

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    $config['database']['host'],
    $config['database']['name']
);

$pdo = new PDO(
    $dsn,
    $config['database']['user'],
    $config['database']['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$insertBatch = $pdo->prepare(
    'INSERT INTO inbox_import_batches (
        source_system,
        source_filename,
        rows_imported,
        rows_rejected,
        notes
    ) VALUES (?, ?, 0, 0, ?)'
);

$updateBatch = $pdo->prepare(
    'UPDATE inbox_import_batches
     SET rows_imported = ?,
         rows_rejected = ?,
         notes = ?
     WHERE batch_id = ?'
);

$insertObservation = $pdo->prepare(
    'INSERT INTO inbox_observations (
        import_batch_id,
        source_system,
        source_key,
        transaction_date,
        amount,
        direction,
        description,
        payments_reference_number,
        gf_entry_id,
        wave_transaction_id,
        payload_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?)
     ON DUPLICATE KEY UPDATE
        import_batch_id = VALUES(import_batch_id),
        transaction_date = VALUES(transaction_date),
        amount = VALUES(amount),
        direction = VALUES(direction),
        description = VALUES(description),
        payload_json = VALUES(payload_json)'
);

$filesImported = 0;
$filesFailed = 0;
$totalInserted = 0;
$totalRefreshed = 0;
$totalRejected = 0;

foreach (array_slice($argv, 1) as $inputPath) {
    $filename = basename($inputPath);
    $batchId = null;

    try {
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new RuntimeException(
                "File is missing or unreadable: {$inputPath}"
            );
        }

        $raw = file_get_contents($inputPath);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException("OFX file is empty: {$inputPath}");
        }

        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $rawUtf8 = decodeOfxText($raw);

        $detectedStatementType = detectOfxStatementType($rawUtf8);
        $accountId = ofxAccountTagValue($rawUtf8, $detectedStatementType, 'ACCTID');
        $bankId = $detectedStatementType === 'bank'
            ? ofxAccountTagValue($rawUtf8, 'bank', 'BANKID')
            : '';
        $branchId = $detectedStatementType === 'bank'
            ? ofxAccountTagValue($rawUtf8, 'bank', 'BRANCHID')
            : '';
        $accountType = $detectedStatementType === 'credit_card'
            ? 'CREDITCARD'
            : ofxAccountTagValue($rawUtf8, 'bank', 'ACCTTYPE');

        $currency = ofxTagValue($rawUtf8, 'CURDEF');
        $dateStartRaw = ofxTagValue($rawUtf8, 'DTSTART');
        $dateEndRaw = ofxTagValue($rawUtf8, 'DTEND');
        $ledgerBalance = ofxTagValue($rawUtf8, 'BALAMT');
        $ledgerDateRaw = ofxTagValue($rawUtf8, 'DTASOF');

        if ($accountId === '') {
            throw new RuntimeException(
                "OFX file does not contain BANKACCTFROM/ACCTID or " .
                "CCACCTFROM/ACCTID: {$filename}"
            );
        }

        if (!isset($accountMap[$accountId])) {
            throw new RuntimeException(
                "Unsupported OFX account {$accountId} in {$filename}. " .
                'Add an explicit account mapping before importing it.'
            );
        }

        $accountName = $accountMap[$accountId]['account_name'];
        $sourceSystem = $accountMap[$accountId]['source_system'];
        $statementType = $accountMap[$accountId]['statement_type'];

        if ($statementType !== $detectedStatementType) {
            throw new RuntimeException(
                sprintf(
                    'Account %s is mapped as %s but the file contains a %s statement.',
                    $accountId,
                    $statementType,
                    $detectedStatementType
                )
            );
        }

        $blocks = ofxTransactionBlocks($rawUtf8);
        if (count($blocks) === 0) {
            throw new RuntimeException(
                "No STMTTRN transaction blocks found in {$filename}."
            );
        }

        $batchNotes = sprintf(
            'OFX import started. Account=%s; type=%s; ACCTID=%s; transactions=%d.',
            $accountName,
            $statementType,
            $accountId,
            count($blocks)
        );

        $insertBatch->execute([
            $sourceSystem,
            $filename,
            $batchNotes,
        ]);
        $batchId = (int)$pdo->lastInsertId();

        $inserted = 0;
        $refreshed = 0;
        $rejected = 0;
        $rejectionMessages = [];

        foreach ($blocks as $index => $block) {
            try {
                $transaction = parseOfxTransaction($block);
                $fitId = $transaction['fitid'];
                $postedDate = normalizeOfxDate($transaction['dtposted']);
                $amount = normalizeOfxAmount($transaction['trnamt']);
                $direction = $amount < 0 ? 'expense' : 'income';
                $description = buildDescription(
                    $transaction['name'],
                    $transaction['memo']
                );

                $sourceKey = sprintf(
                    '%s:%s:%s',
                    $sourceSystem,
                    $accountId,
                    $fitId
                );

                $payload = json_encode(
                    [
                        'format' => detectOfxFormat($rawUtf8),
                        'ofx_version' => detectOfxVersion($rawUtf8),
                        'source_filename' => $filename,
                        'statement_type' => $statementType,
                        'account_name' => $accountName,
                        'account_id' => $accountId,
                        'account_type' => $accountType,
                        'bank_id' => $bankId,
                        'branch_id' => $branchId,
                        'currency' => $currency,
                        'statement_start' => normalizeOptionalOfxDate($dateStartRaw),
                        'statement_end' => normalizeOptionalOfxDate($dateEndRaw),
                        'ledger_balance' => $ledgerBalance,
                        'ledger_date' => normalizeOptionalOfxDate($ledgerDateRaw),
                        'transaction_type' => $transaction['trntype'],
                        'posted_date_raw' => $transaction['dtposted'],
                        'amount' => number_format($amount, 2, '.', ''),
                        'fitid' => $fitId,
                        'name' => $transaction['name'],
                        'memo' => $transaction['memo'],
                        'check_number' => $transaction['checknum'],
                        'reference_number' => $transaction['refnum'],
                        'raw_transaction' => trim($block),
                    ],
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE |
                    JSON_THROW_ON_ERROR
                );

                $pdo->beginTransaction();
                try {
                    $insertObservation->execute([
                        $batchId,
                        $sourceSystem,
                        $sourceKey,
                        $postedDate,
                        number_format($amount, 2, '.', ''),
                        $direction,
                        $description,
                        $payload,
                    ]);

                    if ($insertObservation->rowCount() === 1) {
                        $inserted++;
                    } else {
                        $refreshed++;
                    }

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            } catch (Throwable $e) {
                $rejected++;
                $rejectionMessages[] = sprintf(
                    'Transaction %d rejected: %s',
                    $index + 1,
                    $e->getMessage()
                );
            }
        }

        $notes = sprintf(
            'Completed. Account=%s; type=%s; ACCTID=%s; inserted=%d; refreshed=%d; rejected=%d; statement=%s to %s; ledger=%s %s.',
            $accountName,
            $statementType,
            $accountId,
            $inserted,
            $refreshed,
            $rejected,
            normalizeOptionalOfxDate($dateStartRaw) ?? 'unknown',
            normalizeOptionalOfxDate($dateEndRaw) ?? 'unknown',
            $currency !== '' ? $currency : 'unknown currency',
            $ledgerBalance !== '' ? $ledgerBalance : 'unknown balance'
        );

        if ($rejectionMessages !== []) {
            $notes .= ' ' . implode(' | ', $rejectionMessages);
        }

        $updateBatch->execute([
            $inserted + $refreshed,
            $rejected,
            $notes,
            $batchId,
        ]);

        $filesImported++;
        $totalInserted += $inserted;
        $totalRefreshed += $refreshed;
        $totalRejected += $rejected;

        printf(
            "%s: account=%s type=%s inserted=%d refreshed=%d rejected=%d batch=%d\n",
            $filename,
            $accountName,
            $statementType,
            $inserted,
            $refreshed,
            $rejected,
            $batchId
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $filesFailed++;
        $totalRejected++;

        if ($batchId !== null) {
            try {
                $updateBatch->execute([
                    0,
                    1,
                    'FAILED: ' . $e->getMessage(),
                    $batchId,
                ]);
            } catch (Throwable $ignored) {
                // Preserve the original exception.
            }
        }

        fwrite(
            STDERR,
            sprintf("%s: FAILED: %s\n", $filename, $e->getMessage())
        );
    }
}

printf(
    "Done. Files imported=%d failed=%d observations inserted=%d refreshed=%d rejected=%d\n",
    $filesImported,
    $filesFailed,
    $totalInserted,
    $totalRefreshed,
    $totalRejected
);

exit($filesFailed > 0 || $totalRejected > 0 ? 1 : 0);

function decodeOfxText(string $raw): string
{
    $encoding = detectOfxEncoding($raw);

    if (strcasecmp($encoding, 'UTF-8') === 0) {
        return $raw;
    }

    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($raw, 'UTF-8', $encoding);
    }

    if (function_exists('iconv')) {
        $converted = iconv($encoding, 'UTF-8//TRANSLIT', $raw);
        if ($converted !== false) {
            return $converted;
        }
    }

    return $raw;
}

function detectOfxEncoding(string $ofx): string
{
    $headerEncoding = ofxHeaderValue($ofx, 'ENCODING');
    $charset = ofxHeaderValue($ofx, 'CHARSET');

    if (strcasecmp($headerEncoding, 'UTF-8') === 0 || $charset === '65001') {
        return 'UTF-8';
    }

    if (preg_match('/<\?xml[^>]*encoding=["\']([^"\']+)["\']/i', $ofx, $matches) === 1) {
        $xmlEncoding = strtoupper(trim($matches[1]));
        if ($xmlEncoding === 'UTF-8') {
            return 'UTF-8';
        }
        if ($xmlEncoding === 'US-ASCII') {
            return 'Windows-1252';
        }
        return $xmlEncoding;
    }

    if ($charset === '1252' || strcasecmp($headerEncoding, 'USASCII') === 0) {
        return 'Windows-1252';
    }

    return 'Windows-1252';
}

function detectOfxStatementType(string $ofx): string
{
    if (preg_match('/<CCACCTFROM\b/i', $ofx) === 1) {
        return 'credit_card';
    }

    if (preg_match('/<BANKACCTFROM\b/i', $ofx) === 1) {
        return 'bank';
    }

    throw new RuntimeException(
        'OFX file contains neither CCACCTFROM nor BANKACCTFROM.'
    );
}

function ofxAccountTagValue(
    string $ofx,
    string $statementType,
    string $tag
): string {
    $container = $statementType === 'credit_card'
        ? 'CCACCTFROM'
        : 'BANKACCTFROM';

    if (preg_match(
        '/<' . preg_quote($container, '/') . '\b[^>]*>(.*?)<\/' .
        preg_quote($container, '/') . '>/is',
        $ofx,
        $matches
    ) !== 1) {
        return '';
    }

    return ofxTagValue($matches[1], $tag);
}

function detectOfxFormat(string $ofx): string
{
    return preg_match('/^\s*<\?xml\b/i', $ofx) === 1
        ? 'OFXXML'
        : 'OFXSGML';
}

function detectOfxVersion(string $ofx): string
{
    $version = ofxHeaderValue($ofx, 'VERSION');
    if ($version !== '') {
        return $version;
    }

    if (preg_match('/<\?OFX\b[^>]*\bVERSION=["\']?([^\s"\'?>]+)/i', $ofx, $matches) === 1) {
        return trim($matches[1]);
    }

    return '';
}

function ofxHeaderValue(string $ofx, string $name): string
{
    if (preg_match(
        '/(?:^|\n)' . preg_quote($name, '/') . ':([^\n]*)/i',
        $ofx,
        $matches
    ) !== 1) {
        return '';
    }

    return trim($matches[1]);
}

function ofxTagValue(string $text, string $tag): string
{
    $pattern = '/<' . preg_quote($tag, '/') . '\b[^>]*>\s*([^<]*)/i';
    if (preg_match($pattern, $text, $matches) !== 1) {
        return '';
    }

    return trim(
        html_entity_decode(
            $matches[1],
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )
    );
}

function ofxTransactionBlocks(string $ofx): array
{
    if (preg_match_all(
        '/<STMTTRN\b[^>]*>\s*(.*?)\s*<\/STMTTRN>/is',
        $ofx,
        $matches
    ) === false) {
        return [];
    }

    return $matches[1] ?? [];
}

function parseOfxTransaction(string $block): array
{
    $transaction = [
        'trntype' => ofxTagValue($block, 'TRNTYPE'),
        'dtposted' => ofxTagValue($block, 'DTPOSTED'),
        'trnamt' => ofxTagValue($block, 'TRNAMT'),
        'fitid' => ofxTagValue($block, 'FITID'),
        'name' => ofxTagValue($block, 'NAME'),
        'memo' => ofxTagValue($block, 'MEMO'),
        'checknum' => ofxTagValue($block, 'CHECKNUM'),
        'refnum' => ofxTagValue($block, 'REFNUM'),
    ];

    $missing = [];
    foreach (['dtposted', 'trnamt', 'fitid'] as $field) {
        if ($transaction[$field] === '') {
            $missing[] = strtoupper($field);
        }
    }

    if ($missing !== []) {
        throw new RuntimeException(
            'Missing required OFX fields: ' . implode(', ', $missing)
        );
    }

    return $transaction;
}

function normalizeOfxDate(string $value): string
{
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $matches) !== 1) {
        throw new RuntimeException("Invalid OFX date: {$value}");
    }

    $year = (int)$matches[1];
    $month = (int)$matches[2];
    $day = (int)$matches[3];

    if (!checkdate($month, $day, $year)) {
        throw new RuntimeException("Invalid OFX calendar date: {$value}");
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function normalizeOptionalOfxDate(string $value): ?string
{
    return $value === '' ? null : normalizeOfxDate($value);
}

function normalizeOfxAmount(string $value): float
{
    $normalized = str_replace([',', ' '], '', trim($value));
    if ($normalized === '' || !is_numeric($normalized)) {
        throw new RuntimeException("Invalid OFX amount: {$value}");
    }

    return (float)$normalized;
}

function buildDescription(string $name, string $memo): string
{
    $name = trim($name);
    $memo = trim($memo);

    if ($name !== '' && $memo !== '') {
        return $name . ' | ' . $memo;
    }

    if ($memo !== '') {
        return $memo;
    }

    if ($name !== '') {
        return $name;
    }

    return '[No OFX description]';
}
