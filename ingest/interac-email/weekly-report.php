<?php

/*
|--------------------------------------------------------------------------
| 403TO Payment Importer Weekly Report
|--------------------------------------------------------------------------
|
| Produces a seven-day health and data-quality report covering:
|
| - Import counts and totals
| - Totals by routing category
| - Recent payment samples
| - Unknown routing categories
| - Missing or invalid required data
| - Suspicious payer names
| - Missing or unusually formatted payment messages
| - Suspicious dates
| - Unexpected subject formats
| - Fatal errors recorded in process.log
| - Current IMAP processing state
|
| Category rules:
|
| - "Sea Venturers" is the canonical category.
| - Existing "Venturers" records are reported as "Sea Venturers".
| - "Admin" is a valid non-section catch-all category.
|
*/

$logDir = __DIR__ . '/logs';
$logFile = $logDir . '/weekly-report.log';
$processorLog = $logDir . '/process.log';

if (!is_dir($logDir)) {
    if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        fwrite(
            STDERR,
            "Unable to create log directory: {$logDir}\n"
        );

        exit(1);
    }
}

try {
    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    $config = require __DIR__ . '/config/config.php';

    if (empty($config['report']['to'])) {
        throw new RuntimeException(
            'Missing report recipient in config.php.'
        );
    }

    if (empty($config['report']['from'])) {
        throw new RuntimeException(
            'Missing report sender in config.php.'
        );
    }

    $reportTo = $config['report']['to'];
    $reportFrom = $config['report']['from'];

    $reportSubject = $config['report']['subject']
        ?? '403TO Payment Importer Weekly Report';

    /*
     * Canonical routing categories.
     */
    $knownCategories = $config['report']['known_categories']
        ?? [
            'Beavers',
            'Cubs',
            'Sea Scouts',
            'Sea Venturers',
            'Admin',
        ];

    /*
     * Alternate values that should be reported under a canonical category.
     */
    $categoryAliases = $config['report']['category_aliases']
        ?? [
            'Venturers' => 'Sea Venturers',
        ];

    /*
     * Friendly labels for the report.
     */
    $categoryLabels = $config['report']['category_labels']
        ?? [
            'Beavers' => 'Beavers',
            'Cubs' => 'Cubs',
            'Sea Scouts' => 'Sea Scouts',
            'Sea Venturers' => 'Sea Venturers',
            'Admin' => 'Admin / non-section',
        ];

    /*
    |--------------------------------------------------------------------------
    | Reporting Period
    |--------------------------------------------------------------------------
    */

    $reportEnd = new DateTimeImmutable('now');
    $reportStart = $reportEnd->sub(
        new DateInterval('P7D')
    );

    $reportStartSql = $reportStart->format(
        'Y-m-d H:i:s'
    );

    $reportEndSql = $reportEnd->format(
        'Y-m-d H:i:s'
    );

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    */

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
            PDO::ATTR_ERRMODE
                => PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE
                => PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES
                => false,
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Helper Functions
    |--------------------------------------------------------------------------
    */

    function money(mixed $amount): string
    {
        return '$' . number_format(
            (float) $amount,
            2
        );
    }

    function displayValue(
        mixed $value,
        string $emptyValue = '[empty]'
    ): string {
        $value = trim((string) $value);

        return $value === ''
            ? $emptyValue
            : $value;
    }

    function canonicalCategory(
        string $category,
        array $aliases
    ): string {
        $category = trim($category);

        foreach ($aliases as $alias => $canonical) {
            if (strcasecmp($category, $alias) === 0) {
                return $canonical;
            }
        }

        return $category;
    }

    function categoryLabel(
        string $category,
        array $aliases,
        array $labels
    ): string {
        $canonical = canonicalCategory(
            $category,
            $aliases
        );

        return $labels[$canonical] ?? $canonical;
    }

    /*
    |--------------------------------------------------------------------------
    | Overall Database Health
    |--------------------------------------------------------------------------
    */

    $totalDatabasePayments = (int) $pdo
        ->query(
            'SELECT COUNT(*)
             FROM payments'
        )
        ->fetchColumn();

    $totalDatabaseAmount = (float) $pdo
        ->query(
            'SELECT COALESCE(SUM(amount), 0)
             FROM payments'
        )
        ->fetchColumn();

    $latestImport = $pdo
        ->query(
            'SELECT
                reference_number,
                section,
                payer_name,
                amount,
                payment_date,
                imported_at
             FROM payments
             ORDER BY imported_at DESC
             LIMIT 1'
        )
        ->fetch();

    /*
    |--------------------------------------------------------------------------
    | Weekly Totals
    |--------------------------------------------------------------------------
    */

    $weeklyTotalsStatement = $pdo->prepare(
        'SELECT
            COUNT(*) AS payment_count,
            COALESCE(SUM(amount), 0) AS total_amount,
            MIN(imported_at) AS first_import,
            MAX(imported_at) AS last_import
         FROM payments
         WHERE imported_at >= ?
           AND imported_at < ?'
    );

    $weeklyTotalsStatement->execute([
        $reportStartSql,
        $reportEndSql,
    ]);

    $weeklyTotals = $weeklyTotalsStatement->fetch();

    /*
    |--------------------------------------------------------------------------
    | Totals by Routing Category
    |--------------------------------------------------------------------------
    |
    | "Venturers" and "Sea Venturers" are grouped together under the
    | canonical "Sea Venturers" category.
    |
    | "Admin" remains a separate valid category representing organization-wide
    | or otherwise non-section payments.
    |
    */

    $categorySummaryStatement = $pdo->prepare(
        'SELECT
            CASE
                WHEN LOWER(TRIM(section)) IN (
                    \'venturers\',
                    \'sea venturers\'
                )
                THEN \'Sea Venturers\'

                WHEN LOWER(TRIM(section)) = \'admin\'
                THEN \'Admin\'

                ELSE TRIM(section)
            END AS category,

            COUNT(*) AS payment_count,
            COALESCE(SUM(amount), 0) AS total_amount

         FROM payments

         WHERE imported_at >= ?
           AND imported_at < ?

         GROUP BY
            CASE
                WHEN LOWER(TRIM(section)) IN (
                    \'venturers\',
                    \'sea venturers\'
                )
                THEN \'Sea Venturers\'

                WHEN LOWER(TRIM(section)) = \'admin\'
                THEN \'Admin\'

                ELSE TRIM(section)
            END

         ORDER BY category'
    );

    $categorySummaryStatement->execute([
        $reportStartSql,
        $reportEndSql,
    ]);

    $categorySummary =
        $categorySummaryStatement->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Recent Payment Samples
    |--------------------------------------------------------------------------
    */

    $recentPaymentsStatement = $pdo->prepare(
        'SELECT
            reference_number,
            section,
            payer_name,
            amount,
            payment_message,
            payment_date,
            imported_at
         FROM payments
         WHERE imported_at >= ?
           AND imported_at < ?
         ORDER BY imported_at DESC
         LIMIT 10'
    );

    $recentPaymentsStatement->execute([
        $reportStartSql,
        $reportEndSql,
    ]);

    $recentPayments =
        $recentPaymentsStatement->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Anomaly Query 1: Missing or Invalid Required Data
    |--------------------------------------------------------------------------
    */

    $invalidRequiredDataStatement = $pdo->prepare(
        'SELECT
            reference_number,
            section,
            payer_name,
            amount,
            payment_date,
            imported_at
         FROM payments
         WHERE imported_at >= ?
           AND imported_at < ?
           AND (
                reference_number IS NULL
                OR TRIM(reference_number) = \'\'
                OR section IS NULL
                OR TRIM(section) = \'\'
                OR payer_name IS NULL
                OR TRIM(payer_name) = \'\'
                OR amount IS NULL
                OR amount <= 0
                OR payment_date IS NULL
           )
         ORDER BY imported_at DESC'
    );

    $invalidRequiredDataStatement->execute([
        $reportStartSql,
        $reportEndSql,
    ]);

    $invalidRequiredData =
        $invalidRequiredDataStatement->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Anomaly Query 2: Unknown Routing Categories
    |--------------------------------------------------------------------------
    |
    | Valid raw values include:
    |
    | - Beavers
    | - Cubs
    | - Sea Scouts
    | - Venturers
    | - Sea Venturers
    | - Admin
    |
    | Venturers and Sea Venturers represent the same canonical category.
    | Admin is a valid catch-all for non-section payments.
    |
    */

    $unknownCategoryStatement = $pdo->prepare(
        'SELECT
            reference_number,
            section,
            payer_name,
            amount,
            raw_subject,
            imported_at

         FROM payments

         WHERE imported_at >= ?
           AND imported_at < ?

           AND LOWER(TRIM(section)) NOT IN (
                \'beavers\',
                \'cubs\',
                \'sea scouts\',
                \'venturers\',
                \'sea venturers\',
                \'admin\'
           )

         ORDER BY imported_at DESC'
    );

    $unknownCategoryStatement->execute([
        $reportStartSql,
        $reportEndSql,
    ]);

    $unknownCategories =
        $unknownCategoryStatement->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Anomaly Query 3: Suspicious Payer Names
    |--------------------------------------------------------------------------
    |
    | This query identifies payer names that may warrant visual review:
    |
    | - Empty payer names
    | - Names shorter than three characters
    | - Names longer than 150 characters
    | - Names containing digits
    | - Names containing control characters
    | - Names containing three or more consecutive whitespace characters
    |
    */

    $suspiciousPayerStatement = $pdo->prepare(
        'SELECT
            reference_number,
            section,
            payer_name,
            amount,
            payment_date,
            imported_at
         FROM payments
         WHERE imported_at >= ?
           AND imported_at < ?
           AND (
                payer_name IS NULL
                OR CHAR_LENGTH(TRIM(payer_name)) < 3
                OR CHAR_LENGTH(payer_name) > 150
                OR payer_name REGEXP \'[0-9]\'
                OR payer_name REGEXP \'[[:cntrl:]]\'
                OR payer_name REGEXP \'[[:space:]]{3,}\'
           )
         ORDER BY imported_at DESC'
    );

    $suspiciousPayerStatement->execute([
        $reportStartSql,
        $reportEndSql,
    ]);

    $suspiciousPayers =
        $suspiciousPayerStatement->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Anomaly Query 4: Suspicious Payment Messages
    |--------------------------------------------------------------------------
    |
    | A blank payment message is not necessarily an error. It is reported for
    | review because the message may later be needed for Wave categorization.
    |
    */

    $suspiciousMemoStatement = $pdo->prepare(
        'SELECT
            reference_number,
            section,
            payer_name,
            amount,
            payment_message,
            imported_at
         FROM payments
         WHERE imported_at >= ?
           AND imported_at < ?
           AND (
                payment_message IS NULL
                OR TRIM(payment_message) = \'\'
                OR CHAR_LENGTH(payment_message) > 1000
                OR payment_message REGEXP \'[[:cntrl:]]\'
           )
         ORDER BY imported_at DESC'
    );

    $suspiciousMemoStatement->execute([
        $reportStartSql,
        $reportEndSql,
    ]);

    $suspiciousMemos =
        $suspiciousMemoStatement->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Anomaly Query 5: Suspicious Payment Dates
    |--------------------------------------------------------------------------
    |
    | Flags:
    |
    | - Missing payment dates
    | - Payment dates more than one day in the future
    | - Payments imported more than 30 days after the payment date
    |
    | Delayed imports may be legitimate during historical ingestion.
    |
    */

    $suspiciousDateStatement = $pdo->prepare(
        'SELECT
            reference_number,
            section,
            payer_name,
            amount,
            payment_date,
            imported_at,
            DATEDIFF(
                DATE(imported_at),
                payment_date
            ) AS import_delay_days
         FROM payments
         WHERE imported_at >= ?
           AND imported_at < ?
           AND (
                payment_date IS NULL
                OR payment_date >
                   DATE_ADD(
                       CURDATE(),
                       INTERVAL 1 DAY
                   )
                OR DATEDIFF(
                       DATE(imported_at),
                       payment_date
                   ) > 30
           )
         ORDER BY imported_at DESC'
    );

    $suspiciousDateStatement->execute([
        $reportStartSql,
        $reportEndSql,
    ]);

    $suspiciousDates =
        $suspiciousDateStatement->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Anomaly Query 6: Unexpected Subject Formats
    |--------------------------------------------------------------------------
    |
    | Section-routed income messages should contain:
    |
    | - A bracketed routing prefix
    | - The word "income"
    | - "Interac e-Transfer"
    |
    | Admin catch-all income may eventually use an unprefixed subject. Current
    | Admin records should therefore not automatically be flagged solely for
    | lacking the bracketed "income" prefix.
    |
    */

    $unexpectedSubjectStatement = $pdo->prepare(
        'SELECT
            reference_number,
            section,
            raw_subject,
            imported_at

         FROM payments

         WHERE imported_at >= ?
           AND imported_at < ?

           AND (
                raw_subject IS NULL
                OR TRIM(raw_subject) = \'\'

                OR LOWER(raw_subject)
                   NOT LIKE \'%interac e-transfer%\'

                OR (
                    LOWER(TRIM(section)) <> \'admin\'
                    AND (
                        raw_subject NOT LIKE \'[%\'
                        OR LOWER(raw_subject)
                           NOT LIKE \'%income%\'
                    )
                )
           )

         ORDER BY imported_at DESC'
    );

    $unexpectedSubjectStatement->execute([
        $reportStartSql,
        $reportEndSql,
    ]);

    $unexpectedSubjects =
        $unexpectedSubjectStatement->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | Processing State
    |--------------------------------------------------------------------------
    */

    $lastUidStatement = $pdo->prepare(
        'SELECT state_value
         FROM processor_state
         WHERE state_key = ?'
    );

    $lastUidStatement->execute([
        'last_processed_uid',
    ]);

    $lastProcessedUid =
        $lastUidStatement->fetchColumn();

    if ($lastProcessedUid === false) {
        $lastProcessedUid = '[missing]';
    }

    /*
    |--------------------------------------------------------------------------
    | Recent Fatal Errors from process.log
    |--------------------------------------------------------------------------
    */

    $fatalErrors = [];

    if (is_readable($processorLog)) {
        $processorLogLines = file(
            $processorLog,
            FILE_IGNORE_NEW_LINES |
            FILE_SKIP_EMPTY_LINES
        );

        if ($processorLogLines !== false) {
            foreach ($processorLogLines as $line) {
                if (
                    stripos(
                        $line,
                        'FATAL:'
                    ) === false
                ) {
                    continue;
                }

                $matched = preg_match(
                    '/^\[([0-9]{4}-[0-9]{2}-[0-9]{2} ' .
                    '[0-9]{2}:[0-9]{2}:[0-9]{2})\]/',
                    $line,
                    $matches
                );

                if ($matched !== 1) {
                    $fatalErrors[] = $line;
                    continue;
                }

                $errorDate =
                    DateTimeImmutable::createFromFormat(
                        'Y-m-d H:i:s',
                        $matches[1]
                    );

                if (
                    $errorDate !== false &&
                    $errorDate >= $reportStart &&
                    $errorDate < $reportEnd
                ) {
                    $fatalErrors[] = $line;
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate Anomaly Count
    |--------------------------------------------------------------------------
    */

    $anomalyCount =
        count($invalidRequiredData) +
        count($unknownCategories) +
        count($suspiciousPayers) +
        count($suspiciousMemos) +
        count($suspiciousDates) +
        count($unexpectedSubjects) +
        count($fatalErrors);

    /*
    |--------------------------------------------------------------------------
    | Build Report
    |--------------------------------------------------------------------------
    */

    $reportLines = [];

    $reportLines[] =
        '403TO Payment Importer Weekly Report';

    $reportLines[] =
        str_repeat('=', 38);

    $reportLines[] = '';

    /*
     * Reporting period
     */

    $reportLines[] = 'Reporting period';
    $reportLines[] = '----------------';

    $reportLines[] =
        $reportStart->format('Y-m-d H:i:s') .
        ' to ' .
        $reportEnd->format('Y-m-d H:i:s');

    $reportLines[] = '';

    /*
     * Importer health
     */

    $reportLines[] = 'Importer health';
    $reportLines[] = '---------------';

    $reportLines[] =
        'Payments imported this period: ' .
        (int) (
            $weeklyTotals['payment_count']
            ?? 0
        );

    $reportLines[] =
        'Amount imported this period: ' .
        money(
            $weeklyTotals['total_amount']
            ?? 0
        );

    $reportLines[] =
        'Payments in database: ' .
        $totalDatabasePayments;

    $reportLines[] =
        'Total amount in database: ' .
        money($totalDatabaseAmount);

    $reportLines[] =
        'Last processed IMAP UID: ' .
        $lastProcessedUid;

    if ($latestImport) {
        $latestCategory = categoryLabel(
            $latestImport['section'],
            $categoryAliases,
            $categoryLabels
        );

        $reportLines[] = sprintf(
            'Latest import: %s | %s | %s | %s',
            $latestImport['imported_at'],
            $latestCategory,
            money($latestImport['amount']),
            $latestImport['reference_number']
        );
    } else {
        $reportLines[] =
            'Latest import: No payments found';
    }

    $reportLines[] = '';

    /*
     * Routing category summary
     */

    $reportLines[] =
        'Payments by routing category';

    $reportLines[] =
        '----------------------------';

    if (empty($categorySummary)) {
        $reportLines[] =
            'No payments were imported during this period.';
    } else {
        foreach ($categorySummary as $row) {
            $displayCategory = categoryLabel(
                $row['category'],
                $categoryAliases,
                $categoryLabels
            );

            $reportLines[] = sprintf(
                '%-24s %3d payment(s)  %10s',
                $displayCategory,
                $row['payment_count'],
                money($row['total_amount'])
            );
        }
    }

    $reportLines[] = '';

    /*
     * Anomaly summary
     */

    $reportLines[] = 'Anomaly summary';
    $reportLines[] = '---------------';

    $reportLines[] =
        'Total anomaly findings: ' .
        $anomalyCount;

    $reportLines[] =
        'Missing or invalid required data: ' .
        count($invalidRequiredData);

    $reportLines[] =
        'Unknown routing categories: ' .
        count($unknownCategories);

    $reportLines[] =
        'Suspicious payer names: ' .
        count($suspiciousPayers);

    $reportLines[] =
        'Empty memo fields: ' .
        count($suspiciousMemos);

    $reportLines[] =
        'Suspicious dates: ' .
        count($suspiciousDates);

    $reportLines[] =
        'Unexpected subject formats: ' .
        count($unexpectedSubjects);

    $reportLines[] =
        'Fatal processor errors: ' .
        count($fatalErrors);

    $reportLines[] = '';

    /*
     * Missing or invalid required data details
     */

    if (!empty($invalidRequiredData)) {
        $reportLines[] =
            'Missing or invalid required data';

        $reportLines[] =
            '--------------------------------';

        foreach ($invalidRequiredData as $row) {
            $displayCategory = categoryLabel(
                (string) $row['section'],
                $categoryAliases,
                $categoryLabels
            );

            $reportLines[] = sprintf(
                '%s | %s | %s | %s | Date: %s',
                displayValue(
                    $row['reference_number']
                ),
                displayValue(
                    $displayCategory
                ),
                displayValue(
                    $row['payer_name']
                ),
                money($row['amount']),
                displayValue(
                    $row['payment_date']
                )
            );
        }

        $reportLines[] = '';
    }

    /*
     * Unknown routing category details
     */

    if (!empty($unknownCategories)) {
        $reportLines[] =
            'Unknown routing categories';

        $reportLines[] =
            '--------------------------';

        foreach ($unknownCategories as $row) {
            $reportLines[] = sprintf(
                '%s | Category: %s | %s | %s',
                $row['reference_number'],
                displayValue(
                    $row['section']
                ),
                displayValue(
                    $row['payer_name']
                ),
                money($row['amount'])
            );

            $reportLines[] =
                '    Subject: ' .
                displayValue(
                    $row['raw_subject']
                );
        }

        $reportLines[] = '';
    }

    /*
     * Suspicious payer details
     */

    if (!empty($suspiciousPayers)) {
        $reportLines[] =
            'Suspicious payer names';

        $reportLines[] =
            '----------------------';

        foreach ($suspiciousPayers as $row) {
            $displayCategory = categoryLabel(
                (string) $row['section'],
                $categoryAliases,
                $categoryLabels
            );

            $reportLines[] = sprintf(
                '%s | %s | Payer: %s | %s',
                $row['reference_number'],
                displayValue(
                    $displayCategory
                ),
                displayValue(
                    $row['payer_name']
                ),
                money($row['amount'])
            );
        }

        $reportLines[] = '';
    }

    /*
     * Empty payment-message details
     */

    if (!empty($suspiciousMemos)) {
        $reportLines[] =
            'Empty payment messages';

        $reportLines[] =
            '---------------------------';

        foreach ($suspiciousMemos as $row) {
            $displayCategory = categoryLabel(
                (string) $row['section'],
                $categoryAliases,
                $categoryLabels
            );

            $reportLines[] = sprintf(
                '%s | %s | %s | %s',
                $row['reference_number'],
                displayValue(
                    $displayCategory
                ),
                displayValue(
                    $row['payer_name']
                ),
                money($row['amount'])
            );

            $reportLines[] =
                '    Memo: ' .
                displayValue(
                    $row['payment_message']
                );
        }

        $reportLines[] = '';
    }

    /*
     * Suspicious date details
     */

    if (!empty($suspiciousDates)) {
        $reportLines[] =
            'Suspicious payment dates';

        $reportLines[] =
            '------------------------';

        foreach ($suspiciousDates as $row) {
            $reportLines[] = sprintf(
                '%s | Payment: %s | Imported: %s | Delay: %s day(s)',
                $row['reference_number'],
                displayValue(
                    $row['payment_date']
                ),
                displayValue(
                    $row['imported_at']
                ),
                displayValue(
                    $row['import_delay_days']
                )
            );
        }

        $reportLines[] = '';
    }

    /*
     * Unexpected subject details
     */

    if (!empty($unexpectedSubjects)) {
        $reportLines[] =
            'Unexpected subject formats';

        $reportLines[] =
            '--------------------------';

        foreach ($unexpectedSubjects as $row) {
            $displayCategory = categoryLabel(
                (string) $row['section'],
                $categoryAliases,
                $categoryLabels
            );

            $reportLines[] =
                $row['reference_number'] .
                ' | ' .
                displayValue(
                    $displayCategory
                );

            $reportLines[] =
                '    Subject: ' .
                displayValue(
                    $row['raw_subject']
                );
        }

        $reportLines[] = '';
    }

    /*
     * Fatal processor errors
     */

    if (!empty($fatalErrors)) {
        $reportLines[] =
            'Fatal processor errors';

        $reportLines[] =
            '----------------------';

        foreach ($fatalErrors as $errorLine) {
            $reportLines[] = $errorLine;
        }

        $reportLines[] = '';
    }

    /*
     * Recent payment samples
     */

    $reportLines[] =
        'Recent payment samples';

    $reportLines[] =
        '----------------------';

    if (empty($recentPayments)) {
        $reportLines[] =
            'No payments were imported during this period.';
    } else {
        foreach ($recentPayments as $row) {
            $displayCategory = categoryLabel(
                (string) $row['section'],
                $categoryAliases,
                $categoryLabels
            );

            $reportLines[] = sprintf(
                '%s | %s | %s | %s | Ref: %s',
                displayValue(
                    $row['payment_date']
                ),
                $displayCategory,
                displayValue(
                    $row['payer_name']
                ),
                money($row['amount']),
                $row['reference_number']
            );

            $reportLines[] =
                '    Memo: ' .
                displayValue(
                    $row['payment_message']
                );
        }
    }

    $reportLines[] = '';

    /*
     * Review prompts
     */

    $reportLines[] =
        'Questions for review';

    $reportLines[] =
        '--------------------';

    $reportLines[] =
        '[ ] Do the recent payment samples match the Interac emails?';

    $reportLines[] =
        '[ ] Do payer names, dates, amounts, and messages look correctly parsed?';

    $reportLines[] =
        '[ ] Have any new or changed routing prefixes appeared?';

    $reportLines[] =
        '[ ] Do Venturer payments appear under Sea Venturers?';

    $reportLines[] =
        '[ ] Do Admin / non-section payments appear and look properly parsed?';

    $reportLines[] =
        '[ ] Were any expected deposits missing from the report?';

    $reportLines[] =
        '[ ] Is the importer ready for Wave integration planning?';

    $reportLines[] = '';

    /*
     * Project reminder
     */

    $reportLines[] =
        'Project reminder';

    $reportLines[] =
        '----------------';

    $reportLines[] =
        'Return to the 403TO Payment Importer project this week.';

    $reportLines[] =
        'Current version: v0.1';

    $reportLines[] =
        'Suggested next phase: verify reliability, then plan Wave export.';

    $reportLines[] = '';

    $reportBody = implode(
        PHP_EOL,
        $reportLines
    );

    /*
    |--------------------------------------------------------------------------
    | Send Email Report
    |--------------------------------------------------------------------------
    */

    $subjectSuffix = $anomalyCount > 0
        ? " ({$anomalyCount} finding(s))"
        : ' (no anomalies)';

    $emailSubject =
        $reportSubject .
        $subjectSuffix;

    $headers = [
        'From: ' . $reportFrom,
        'Reply-To: ' . $reportFrom,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    $mailSent = mail(
        $reportTo,
        $emailSubject,
        $reportBody,
        implode("\r\n", $headers)
    );

    if (!$mailSent) {
        throw new RuntimeException(
            'PHP mail() returned false while sending the weekly report.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Success Logging
    |--------------------------------------------------------------------------
    */

    $successMessage = sprintf(
        "[%s] Weekly report sent to %s. Payments=%d Anomalies=%d\n",
        date('Y-m-d H:i:s'),
        $reportTo,
        (int) (
            $weeklyTotals['payment_count']
            ?? 0
        ),
        $anomalyCount
    );

    echo $successMessage;

    error_log(
        $successMessage,
        3,
        $logFile
    );
} catch (Throwable $e) {
    $errorMessage = sprintf(
        "[%s] WEEKLY REPORT FATAL: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    );

    fwrite(
        STDERR,
        $errorMessage
    );

    error_log(
        $errorMessage,
        3,
        $logFile
    );

    exit(1);
}
