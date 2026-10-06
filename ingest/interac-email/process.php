<?php

/**
 * Interac Email Processor
 * Read incoming Interac e-Transfer emails from an IMAP mailbox, parse them, and write
 * the results to the inbox_observations table for later processing.
 */

$logDir = __DIR__ . '/logs';
$logFile = $logDir . '/process.log';

if (!is_dir($logDir)) {
    if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        fwrite(STDERR, "Unable to create log directory: {$logDir}\n");
        exit(1);
    }
}

$imap = null;
$pdo = null;
$batchId = null;

try {
    $config = require dirname(__DIR__) . '/bootstrap.php';

    /* Database */
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
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    /* Load processing state */
    $stmt = $pdo->prepare(
        'SELECT state_value
         FROM processor_state
         WHERE state_key = ?'
    );
    $stmt->execute(['last_processed_uid']);
    $stateValue = $stmt->fetchColumn();

    if ($stateValue === false) {
        throw new RuntimeException(
            'Missing processor_state row for last_processed_uid.'
        );
    }

    $lastProcessedUid = (int) $stateValue;

    /* Reusable database statements */

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
            payload_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            import_batch_id = VALUES(import_batch_id),
            transaction_date = VALUES(transaction_date),
            amount = VALUES(amount),
            direction = VALUES(direction),
            description = VALUES(description),
            payments_reference_number = VALUES(payments_reference_number),
            payload_json = VALUES(payload_json)'
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

    $updateState = $pdo->prepare(
        'UPDATE processor_state
         SET state_value = ?
         WHERE state_key = ?'
    );

    /* Create one inbox batch for this processor run. */
    $insertBatch->execute([
        'interac_email',
        null,
        'IMAP processor run started at ' . date('Y-m-d H:i:s'),
    ]);
    $batchId = (int) $pdo->lastInsertId();

    /* IMAP connection */
    $mailbox = sprintf(
        '{%s:%d/imap/ssl}INBOX',
        $config['imap']['host'],
        $config['imap']['port']
    );

    $imap = imap_open(
        $mailbox,
        $config['imap']['user'],
        $config['imap']['password']
    );

    if (!$imap) {
        throw new RuntimeException(
            'IMAP connection failed: ' . imap_last_error()
        );
    }

    $totalMessages = imap_num_msg($imap);

    echo "Mailbox contains {$totalMessages} messages\n";
    echo "Last processed UID: {$lastProcessedUid}\n\n";

    $processed = 0;
    $duplicates = 0;
    $skipped = 0;
    $observationsWritten = 0;

    /* Process messages with UIDs greater than the stored UID. */
    for ($msgNum = 1; $msgNum <= $totalMessages; $msgNum++) {
        $uid = imap_uid($imap, $msgNum);

        if ($uid === false || $uid <= 0) {
            throw new RuntimeException(
                "Unable to obtain IMAP UID for message sequence {$msgNum}."
            );
        }

        if ($uid <= $lastProcessedUid) {
            continue;
        }

        $overview = imap_fetch_overview($imap, $msgNum, 0);
        if (empty($overview)) {
            throw new RuntimeException(
                "Unable to fetch overview for IMAP UID {$uid}."
            );
        }

        $subject = $overview[0]->subject ?? '';

        /* Classify candidate incoming payment. */
        $isInteracMessage =
            stripos($subject, 'Interac e-Transfer') !== false;

        $isSectionIncome =
            $isInteracMessage &&
            preg_match('/^\[.*?\s*-?\s*income\]/i', $subject) === 1;

        $isDirectAdminIncome =
            $isInteracMessage &&
            stripos($subject, "You've received") !== false &&
            stripos($subject, 'automatically deposited') !== false;

        if (!$isSectionIncome && !$isDirectAdminIncome) {
            $skipped++;
            $updateState->execute([$uid, 'last_processed_uid']);
            $lastProcessedUid = $uid;
            continue;
        }

        /* Fetch message body. */
        $body = imap_fetchbody($imap, $msgNum, 1);
        if ($body === false || trim($body) === '') {
            $body = imap_body($imap, $msgNum);
        }
        if ($body === false || trim($body) === '') {
            throw new RuntimeException(
                "Unable to retrieve message body for IMAP UID {$uid}."
            );
        }

        /* Routing category. */
        if ($isDirectAdminIncome && !$isSectionIncome) {
            $section = 'Admin';
        } else {
            preg_match('/^\[(.*?)\]/', $subject, $matches);
            $section = trim($matches[1] ?? '');
            $section = preg_replace(
                '/\s*-\s*income$|\s+income$/i',
                '',
                $section
            );
            $section = trim($section);
        }

        if (strcasecmp($section, 'Venturers') === 0) {
            $section = 'Sea Venturers';
        }

        /* Payer. */
        preg_match('/Sent From:\s*(.+?)\s*Amount:/si', $body, $matches);
        $payer = trim($matches[1] ?? '');

        /* Amount. */
        preg_match('/Amount:\s*\$([0-9,.]+)/i', $body, $matches);
        $amount = str_replace(',', '', trim($matches[1] ?? ''));

        /* Interac reference number. */
        preg_match('/Reference Number:\s*([A-Za-z0-9]+)/i', $body, $matches);
        $reference = trim($matches[1] ?? '');

        /* Payment date. */
        preg_match('/Date:\s*(.*?)\s*Reference Number:/si', $body, $matches);
        $paymentDate = trim($matches[1] ?? '');

        /* Payment message. */
        preg_match('/Message:\s*(.*?)\s*Date:/si', $body, $matches);
        $memo = trim($matches[1] ?? '');

        /* Confirm incoming deposit from body. */
        $isConfirmedIncomingDeposit =
            stripos($body, 'Funds Deposited!') !== false &&
            stripos(
                $body,
                'Your funds have been automatically deposited'
            ) !== false &&
            stripos($body, 'Sent From:') !== false &&
            stripos($body, 'Reference Number:') !== false;

        if (!$isConfirmedIncomingDeposit) {
            throw new RuntimeException(
                sprintf(
                    'Candidate Interac income message did not pass body validation. ' .
                    'IMAP UID: %d. Subject: %s',
                    $uid,
                    $subject
                )
            );
        }

        /* Validate required fields. */
        $missingFields = [];
        if ($section === '') {
            $missingFields[] = 'section';
        }
        if ($payer === '') {
            $missingFields[] = 'payer';
        }
        if ($amount === '' || !is_numeric($amount)) {
            $missingFields[] = 'amount';
        }
        if ($reference === '') {
            $missingFields[] = 'reference number';
        }
        if ($paymentDate === '') {
            $missingFields[] = 'payment date';
        }

        if (!empty($missingFields)) {
            throw new RuntimeException(
                sprintf(
                    'Unable to parse IMAP UID %d. Missing or invalid: %s. Subject: %s',
                    $uid,
                    implode(', ', $missingFields),
                    $subject
                )
            );
        }

        /* Convert payment date. */
        $normalizedPaymentDate = str_replace('Sept', 'Sep', $paymentDate);
        $dateObject = DateTime::createFromFormat(
            '!M j, Y',
            $normalizedPaymentDate
        );
        $dateErrors = DateTime::getLastErrors();

        if (
            $dateObject === false ||
            (
                is_array($dateErrors) &&
                (
                    $dateErrors['warning_count'] > 0 ||
                    $dateErrors['error_count'] > 0
                )
            )
        ) {
            throw new RuntimeException(
                sprintf(
                    'Unable to parse payment date "%s" for IMAP UID %d.',
                    $paymentDate,
                    $uid
                )
            );
        }

        $sqlDate = $dateObject->format('Y-m-d');

        /*
         * Preserve parsed source evidence in the inbox payload.
         * JSON_THROW_ON_ERROR prevents silently storing malformed JSON.
         */
        $payload = json_encode(
            [
                'reference_number' => $reference,
                'section' => $section,
                'payer_name' => $payer,
                'amount' => number_format((float) $amount, 2, '.', ''),
                'payment_message' => $memo,
                'payment_date' => $sqlDate,
                'imap_uid' => $uid,
                'raw_subject' => $subject,
                'source_mailbox' => $config['imap']['user'],
            ],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        );

        $sourceKey = 'interac_email:' . $reference;

        /*
         * Write inbox observation.
         * Duplicate detection is now based entirely on
         * (source_system, source_key) in inbox_observations.
         */
        $pdo->beginTransaction();

        try {

            $insertObservation->execute([
                $batchId,
                'interac_email',
                $sourceKey,
                $sqlDate,
                $amount,
                'income',
                $memo !== '' ? $memo : $subject,
                $reference,
                $payload,
            ]);

            $updateState->execute([
                $uid,
                'last_processed_uid'
            ]);

            $pdo->commit();

            $lastProcessedUid = $uid;
            $observationsWritten++;

            if ($insertObservation->rowCount() === 1) {

                $processed++;

                echo sprintf(
                    "Imported inbox observation: %s (%s) %s [UID %d]\n",
                    $reference,
                    $section,
                    $amount,
                    $uid
                );

            } else {

                $duplicates++;

                echo sprintf(
                    "Refreshed inbox observation: %s [UID %d]\n",
                    $reference,
                    $uid
                );

            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    imap_close($imap);
    $imap = null;

    /* Finalize batch and log success summary. */
    $batchNotes = sprintf(
        'Completed. Observations imported=%d; duplicates=%d; skipped=%d; observations written=%d; last UID=%d.',
        $processed,
        $duplicates,
        $skipped,
        $observationsWritten,
        $lastProcessedUid
    );

    $updateBatch->execute([
        $observationsWritten,
        0,
        $batchNotes,
        $batchId,
    ]);

    $summary = sprintf(
        "[%s] Processed=%d Duplicates=%d Skipped=%d Observations=%d BatchID=%d LastUID=%d\n",
        date('Y-m-d H:i:s'),
        $processed,
        $duplicates,
        $skipped,
        $observationsWritten,
        $batchId,
        $lastProcessedUid
    );

    echo "\n";
    echo $summary;
    error_log($summary, 3, $logFile);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($imap !== null) {
        imap_close($imap);
    }

    if ($pdo instanceof PDO && $batchId !== null) {
        try {
            $failureNotes = 'FAILED: ' . $e->getMessage();
            $failureUpdate = $pdo->prepare(
                'UPDATE inbox_import_batches
                 SET rows_rejected = rows_rejected + 1,
                     notes = ?
                 WHERE batch_id = ?'
            );
            $failureUpdate->execute([$failureNotes, $batchId]);
        } catch (Throwable $ignored) {
            /* Preserve the original failure. */
        }
    }

    $errorMessage = sprintf(
        "[%s] FATAL: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    );

    fwrite(STDERR, $errorMessage);
    error_log($errorMessage, 3, $logFile);
    exit(1);
}
