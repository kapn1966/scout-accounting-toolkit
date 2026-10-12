<?php
/**
 * Weekly Interac importer report (run weekly from cron).
 *
 * Emails a seven-day summary of imported Interac payments with data-quality checks:
 * totals by section, unknown sections, missing data, unusual payer names, empty messages,
 * suspicious dates, unexpected subjects, and fatal errors from process.log.
 *
 * Reads the Interac records in inbox_observations (the legacy payments table is no longer used).
 */

declare(strict_types=1);

$logDir = __DIR__ . '/logs';
$logFile = $logDir . '/weekly-report.log';
$processorLog = $logDir . '/process.log';
if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
    fwrite(STDERR, "Unable to create log directory: {$logDir}\n");
    exit(1);
}

/** Interac payments as rows with the fields the report uses. */
const PAYMENTS = "(
    SELECT
        payments_reference_number AS reference_number,
        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.section')) AS section,
        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.payer_name')) AS payer_name,
        amount,
        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.payment_message')) AS payment_message,
        transaction_date AS payment_date,
        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.raw_subject')) AS raw_subject,
        created_at AS imported_at
    FROM inbox_observations
    WHERE source_system = 'interac_email'
) p";

function money($amount): string
{
    return '$' . number_format((float)$amount, 2);
}

function show($value): string
{
    $value = trim((string)$value);
    return $value === '' ? '[empty]' : $value;
}

try {
    [$config, $pdo] = require dirname(__DIR__) . '/bootstrap.php';
    $report = (array)($config['report'] ?? []);
    if (empty($report['to']) || empty($report['from'])) {
        throw new RuntimeException('Set report.to and report.from in config.php.');
    }
    $known = array_map('strtolower', (array)($report['known_sections'] ?? []));
    $labels = (array)($report['section_labels'] ?? []);
    $label = function ($section) use ($labels): string {
        $section = trim((string)$section);
        return $labels[$section] ?? ($section === '' ? '[empty]' : $section);
    };

    $end = new DateTimeImmutable('now');
    $start = $end->sub(new DateInterval('P7D'));
    $period = [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    $inPeriod = 'imported_at >= ? AND imported_at < ?';

    $query = function (string $sql, array $args = []) use ($pdo): array {
        $q = $pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    };

    $all = $query('SELECT COUNT(*) n, COALESCE(SUM(amount), 0) total FROM ' . PAYMENTS)[0];
    $week = $query('SELECT COUNT(*) n, COALESCE(SUM(amount), 0) total FROM ' . PAYMENTS . " WHERE $inPeriod", $period)[0];
    $latest = $query('SELECT * FROM ' . PAYMENTS . ' ORDER BY imported_at DESC LIMIT 1')[0] ?? null;
    $bySection = $query('SELECT section, COUNT(*) n, COALESCE(SUM(amount), 0) total FROM ' . PAYMENTS . " WHERE $inPeriod GROUP BY section ORDER BY section", $period);
    $recent = $query('SELECT * FROM ' . PAYMENTS . " WHERE $inPeriod ORDER BY imported_at DESC LIMIT 10", $period);

    $checks = [
        'Missing or invalid required data' => $query('SELECT * FROM ' . PAYMENTS . " WHERE $inPeriod AND (
            COALESCE(TRIM(reference_number), '') = '' OR COALESCE(TRIM(section), '') = '' OR COALESCE(TRIM(payer_name), '') = ''
            OR amount IS NULL OR amount <= 0 OR payment_date IS NULL)", $period),
        'Suspicious payer names' => $query('SELECT * FROM ' . PAYMENTS . " WHERE $inPeriod AND (
            payer_name IS NULL OR CHAR_LENGTH(TRIM(payer_name)) < 3 OR CHAR_LENGTH(payer_name) > 150
            OR payer_name REGEXP '[0-9]' OR payer_name REGEXP '[[:cntrl:]]' OR payer_name REGEXP '[[:space:]]{3,}')", $period),
        'Empty payment messages' => $query('SELECT * FROM ' . PAYMENTS . " WHERE $inPeriod AND (
            COALESCE(TRIM(payment_message), '') = '' OR CHAR_LENGTH(payment_message) > 1000 OR payment_message REGEXP '[[:cntrl:]]')", $period),
        'Suspicious dates' => $query('SELECT * FROM ' . PAYMENTS . " WHERE $inPeriod AND (
            payment_date IS NULL OR payment_date > DATE_ADD(CURDATE(), INTERVAL 1 DAY) OR DATEDIFF(DATE(imported_at), payment_date) > 30)", $period),
        'Unexpected subject formats' => $query('SELECT * FROM ' . PAYMENTS . " WHERE $inPeriod AND (
            COALESCE(TRIM(raw_subject), '') = '' OR LOWER(raw_subject) NOT LIKE '%interac e-transfer%')", $period),
    ];
    $checks['Unknown sections'] = $known ? array_values(array_filter($query('SELECT * FROM ' . PAYMENTS . " WHERE $inPeriod", $period), function ($row) use ($known) {
        return !in_array(strtolower(trim((string)$row['section'])), $known, true);
    })) : [];

    $fatal = [];
    if (is_readable($processorLog)) {
        foreach ((array)file($processorLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (stripos($line, 'FATAL:') === false) {
                continue;
            }
            $when = preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m) ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m[1]) : false;
            if (!$when || ($when >= $start && $when < $end)) {
                $fatal[] = $line;
            }
        }
    }

    $findings = array_sum(array_map('count', $checks)) + count($fatal);

    $out = [];
    $heading = function (string $title) use (&$out): void {
        $out[] = $title;
        $out[] = str_repeat('-', strlen($title));
    };
    $out[] = 'Scout Accounting weekly Interac report';
    $out[] = str_repeat('=', 38);
    $out[] = '';
    $heading('Reporting period');
    $out[] = $period[0] . ' to ' . $period[1];
    $out[] = '';
    $heading('Importer health');
    $out[] = 'Payments imported this period: ' . (int)$week['n'];
    $out[] = 'Amount imported this period: ' . money($week['total']);
    $out[] = 'Interac payments in the inbox: ' . (int)$all['n'];
    $out[] = 'Total amount in the inbox: ' . money($all['total']);
    $uid = $query("SELECT state_value FROM processor_state WHERE state_key = 'last_processed_uid'")[0]['state_value'] ?? '[missing]';
    $out[] = 'Last processed IMAP UID: ' . $uid;
    $out[] = $latest
        ? sprintf('Latest import: %s | %s | %s | %s', $latest['imported_at'], $label($latest['section']), money($latest['amount']), $latest['reference_number'])
        : 'Latest import: none';
    $out[] = '';
    $heading('Payments by section');
    if (!$bySection) {
        $out[] = 'No payments were imported during this period.';
    }
    foreach ($bySection as $row) {
        $out[] = sprintf('%-24s %3d payment(s)  %10s', $label($row['section']), $row['n'], money($row['total']));
    }
    $out[] = '';
    $heading('Findings');
    $out[] = 'Total findings: ' . $findings;
    foreach ($checks as $title => $rows) {
        $out[] = $title . ': ' . count($rows);
    }
    $out[] = 'Fatal importer errors: ' . count($fatal);
    $out[] = '';
    foreach ($checks as $title => $rows) {
        if (!$rows) {
            continue;
        }
        $heading($title);
        foreach ($rows as $row) {
            $out[] = sprintf('%s | %s | %s | %s | %s', show($row['reference_number']), $label($row['section']), show($row['payer_name']), money($row['amount']), show($row['payment_date']));
            if ($title === 'Empty payment messages') {
                $out[] = '    Message: ' . show($row['payment_message']);
            }
            if ($title === 'Unexpected subject formats' || $title === 'Unknown sections') {
                $out[] = '    Subject: ' . show($row['raw_subject']);
            }
        }
        $out[] = '';
    }
    if ($fatal) {
        $heading('Fatal importer errors');
        $out = array_merge($out, $fatal, ['']);
    }
    $heading('Recent payments');
    if (!$recent) {
        $out[] = 'No payments were imported during this period.';
    }
    foreach ($recent as $row) {
        $out[] = sprintf('%s | %s | %s | %s | Ref: %s', show($row['payment_date']), $label($row['section']), show($row['payer_name']), money($row['amount']), $row['reference_number']);
        $out[] = '    Message: ' . show($row['payment_message']);
    }
    $out[] = '';

    $subject = ($report['subject'] ?? 'Scout Accounting weekly Interac report') . ($findings ? " ({$findings} finding(s))" : ' (no findings)');
    $headers = [
        'From: ' . $report['from'],
        'Reply-To: ' . $report['from'],
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if (!mail((string)$report['to'], $subject, implode(PHP_EOL, $out), implode("\r\n", $headers))) {
        throw new RuntimeException('PHP mail() returned false while sending the weekly report.');
    }
    $message = sprintf("[%s] Weekly report sent to %s. Payments=%d Findings=%d\n", date('Y-m-d H:i:s'), $report['to'], (int)$week['n'], $findings);
    echo $message;
    error_log($message, 3, $logFile);
} catch (Throwable $e) {
    $message = sprintf("[%s] WEEKLY REPORT FATAL: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
    fwrite(STDERR, $message);
    error_log($message, 3, $logFile);
    exit(1);
}
