<?php
/**
 * Interac e-Transfer importer (run hourly from cron).
 *
 * Reads Interac deposit notifications from an IMAP mailbox and records each payment in
 * inbox_observations as evidence for matching to bank deposits. Continues from the last
 * processed IMAP UID stored in processor_state.
 *
 * Section routing: subjects like "[Cubs income] Interac e-Transfer ..." are recorded under
 * "Cubs". Direct deposits without a prefix use interac.default_section from config.php.
 */

declare(strict_types=1);

$logDir = __DIR__ . '/logs';
$logFile = $logDir . '/process.log';
if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
    fwrite(STDERR, "Unable to create log directory: {$logDir}\n");
    exit(1);
}

$imap = null;
$pdo = null;
$batchId = null;

try {
    [$config, $pdo] = require dirname(__DIR__) . '/bootstrap.php';
    $defaultSection = (string)($config['interac']['default_section'] ?? 'Admin');
    $sectionAliases = (array)($config['interac']['section_aliases'] ?? []);

    $state = $pdo->prepare('SELECT state_value FROM processor_state WHERE state_key = ?');
    $state->execute(['last_processed_uid']);
    $stateValue = $state->fetchColumn();
    if ($stateValue === false) {
        throw new RuntimeException('Missing processor_state row for last_processed_uid.');
    }
    $lastProcessedUid = (int)$stateValue;

    $insertObservation = $pdo->prepare(
        'INSERT INTO inbox_observations
            (import_batch_id, source_system, source_key, transaction_date, amount, direction, description, payments_reference_number, payload_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            import_batch_id = VALUES(import_batch_id),
            transaction_date = VALUES(transaction_date),
            amount = VALUES(amount),
            direction = VALUES(direction),
            description = VALUES(description),
            payments_reference_number = VALUES(payments_reference_number),
            payload_json = VALUES(payload_json)'
    );
    $updateState = $pdo->prepare('UPDATE processor_state SET state_value = ? WHERE state_key = ?');

    /* One import batch per run. */
    $pdo->prepare('INSERT INTO inbox_import_batches (source_system, source_filename, rows_imported, rows_rejected, notes) VALUES (?, NULL, 0, 0, ?)')
        ->execute(['interac_email', 'IMAP run started at ' . date('Y-m-d H:i:s')]);
    $batchId = (int)$pdo->lastInsertId();

    $imap = imap_open(
        sprintf('{%s:%d/imap/ssl}INBOX', $config['imap']['host'], $config['imap']['port']),
        $config['imap']['user'],
        $config['imap']['password']
    );
    if (!$imap) {
        throw new RuntimeException('IMAP connection failed: ' . imap_last_error());
    }

    $totalMessages = imap_num_msg($imap);
    echo "Mailbox contains {$totalMessages} messages\n";
    echo "Last processed UID: {$lastProcessedUid}\n\n";

    $imported = 0;
    $refreshed = 0;
    $skipped = 0;

    for ($msgNum = 1; $msgNum <= $totalMessages; $msgNum++) {
        $uid = imap_uid($imap, $msgNum);
        if ($uid === false || $uid <= 0) {
            throw new RuntimeException("Unable to obtain IMAP UID for message sequence {$msgNum}.");
        }
        if ($uid <= $lastProcessedUid) {
            continue;
        }
        $overview = imap_fetch_overview($imap, (string)$msgNum, 0);
        if (empty($overview)) {
            throw new RuntimeException("Unable to fetch overview for IMAP UID {$uid}.");
        }
        $subject = $overview[0]->subject ?? '';

        /* Is this an incoming payment? */
        $isInterac = stripos($subject, 'Interac e-Transfer') !== false;
        $isSectionIncome = $isInterac && preg_match('/^\[.*?\s*-?\s*income\]/i', $subject) === 1;
        $isDirectIncome = $isInterac
            && stripos($subject, "You've received") !== false
            && stripos($subject, 'automatically deposited') !== false;
        if (!$isSectionIncome && !$isDirectIncome) {
            $skipped++;
            $updateState->execute([$uid, 'last_processed_uid']);
            $lastProcessedUid = $uid;
            continue;
        }

        $body = imap_fetchbody($imap, $msgNum, '1');
        if ($body === false || trim($body) === '') {
            $body = imap_body($imap, $msgNum);
        }
        if ($body === false || trim($body) === '') {
            throw new RuntimeException("Unable to retrieve message body for IMAP UID {$uid}.");
        }

        /* Routing section. */
        if ($isSectionIncome) {
            preg_match('/^\[(.*?)\]/', $subject, $m);
            $section = trim(preg_replace('/\s*-\s*income$|\s+income$/i', '', trim($m[1] ?? '')));
        } else {
            $section = $defaultSection;
        }
        foreach ($sectionAliases as $alias => $canonical) {
            if (strcasecmp($section, (string)$alias) === 0) {
                $section = (string)$canonical;
            }
        }

        preg_match('/Sent From:\s*(.+?)\s*Amount:/si', $body, $m);
        $payer = trim($m[1] ?? '');
        preg_match('/Amount:\s*\$([0-9,.]+)/i', $body, $m);
        $amount = str_replace(',', '', trim($m[1] ?? ''));
        preg_match('/Reference Number:\s*([A-Za-z0-9]+)/i', $body, $m);
        $reference = trim($m[1] ?? '');
        preg_match('/Date:\s*(.*?)\s*Reference Number:/si', $body, $m);
        $paymentDate = trim($m[1] ?? '');
        preg_match('/Message:\s*(.*?)\s*Date:/si', $body, $m);
        $memo = trim($m[1] ?? '');

        $isDeposit = stripos($body, 'Funds Deposited!') !== false
            && stripos($body, 'Your funds have been automatically deposited') !== false
            && stripos($body, 'Sent From:') !== false
            && stripos($body, 'Reference Number:') !== false;
        if (!$isDeposit) {
            throw new RuntimeException(sprintf('Candidate Interac message did not pass body validation. IMAP UID: %d. Subject: %s', $uid, $subject));
        }

        $missing = [];
        foreach (['section' => $section, 'payer' => $payer, 'reference number' => $reference, 'payment date' => $paymentDate] as $label => $value) {
            if ($value === '') {
                $missing[] = $label;
            }
        }
        if ($amount === '' || !is_numeric($amount)) {
            $missing[] = 'amount';
        }
        if ($missing) {
            throw new RuntimeException(sprintf('Unable to parse IMAP UID %d. Missing or invalid: %s. Subject: %s', $uid, implode(', ', $missing), $subject));
        }

        $date = DateTime::createFromFormat('!M j, Y', str_replace('Sept', 'Sep', $paymentDate));
        $dateErrors = DateTime::getLastErrors();
        if ($date === false || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new RuntimeException(sprintf('Unable to parse payment date "%s" for IMAP UID %d.', $paymentDate, $uid));
        }
        $sqlDate = $date->format('Y-m-d');

        $payload = json_encode([
            'reference_number' => $reference,
            'section' => $section,
            'payer_name' => $payer,
            'amount' => number_format((float)$amount, 2, '.', ''),
            'payment_message' => $memo,
            'payment_date' => $sqlDate,
            'imap_uid' => $uid,
            'raw_subject' => $subject,
            'source_mailbox' => $config['imap']['user'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $pdo->beginTransaction();
        try {
            $insertObservation->execute([
                $batchId,
                'interac_email',
                'interac_email:' . $reference,
                $sqlDate,
                $amount,
                'income',
                $memo !== '' ? $memo : $subject,
                $reference,
                $payload,
            ]);
            $isNew = $insertObservation->rowCount() === 1;
            $updateState->execute([$uid, 'last_processed_uid']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $lastProcessedUid = $uid;
        if ($isNew) {
            $imported++;
            printf("Imported inbox observation: %s (%s) %s [UID %d]\n", $reference, $section, $amount, $uid);
        } else {
            $refreshed++;
            printf("Refreshed inbox observation: %s [UID %d]\n", $reference, $uid);
        }
    }

    imap_close($imap);
    $imap = null;

    $pdo->prepare('UPDATE inbox_import_batches SET rows_imported = ?, rows_rejected = 0, notes = ? WHERE batch_id = ?')->execute([
        $imported + $refreshed,
        sprintf('Completed. Observations imported=%d; duplicates=%d; skipped=%d; last UID=%d.', $imported, $refreshed, $skipped, $lastProcessedUid),
        $batchId,
    ]);

    $summary = sprintf(
        "[%s] Processed=%d Duplicates=%d Skipped=%d Observations=%d BatchID=%d LastUID=%d\n",
        date('Y-m-d H:i:s'),
        $imported,
        $refreshed,
        $skipped,
        $imported + $refreshed,
        $batchId,
        $lastProcessedUid
    );
    echo "\n" . $summary;
    error_log($summary, 3, $logFile);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($imap) {
        imap_close($imap);
    }
    if ($pdo instanceof PDO && $batchId !== null) {
        try {
            $pdo->prepare('UPDATE inbox_import_batches SET rows_rejected = rows_rejected + 1, notes = ? WHERE batch_id = ?')
                ->execute(['FAILED: ' . $e->getMessage(), $batchId]);
        } catch (Throwable $ignored) {
            // Keep the original failure.
        }
    }
    $message = sprintf("[%s] FATAL: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
    fwrite(STDERR, $message);
    error_log($message, 3, $logFile);
    exit(1);
}
