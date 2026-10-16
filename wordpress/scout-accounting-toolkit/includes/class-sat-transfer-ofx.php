<?php
/**
 * Frozen transfer-only OFX batches and their Transfers to Wave inbox view.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Transfer_OFX
{
    private const NONCE = 'sat_transfer_ofx';
    private const ACTION_CREATE = 'sat_transfer_ofx_create';
    private const ACTION_DOWNLOAD = 'sat_transfer_ofx_download';
    private const ACTION_CONFIRM = 'sat_transfer_ofx_confirm';
    private const ACTION_CANCEL = 'sat_transfer_ofx_cancel';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('admin_post_' . self::ACTION_CREATE, [$this, 'action_create']);
        add_action('admin_post_' . self::ACTION_DOWNLOAD, [$this, 'action_download']);
        add_action('admin_post_' . self::ACTION_CONFIRM, [$this, 'action_confirm']);
        add_action('admin_post_' . self::ACTION_CANCEL, [$this, 'action_cancel']);
    }

    private function db(): PDO
    {
        return SAT_DB::pdo();
    }

    private function guard(): void
    {
        if (!is_user_logged_in() || !current_user_can(SAT_CAP_REVIEW)) {
            wp_die('You do not have permission to manage transfer exports.', 'Scout Accounting', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);
    }

    private function message(string $type, string $text): void
    {
        $url = wp_get_referer();
        if (!$url) {
            $url = home_url('/');
        }
        $url = add_query_arg('sat_view', 'transfers', $url);
        wp_safe_redirect(add_query_arg([
            'sat_notice' => $type,
            'sat_message' => rawurlencode($text),
        ], $url));
        exit;
    }

    private static function clean_text(mixed $value, int $limit = 255): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)$value));
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
    }

    private static function payload(array $observation): array
    {
        $payload = json_decode((string)($observation['payload_json'] ?? ''), true);
        return is_array($payload) ? $payload : [];
    }

    private static function date_valid(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Validate and orient exactly one accepted bank-to-bank transfer.
     *
     * Returned source/destination are the negative/positive legs respectively.
     */
    public static function validate_pair(
        array $observations,
        array $banks,
        array $configured_accounts,
        array $linked_counts,
        array $reserved_observation_ids = []
    ): array {
        if (count($observations) !== 2) {
            throw new InvalidArgumentException('An accepted transfer must have exactly two observations.');
        }
        $first = $observations[0];
        $second = $observations[1];
        $first_id = (int)($first['observation_id'] ?? 0);
        $second_id = (int)($second['observation_id'] ?? 0);
        if ($first_id < 1 || $second_id < 1 || $first_id === $second_id) {
            throw new InvalidArgumentException('Transfer observations must be distinct.');
        }
        if ((int)($first['match_id'] ?? 0) < 1 || (int)$first['match_id'] !== (int)($second['match_id'] ?? 0)) {
            throw new InvalidArgumentException('Both observations must belong to the same accepted match.');
        }

        foreach ([$first, $second] as $observation) {
            $id = (int)$observation['observation_id'];
            if (($linked_counts[$id] ?? 0) !== 1) {
                throw new InvalidArgumentException('Each transfer observation must belong uniquely to this accepted match.');
            }
            if (in_array((string)($observation['review_status'] ?? ''), ['excluded', 'exported'], true)) {
                throw new InvalidArgumentException('Excluded or exported observations cannot enter a transfer batch.');
            }
            if (isset($reserved_observation_ids[$id])) {
                throw new InvalidArgumentException('A transfer observation already belongs to a non-cancelled batch.');
            }
            $source = (string)($observation['source_system'] ?? '');
            if ($source === '' || !isset($banks[$source])) {
                throw new InvalidArgumentException('Both observations must belong to configured SAT payment accounts.');
            }
        }

        $source_a = (string)$first['source_system'];
        $source_b = (string)$second['source_system'];
        if ($source_a === $source_b) {
            throw new InvalidArgumentException('Transfer observations must belong to different source systems.');
        }

        $leg_a = self::account_leg($first, $banks, $configured_accounts);
        $leg_b = self::account_leg($second, $banks, $configured_accounts);
        if ($leg_a['account']['acctid'] === $leg_b['account']['acctid']) {
            throw new InvalidArgumentException('Transfer observations must belong to different configured account IDs.');
        }
        if ($leg_a['account']['currency'] !== $leg_b['account']['currency']) {
            throw new InvalidArgumentException('Transfer account currencies do not match.');
        }
        $cents_a = SAT_OFX_Writer::amount_cents((string)($first['amount'] ?? ''));
        $cents_b = SAT_OFX_Writer::amount_cents((string)($second['amount'] ?? ''));
        if ($cents_a === 0 || $cents_a !== -$cents_b) {
            throw new InvalidArgumentException('Transfer amounts must be non-zero, equal and opposite to the cent.');
        }
        if ((int)($first['gf_entry_id'] ?? 0) > 0 || (int)($second['gf_entry_id'] ?? 0) > 0) {
            throw new InvalidArgumentException('Gravity Forms evidence matches are not transfers.');
        }

        if ($cents_a < 0) {
            $source = $leg_a;
            $destination = $leg_b;
        } else {
            $source = $leg_b;
            $destination = $leg_a;
        }
        return [
            'match_id' => (int)$first['match_id'],
            'source' => $source,
            'destination' => $destination,
            'amount_cents' => abs($cents_a),
            'currency' => $leg_a['account']['currency'],
        ];
    }

    private static function account_leg(array $observation, array $banks, array $configured_accounts): array
    {
        $source_system = (string)$observation['source_system'];
        $bank = $banks[$source_system] ?? null;
        if (!is_array($bank)) {
            throw new InvalidArgumentException('The source system is not a configured payment account.');
        }
        $account_id = (string)($bank['acctid'] ?? '');
        $configured = $configured_accounts[$account_id] ?? null;
        if ($account_id === '' || !is_array($configured) || (string)($configured['source_system'] ?? '') !== $source_system) {
            throw new InvalidArgumentException('A configured payment account ID is required for both observations.');
        }
        $payload = self::payload($observation);
        if ((string)($payload['account_id'] ?? '') !== $account_id
            || (string)($payload['statement_type'] ?? '') !== (string)($bank['type'] ?? '')) {
            throw new InvalidArgumentException('The observation account identity does not match its configured payment account.');
        }
        $currency = strtoupper(trim((string)($payload['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('A valid stored account currency is required.');
        }
        $date = (string)($observation['transaction_date'] ?? '');
        if (!self::date_valid($date)) {
            throw new InvalidArgumentException('A valid posting date is required for both transfer legs.');
        }
        $amount_cents = SAT_OFX_Writer::amount_cents((string)($observation['amount'] ?? ''));
        $metadata = [
            'source_system' => $source_system,
            'source_key' => (string)$observation['source_key'],
            'observation_id' => (int)$observation['observation_id'],
            'date' => $date,
            'amount' => self::cents_string($amount_cents),
            'description' => self::description($observation, $payload),
            'account' => [
                'type' => (string)$bank['type'],
                'label' => self::clean_text($bank['label']),
                'acctid' => $account_id,
                'currency' => $currency,
                'bank_id' => trim((string)($payload['bank_id'] ?? '')),
                'branch_id' => trim((string)($payload['branch_id'] ?? '')),
                'account_type' => trim((string)($payload['account_type'] ?? '')),
            ],
            'memo' => self::clean_text($payload['memo'] ?? ($observation['description'] ?? ''), 255),
            'payload' => $payload,
        ];
        if (!in_array($metadata['account']['type'], ['bank', 'credit_card'], true)) {
            throw new InvalidArgumentException('Configured account type must be bank or credit card.');
        }
        if ($metadata['account']['type'] === 'bank'
            && ($metadata['account']['bank_id'] === '' || $metadata['account']['branch_id'] === '' || $metadata['account']['account_type'] === '')) {
            throw new InvalidArgumentException('Stored bank ID, branch ID and account type are required for a bank account.');
        }
        if ($metadata['source_key'] === '') {
            throw new InvalidArgumentException('A source identity is required for each transfer leg.');
        }
        return $metadata;
    }

    private static function description(array $observation, array $payload): string
    {
        $parts = [];
        foreach ([$payload['name'] ?? '', $payload['memo'] ?? '', $observation['description'] ?? ''] as $value) {
            $value = self::clean_text($value, 100);
            if ($value === '') {
                continue;
            }
            $duplicate = false;
            foreach ($parts as $existing) {
                if (stripos($existing, $value) !== false || stripos($value, $existing) !== false) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $parts[] = $value;
            }
        }
        return self::clean_text(implode(' · ', array_slice($parts, 0, 2)), 160);
    }

    private function read_match(int $match_id, bool $lock = false, bool $ignore_reservations = false, bool $ignore_wave_preview = false): array
    {
        $db = $this->db();
        $suffix = $lock ? ' FOR UPDATE' : '';
        $q = $db->prepare('SELECT match_id, left_observation_id, right_observation_id FROM inbox_matches WHERE match_id = ?' . $suffix);
        $q->execute([$match_id]);
        $match = $q->fetch();
        if (!$match) {
            throw new InvalidArgumentException('Accepted match #' . $match_id . ' no longer exists.');
        }
        $ids = [(int)$match['left_observation_id'], (int)$match['right_observation_id']];
        if ($ids[0] === $ids[1]) {
            throw new InvalidArgumentException('The accepted match does not contain two distinct observations.');
        }
        sort($ids);
        $in = implode(',', array_fill(0, 2, '?'));
        $obs_q = $db->prepare(
            "SELECT o.*, r.review_status
             FROM inbox_observations o
             LEFT JOIN inbox_reviews r ON r.bank_observation_id = o.observation_id
             WHERE o.observation_id IN ($in)
             ORDER BY o.observation_id" . $suffix
        );
        $obs_q->execute($ids);
        $observations = $obs_q->fetchAll();
        if (count($observations) !== 2) {
            throw new InvalidArgumentException('Both observations for the accepted match must still exist.');
        }
        foreach ($observations as &$observation) {
            $observation['match_id'] = (int)$match['match_id'];
        }
        unset($observation);
        $linked_counts = [];
        $linked = $db->prepare(
            'SELECT COUNT(*) FROM inbox_matches
             WHERE left_observation_id = ? OR right_observation_id = ?'
        );
        foreach ($ids as $id) {
            $linked->execute([$id, $id]);
            $linked_counts[$id] = (int)$linked->fetchColumn();
        }
        $reserved = [];
        if (!$ignore_reservations) {
            $q = $db->prepare('SELECT observation_id FROM sat_transfer_ofx_reservations WHERE observation_id IN (?, ?)');
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $reserved[(int)$id] = true;
            }
        }
        if (!$ignore_wave_preview) {
            $wave = $db->prepare(
                'SELECT observation_id FROM sat_wave_export_sources WHERE observation_id IN (?, ?)'
            );
            $wave->execute($ids);
            if ($wave->fetchColumn()) {
                throw new InvalidArgumentException('A transfer observation already belongs to a Wave API preview.');
            }
        }
        return [$observations, $linked_counts, $reserved];
    }

    private function eligible(int $match_id, bool $lock = false, bool $ignore_reservations = false, bool $ignore_wave_preview = false): array
    {
        [$observations, $linked_counts, $reserved] = $this->read_match($match_id, $lock, $ignore_reservations, $ignore_wave_preview);
        return self::validate_pair(
            $observations,
            SAT_Settings::banks(),
            SAT_Settings::get('accounts'),
            $linked_counts,
            $reserved
        );
    }

    private function eligible_pairs(): array
    {
        $rows = $this->db()->query('SELECT match_id FROM inbox_matches ORDER BY match_id')->fetchAll(PDO::FETCH_COLUMN);
        $pairs = [];
        foreach ($rows as $id) {
            try {
                $pairs[] = $this->eligible((int)$id);
            } catch (InvalidArgumentException $e) {
                continue;
            }
        }
        return $pairs;
    }

    public function is_eligible_transfer_observation(int $observation_id): bool
    {
        $q = $this->db()->prepare(
            'SELECT match_id FROM inbox_matches
             WHERE left_observation_id = ? OR right_observation_id = ?
             ORDER BY match_id'
        );
        $q->execute([$observation_id, $observation_id]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $match_id) {
            try {
                $pair = $this->eligible((int)$match_id, false, true, true);
            } catch (InvalidArgumentException $e) {
                continue;
            }
            if ((int)$pair['source']['observation_id'] === $observation_id
                || (int)$pair['destination']['observation_id'] === $observation_id) {
                return true;
            }
        }
        return false;
    }

    public function matched_transfer_observation_ids(): array
    {
        $ids = [];
        $matches = $this->db()->query('SELECT match_id FROM inbox_matches ORDER BY match_id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($matches as $match_id) {
            try {
                $pair = $this->eligible((int)$match_id, false, true, true);
                $ids[(int)$pair['source']['observation_id']] = true;
                $ids[(int)$pair['destination']['observation_id']] = true;
            } catch (InvalidArgumentException $e) {
                continue;
            }
        }
        return $ids;
    }

    private static function cents_string(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function create_batch(array $selected_match_ids): int
    {
        $ids = [];
        foreach ($selected_match_ids as $selected_id) {
            if ((!is_int($selected_id) && !is_string($selected_id))
                || !ctype_digit((string)$selected_id)
                || (int)$selected_id < 1) {
                throw new RuntimeException('Invalid transfer selection.');
            }
            $ids[] = (int)$selected_id;
        }
        $unique_ids = array_values(array_unique($ids));
        $ids = $unique_ids;
        sort($ids);
        if (!$ids) {
            throw new RuntimeException('Select at least one accepted transfer.');
        }
        if (count($ids) !== count($selected_match_ids)) {
            throw new RuntimeException('Invalid or duplicate transfer selection.');
        }
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO sat_transfer_ofx_batches (batch_status, created_by)
                 VALUES ('generated', ?)"
            )->execute([get_current_user_id()]);
            $batch_id = (int)$db->lastInsertId();
            $insert_transfer = $db->prepare(
                'INSERT INTO sat_transfer_ofx_batch_matches
                 (batch_id, match_id, source_observation_id, destination_observation_id, source_label, destination_label,
                  amount, currency, source_date, destination_date, source_description, destination_description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert_leg = $db->prepare(
                'INSERT INTO sat_transfer_ofx_legs
                 (batch_match_id, observation_id, source_system, source_key, account_id, account_label, account_type,
                  bank_id, branch_id, bank_account_type, currency, transaction_date, amount, fitid, name, memo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert_reservation = $db->prepare(
                'INSERT INTO sat_transfer_ofx_reservations (observation_id, batch_id) VALUES (?, ?)'
            );
            $account_groups = [];
            foreach ($ids as $match_id) {
                $pair = $this->eligible($match_id, true);
                $source = $pair['source'];
                $destination = $pair['destination'];
                $pair_identity = [
                    ['source_system' => $source['source_system'], 'source_key' => $source['source_key']],
                    ['source_system' => $destination['source_system'], 'source_key' => $destination['source_key']],
                ];
                $insert_transfer->execute([
                    $batch_id,
                    $match_id,
                    $source['observation_id'],
                    $destination['observation_id'],
                    $source['account']['label'],
                    $destination['account']['label'],
                    self::cents_string($pair['amount_cents']),
                    $pair['currency'],
                    $source['date'],
                    $destination['date'],
                    $source['description'],
                    $destination['description'],
                ]);
                $batch_match_id = (int)$db->lastInsertId();
                foreach ([$source, $destination] as $leg) {
                    $is_source = (int)$leg['observation_id'] === (int)$source['observation_id'];
                    $other_label = $is_source ? $destination['account']['label'] : $source['account']['label'];
                    $amount_cents = SAT_OFX_Writer::amount_cents($leg['amount']);
                    $name = self::clean_text('Transfer ' . ($amount_cents < 0 ? 'to ' : 'from ') . $other_label, 32);
                    $memo = $leg['memo'] !== '' ? $leg['memo'] : ($leg['description'] !== '' ? $leg['description'] : $name);
                    $fitid = SAT_OFX_Writer::fitid_for_leg(
                        $pair_identity,
                        $leg['source_system'],
                        $leg['source_key'],
                        $leg['account']['acctid']
                    );
                    $account = $leg['account'];
                    $insert_leg->execute([
                        $batch_match_id,
                        $leg['observation_id'],
                        $leg['source_system'],
                        $leg['source_key'],
                        $account['acctid'],
                        $account['label'],
                        $account['type'],
                        $account['bank_id'],
                        $account['branch_id'],
                        $account['account_type'],
                        $account['currency'],
                        $leg['date'],
                        $leg['amount'],
                        $fitid,
                        $name,
                        self::clean_text($memo),
                    ]);
                    $insert_reservation->execute([$leg['observation_id'], $batch_id]);
                    $account_key = $account['acctid'];
                    $account_groups[$account_key] = $account;
                    $account_groups[$account_key]['source_system'] = $leg['source_system'];
                    $account_groups[$account_key]['transaction_count'] = ($account_groups[$account_key]['transaction_count'] ?? 0) + 1;
                }
            }
            $insert_file = $db->prepare(
                'INSERT INTO sat_transfer_ofx_batch_files
                 (batch_id, account_id, source_system, filename, transaction_count)
                 VALUES (?, ?, ?, ?, ?)'
            );
            ksort($account_groups, SORT_STRING);
            $account_number = 0;
            foreach ($account_groups as $account_id => $account) {
                $account_number++;
                $filename = sprintf('sat-transfer-batch-%d-account-%02d.ofx', $batch_id, $account_number);
                $insert_file->execute([
                    $batch_id,
                    $account_id,
                    $account['source_system'],
                    $filename,
                    $account['transaction_count'],
                ]);
            }
            $db->prepare(
                "UPDATE sat_transfer_ofx_batches SET generated_at = CURRENT_TIMESTAMP WHERE batch_id = ?"
            )->execute([$batch_id]);
            $db->commit();
            return $batch_id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                throw new RuntimeException('A selected transfer observation already belongs to a non-cancelled batch. Refresh the list and try again.', 0, $e);
            }
            throw $e;
        }
    }

    public static function transition_allowed(string $from, string $to): bool
    {
        return $from === 'generated' && in_array($to, ['confirmed', 'cancelled'], true);
    }

    public static function group_legs_by_account(array $legs): array
    {
        $grouped = [];
        foreach ($legs as $leg) {
            $account_id = trim((string)($leg['account_id'] ?? ''));
            if ($account_id === '') {
                throw new InvalidArgumentException('Every frozen leg must have an account identity.');
            }
            $grouped[$account_id][] = $leg;
        }
        ksort($grouped, SORT_STRING);
        return $grouped;
    }

    public static function add_bundle_entries(ZipArchive $zip, array $entries): void
    {
        foreach ($entries as $entry) {
            $filename = (string)($entry['filename'] ?? '');
            if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '..')
                || preg_match('/^[A-Za-z0-9._-]+$/D', $filename) !== 1) {
                throw new InvalidArgumentException('A transfer bundle entry has an unsafe filename.');
            }
            if (!$zip->addFromString($filename, (string)($entry['contents'] ?? ''))) {
                throw new RuntimeException('Could not add a frozen export file to the ZIP bundle.');
            }
            if (!$zip->setMtimeName($filename, 315532800)) {
                throw new RuntimeException('Could not set a deterministic timestamp on a ZIP entry.');
            }
        }
    }

    public function action_create(): void
    {
        $this->guard();
        try {
            $batch_id = $this->create_batch((array)($_POST['selected_matches'] ?? []));
            $this->message('success', 'Transfer OFX batch #' . $batch_id . ' generated. Download and import the account files, then match the transfers in Wave.');
        } catch (Throwable $e) {
            $this->message('error', $e->getMessage());
        }
    }

    private function account_files(int $batch_id): array
    {
        $q = $this->db()->prepare(
            'SELECT * FROM sat_transfer_ofx_batch_files WHERE batch_id = ? ORDER BY source_system, account_id'
        );
        $q->execute([$batch_id]);
        return $q->fetchAll();
    }

    private function frozen_legs(int $batch_id): array
    {
        $q = $this->db()->prepare(
            'SELECT l.*, bm.match_id
             FROM sat_transfer_ofx_legs l
             JOIN sat_transfer_ofx_batch_matches bm ON bm.batch_match_id = l.batch_match_id
             WHERE bm.batch_id = ?
             ORDER BY l.transaction_date, l.fitid'
        );
        $q->execute([$batch_id]);
        return $q->fetchAll();
    }

    private function transfers(int $batch_id): array
    {
        $q = $this->db()->prepare(
            'SELECT * FROM sat_transfer_ofx_batch_matches WHERE batch_id = ? ORDER BY match_id'
        );
        $q->execute([$batch_id]);
        return $q->fetchAll();
    }

    public static function manifest(int $batch_id, string $created_at, array $files, array $transfers): string
    {
        usort($files, static fn (array $a, array $b): int => strcmp((string)$a['filename'], (string)$b['filename']));
        usort($transfers, static fn (array $a, array $b): int => (int)$a['match_id'] <=> (int)$b['match_id']);
        $lines = [
            'Scout Accounting Toolkit - Transfer OFX batch',
            'Batch ID: ' . $batch_id,
            'Created: ' . $created_at,
            '',
            'Account files:',
        ];
        foreach ($files as $file) {
            $account_label = isset($file['account_label'])
                ? ' - ' . self::manifest_label((string)$file['account_label'])
                : '';
            $lines[] = sprintf(
                '  %s (%d transactions)%s',
                $file['filename'],
                (int)$file['transaction_count'],
                $account_label
            );
        }
        $lines[] = '';
        $lines[] = 'Transfers:';
        foreach ($transfers as $transfer) {
            $dates = $transfer['source_date'] === $transfer['destination_date']
                ? (string)$transfer['source_date']
                : $transfer['source_date'] . ' / ' . $transfer['destination_date'];
            $lines[] = sprintf(
                '  %s -> %s | %s %s | %s',
                self::manifest_label($transfer['source_label']),
                self::manifest_label($transfer['destination_label']),
                number_format((float)$transfer['amount'], 2, '.', ''),
                $transfer['currency'],
                $dates
            );
        }
        return implode("\n", $lines) . "\n";
    }

    private static function manifest_label(string $label): string
    {
        $label = self::clean_text($label);
        $redacted = preg_replace('/\b(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]+\b/u', '[account reference omitted]', $label);
        return self::clean_text($redacted ?? $label);
    }

    private function bundle_contents(int $batch_id): array
    {
        $q = $this->db()->prepare('SELECT * FROM sat_transfer_ofx_batches WHERE batch_id = ?');
        $q->execute([$batch_id]);
        $batch = $q->fetch();
        if (!$batch || !in_array($batch['batch_status'], ['generated', 'confirmed'], true)) {
            throw new RuntimeException('Only generated or confirmed transfer batches can be downloaded.');
        }
        $files = $this->account_files($batch_id);
        $legs = $this->frozen_legs($batch_id);
        if (!$files || !$legs) {
            throw new RuntimeException('The frozen transfer batch is incomplete.');
        }
        $by_account = self::group_legs_by_account($legs);
        $contents = [];
        $manifest_files = [];
        foreach ($files as $file) {
            $account_id = (string)$file['account_id'];
            $account_legs = $by_account[$account_id] ?? [];
            if (count($account_legs) !== (int)$file['transaction_count']) {
                throw new RuntimeException('Frozen account transaction count does not match its file record.');
            }
            $first = $account_legs[0];
            $account = [
                'type' => $first['account_type'],
                'label' => $first['account_label'],
                'acctid' => $first['account_id'],
                'currency' => $first['currency'],
                'bank_id' => $first['bank_id'],
                'branch_id' => $first['branch_id'],
                'account_type' => $first['bank_account_type'],
            ];
            $manifest_file = $file;
            $manifest_file['account_label'] = (string)$first['account_label'];
            $manifest_files[] = $manifest_file;
            $transactions = [];
            foreach ($account_legs as $leg) {
                $pair_q = $this->db()->prepare(
                    'SELECT source_system, source_key FROM sat_transfer_ofx_legs WHERE batch_match_id = ? ORDER BY observation_id'
                );
                $pair_q->execute([(int)$leg['batch_match_id']]);
                $pair = $pair_q->fetchAll();
                $transactions[] = [
                    'source_system' => $leg['source_system'],
                    'source_key' => $leg['source_key'],
                    'pair_identity' => $pair,
                    'date' => $leg['transaction_date'],
                    'amount' => $leg['amount'],
                    'fitid' => $leg['fitid'],
                    'name' => $leg['name'],
                    'memo' => $leg['memo'],
                ];
            }
            $contents[] = [
                'filename' => $file['filename'],
                'contents' => SAT_OFX_Writer::serialize_statement($account, $transactions),
            ];
        }
        if (count($by_account) !== count($files)) {
            throw new RuntimeException('Frozen account legs do not match the batch file records.');
        }
        $contents[] = [
            'filename' => 'manifest.txt',
            'contents' => self::manifest((int)$batch['batch_id'], (string)$batch['created_at'], $manifest_files, $this->transfers($batch_id)),
        ];
        return $contents;
    }

    public function write_bundle_for_batch(int $batch_id, string $path): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Could not open the private transfer bundle for writing.');
        }
        try {
            self::add_bundle_entries($zip, $this->bundle_contents($batch_id));
            if (!$zip->close()) {
                throw new RuntimeException('Could not finish the transfer ZIP bundle.');
            }
        } catch (Throwable $e) {
            $zip->close();
            throw $e;
        }
    }

    public function action_download(): void
    {
        $this->guard();
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        $temporary = '';
        try {
            $temporary = tempnam(get_temp_dir(), 'sat-transfer-');
            if ($temporary === false) {
                throw new RuntimeException('Could not create a private temporary bundle file.');
            }
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Could not secure the private temporary bundle file.');
            }
            $this->write_bundle_for_batch($batch_id, $temporary);
            if (!is_readable($temporary) || filesize($temporary) === false) {
                throw new RuntimeException('The generated transfer bundle cannot be read.');
            }
            nocache_headers();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="sat-transfer-batch-' . $batch_id . '.zip"');
            header('Content-Length: ' . (string)filesize($temporary));
            readfile($temporary);
            unlink($temporary);
            exit;
        } catch (Throwable $e) {
            if ($temporary !== '' && is_file($temporary)) {
                unlink($temporary);
            }
            $this->message('error', $e->getMessage());
        }
    }

    private function confirm_batch(int $batch_id): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM sat_transfer_ofx_batches WHERE batch_id = ? FOR UPDATE');
            $q->execute([$batch_id]);
            $batch = $q->fetch();
            if (!$batch || !self::transition_allowed((string)$batch['batch_status'], 'confirmed')) {
                throw new RuntimeException('Only a generated batch awaiting confirmation can be confirmed.');
            }
            $obs = $db->prepare(
                'SELECT l.observation_id
                 FROM sat_transfer_ofx_legs l
                 JOIN sat_transfer_ofx_batch_matches bm ON bm.batch_match_id = l.batch_match_id
                 WHERE bm.batch_id = ?
                 ORDER BY l.observation_id FOR UPDATE'
            );
            $obs->execute([$batch_id]);
            $observation_ids = array_map('intval', $obs->fetchAll(PDO::FETCH_COLUMN));
            $transfer_count = $db->prepare('SELECT COUNT(*) FROM sat_transfer_ofx_batch_matches WHERE batch_id = ?');
            $transfer_count->execute([$batch_id]);
            if (!$observation_ids || count($observation_ids) !== (int)$transfer_count->fetchColumn() * 2) {
                throw new RuntimeException('The batch does not contain exactly two frozen observations per transfer.');
            }
            $reservation = $db->prepare(
                'SELECT batch_id FROM sat_transfer_ofx_reservations WHERE observation_id = ? FOR UPDATE'
            );
            $review_status = $db->prepare(
                'SELECT review_status FROM inbox_reviews WHERE bank_observation_id = ? FOR UPDATE'
            );
            foreach ($observation_ids as $observation_id) {
                $reservation->execute([$observation_id]);
                if ((int)$reservation->fetchColumn() !== $batch_id) {
                    throw new RuntimeException('The batch no longer owns all of its transfer observations.');
                }
                $review_status->execute([$observation_id]);
                if (in_array((string)$review_status->fetchColumn(), ['excluded', 'exported'], true)) {
                    throw new RuntimeException('A transfer observation is now excluded or exported through another route.');
                }
            }
            $review = $db->prepare(
                "INSERT INTO inbox_reviews
                 (bank_observation_id, review_status, export_route, transfer_ofx_batch_id, exported_at, reviewed_by, reviewed_at)
                 VALUES (?, 'exported', 'transfer_ofx', ?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE review_status = 'exported', export_route = 'transfer_ofx',
                   transfer_ofx_batch_id = VALUES(transfer_ofx_batch_id), exported_at = CURRENT_TIMESTAMP,
                   reviewed_by = VALUES(reviewed_by), reviewed_at = CURRENT_TIMESTAMP,
                   updated_at = CURRENT_TIMESTAMP"
            );
            foreach ($observation_ids as $observation_id) {
                $review->execute([$observation_id, $batch_id, get_current_user_id()]);
            }
            $db->prepare(
                "UPDATE sat_transfer_ofx_batches
                 SET batch_status = 'confirmed', confirmed_by = ?, confirmed_at = CURRENT_TIMESTAMP
                 WHERE batch_id = ?"
            )->execute([get_current_user_id(), $batch_id]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function action_confirm(): void
    {
        $this->guard();
        try {
            if (empty($_POST['confirm_imported'])) {
                throw new RuntimeException('Confirm only after both account files have been imported and matched in Wave.');
            }
            $this->confirm_batch((int)($_POST['batch_id'] ?? 0));
            $this->message('success', 'Transfer batch confirmed as imported and matched in Wave.');
        } catch (Throwable $e) {
            $this->message('error', $e->getMessage());
        }
    }

    private function cancel_batch(int $batch_id): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT batch_status FROM sat_transfer_ofx_batches WHERE batch_id = ? FOR UPDATE');
            $q->execute([$batch_id]);
            $status = $q->fetchColumn();
            if (!self::transition_allowed((string)$status, 'cancelled')) {
                throw new RuntimeException('Only an unconfirmed generated transfer batch can be cancelled.');
            }
            $db->prepare(
                "UPDATE sat_transfer_ofx_batches
                 SET batch_status = 'cancelled', cancelled_by = ?, cancelled_at = CURRENT_TIMESTAMP
                 WHERE batch_id = ?"
            )->execute([get_current_user_id(), $batch_id]);
            $db->prepare('DELETE FROM sat_transfer_ofx_reservations WHERE batch_id = ?')->execute([$batch_id]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function action_cancel(): void
    {
        $this->guard();
        try {
            $this->cancel_batch((int)($_POST['batch_id'] ?? 0));
            $this->message('success', 'Transfer batch cancelled. Its transfers are available to generate again.');
        } catch (Throwable $e) {
            $this->message('error', $e->getMessage());
        }
    }

    private function open_form(string $action, int $batch_id = 0): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        if ($batch_id > 0) {
            echo '<input type="hidden" name="batch_id" value="' . $batch_id . '">';
        }
        wp_nonce_field(self::NONCE);
    }

    public function render(): void
    {
        echo '<div class="sat-transfer-ofx">';
        echo '<h3>Transfers to Wave</h3>';
        echo '<p class="help">Accepted transfers are exported as uncategorized account-specific OFX statements. Import each account file in Wave, match both sides as a transfer, then confirm the batch here.</p>';
        if (isset($_GET['sat_notice'], $_GET['sat_message'])) {
            echo '<div class="msg ' . esc_attr(sanitize_key(wp_unslash($_GET['sat_notice']))) . '">' .
                esc_html(sanitize_text_field(rawurldecode(wp_unslash($_GET['sat_message'])))) . '</div>';
        }
        try {
            $this->render_ready();
            $this->render_generated();
            $this->render_history();
        } catch (Throwable $e) {
            echo '<div class="msg error">' . esc_html($e->getMessage()) . '</div>';
        }
        echo '</div>';
    }

    private function render_ready(): void
    {
        $pairs = $this->eligible_pairs();
        echo '<h4>Ready to generate <span class="count">' . count($pairs) . '</span></h4>';
        if (!$pairs) {
            echo '<p>There are no accepted, eligible transfers waiting for an OFX batch.</p>';
            return;
        }
        $this->open_form(self::ACTION_CREATE);
        echo '<table class="widefat striped"><thead><tr><th><input type="checkbox" class="all"></th><th>Date</th><th>Source account</th><th>Destination account</th><th>Amount</th><th>Descriptions</th><th>Transfer batch state</th></tr></thead><tbody>';
        foreach ($pairs as $pair) {
            $source = $pair['source'];
            $destination = $pair['destination'];
            $dates = $source['date'] === $destination['date']
                ? $source['date']
                : $source['date'] . ' / ' . $destination['date'];
            $descriptions = array_filter([
                $source['description'] !== '' ? $source['description'] : 'Source transaction',
                $destination['description'] !== '' ? $destination['description'] : 'Destination transaction',
            ]);
            echo '<tr><td><input type="checkbox" class="check" name="selected_matches[]" value="' . (int)$pair['match_id'] . '"></td>';
            echo '<td>' . esc_html($dates) . '</td>';
            echo '<td>' . esc_html($source['account']['label']) . '</td>';
            echo '<td>' . esc_html($destination['account']['label']) . '</td>';
            echo '<td>' . esc_html(number_format($pair['amount_cents'] / 100, 2) . ' ' . $pair['currency']) . '</td>';
            echo '<td>' . esc_html(implode(' / ', $descriptions)) . '</td>';
            echo '<td>Ready to generate</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">Create OFX batch</button> <span class="bulk-help">Selections and counts represent transfer pairs.</span></p></form>';
    }

    private function render_generated(): void
    {
        $batches = $this->db()->query(
            "SELECT * FROM sat_transfer_ofx_batches WHERE batch_status = 'generated' ORDER BY created_at, batch_id"
        )->fetchAll();
        echo '<h4>Generated, awaiting confirmation <span class="count">' . count($batches) . '</span></h4>';
        if (!$batches) {
            echo '<p>No generated batches are awaiting confirmation.</p>';
            return;
        }
        foreach ($batches as $batch) {
            $id = (int)$batch['batch_id'];
            $transfers = $this->transfers($id);
            $files = $this->account_files($id);
            echo '<section class="sat-transfer-batch"><h5>Batch #' . $id . '</h5>';
            echo '<p>Generated ' . esc_html((string)$batch['generated_at']) . ' · ' . count($transfers) . ' transfers · ' . count($files) . ' account files</p>';
            $this->open_form(self::ACTION_DOWNLOAD, $id);
            echo '<button class="button" type="submit">Download bundle</button></form> ';
            $this->open_form(self::ACTION_CONFIRM, $id);
            echo '<label><input required type="checkbox" name="confirm_imported" value="1"> I imported both account files and matched each transfer in Wave.</label> ';
            echo '<button class="button button-primary" type="submit">Confirm imported and matched</button></form> ';
            $this->open_form(self::ACTION_CANCEL, $id);
            echo '<button class="button" type="submit" onclick="return confirm(\'Cancel this unconfirmed transfer batch? Its transfers will become actionable again.\')">Cancel batch</button></form>';
            echo '</section>';
        }
    }

    private function render_history(): void
    {
        $batches = $this->db()->query(
            "SELECT * FROM sat_transfer_ofx_batches WHERE batch_status IN ('confirmed', 'cancelled')
             ORDER BY COALESCE(confirmed_at, cancelled_at) DESC, batch_id DESC"
        )->fetchAll();
        echo '<details class="sat-transfer-history"><summary>Completed or cancelled history (' . count($batches) . ')</summary>';
        if (!$batches) {
            echo '<p>No transfer batches in history.</p></details>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Batch</th><th>Status</th><th>Transfers</th><th>Account files</th><th>Completed</th><th></th></tr></thead><tbody>';
        foreach ($batches as $batch) {
            $id = (int)$batch['batch_id'];
            echo '<tr><td>#' . $id . '</td><td>' . esc_html(ucfirst((string)$batch['batch_status'])) . '</td>';
            echo '<td>' . count($this->transfers($id)) . '</td><td>' . count($this->account_files($id)) . '</td>';
            echo '<td>' . esc_html((string)($batch['confirmed_at'] ?: $batch['cancelled_at'])) . '</td><td>';
            if ($batch['batch_status'] === 'confirmed') {
                $this->open_form(self::ACTION_DOWNLOAD, $id);
                echo '<button class="button" type="submit">Download bundle</button></form>';
            } else {
                echo '&mdash;';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></details>';
    }
}
