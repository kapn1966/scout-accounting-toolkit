<?php
/**
 * Persistent, frozen Wave export preview batches.
 *
 * Review data is copied into SAT-owned batch tables before export. A batch never
 * silently changes when the inbox changes later. Every Wave mutation is an
 * explicitly confirmed, single-item operation with a deterministic external ID.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Wave_Export
{
    private const NONCE = 'sat_wave_export';
    private const ACTION_CREATE = 'sat_wave_export_create_preview';
    private const ACTION_SAVE = 'sat_wave_export_save_preview';
    private const ACTION_ONE = 'sat_wave_export_one';
    private const ACTION_CSV = 'sat_wave_export_csv';
    private const ACTION_DISCARD = 'sat_wave_export_discard';
    private const ACTION_ALL = 'sat_wave_export_all';
    private const NONCE_ALL = 'sat_wave_export_all';

    private static ?self $instance = null;
    private ?array $account_cache = null;
    private ?array $bank_cache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('admin_post_' . self::ACTION_CREATE, [$this, 'action_create_preview']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'action_save_preview']);
        add_action('admin_post_' . self::ACTION_ONE, [$this, 'action_export_one']);
        add_action('admin_post_' . self::ACTION_CSV, [$this, 'action_csv']);
        add_action('admin_post_' . self::ACTION_DISCARD, [$this, 'action_discard']);
        add_action('admin_post_' . self::ACTION_ALL, [$this, 'action_export_all']);
    }

    private function db(): PDO
    {
        return SAT_DB::pdo();
    }

    private function guard(string $nonce = self::NONCE): void
    {
        if (!is_user_logged_in() || !current_user_can(SAT_CAP_REVIEW)) {
            wp_die('You do not have permission to export transactions.', 'Scout Accounting', ['response' => 403]);
        }
        check_admin_referer($nonce);
    }

    private function clean_text(mixed $value, int $limit = 255): string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', (string)$value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private function cents(string|int|float|null $value): int
    {
        return (int)round(abs((float)$value) * 100);
    }

    private function payload(array $row): array
    {
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        return is_array($payload) ? $payload : [];
    }

    private function source_ids(array $item): array
    {
        $ids = json_decode((string)($item['source_observation_ids'] ?? ''), true);
        if (!is_array($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);
        return $ids;
    }

    private function errors_json(array $errors): ?string
    {
        $errors = array_values(array_unique(array_filter(array_map([$this, 'clean_text'], $errors))));
        return $errors ? wp_json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    }

    private function errors_array(?string $json): array
    {
        $errors = json_decode((string)$json, true);
        return is_array($errors) ? array_values(array_filter(array_map('strval', $errors))) : [];
    }

    /** Active discovered Wave accounts, keyed by both ID and lower-case exact name. */
    private function wave_accounts(): array
    {
        if ($this->account_cache !== null) {
            return $this->account_cache;
        }
        $by_id = [];
        $by_name = [];
        foreach ((array)(SAT_Settings::get('wave')['discovered_accounts'] ?? []) as $account) {
            if (!empty($account['is_archived']) || empty($account['id']) || empty($account['name'])) {
                continue;
            }
            $id = (string)$account['id'];
            $by_id[$id] = $account;
            $by_name[strtolower(trim((string)$account['name']))] = $account;
        }
        return $this->account_cache = ['id' => $by_id, 'name' => $by_name];
    }

    private function account_by_name(string $name): ?array
    {
        return $this->wave_accounts()['name'][strtolower(trim($name))] ?? null;
    }

    /** Bank/card settings keyed by inbox source system. */
    private function banks(): array
    {
        if ($this->bank_cache !== null) {
            return $this->bank_cache;
        }
        $banks = SAT_Settings::banks();
        if (!$banks) {
            $rows = $this->db()->query("SELECT DISTINCT source_system FROM inbox_observations WHERE source_system LIKE 'ofx\\_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $source) {
                $banks[(string)$source] = ['label' => (string)$source, 'type' => 'bank', 'wave' => '', 'acctid' => ''];
            }
        }
        return $this->bank_cache = $banks;
    }

    private function bank_label(string $source): string
    {
        return (string)($this->banks()[$source]['label'] ?? $source);
    }

    private function bank_wave_account(string $source): ?array
    {
        $name = (string)($this->banks()[$source]['wave'] ?? '');
        return $name !== '' ? $this->account_by_name($name) : null;
    }

    /**
     * Translate the OFX sign into Wave's anchor direction. Money leaving a bank account credits the
     * asset (WITHDRAWAL); a card purchase credits the liability (DEPOSIT on a liability anchor).
     */
    private function anchor_direction(string $source, string|int|float|null $amount): string
    {
        $is_credit_card = ($this->banks()[$source]['type'] ?? '') === 'credit_card';
        if ($is_credit_card) {
            return (float)$amount < 0 ? 'DEPOSIT' : 'WITHDRAWAL';
        }
        return (float)$amount < 0 ? 'WITHDRAWAL' : 'DEPOSIT';
    }

    /*
     * Wave debit/credit semantics (developer guide, "Mutation: Create Money Transaction"):
     *   Anchor  DEPOSIT = DEBIT on an ASSET anchor and CREDIT on a LIABILITY anchor; WITHDRAWAL the opposite.
     *   Line    INCREASE = the account type's normal side (DEBIT for ASSET/EXPENSE, CREDIT for
     *           INCOME/LIABILITY/EQUITY), inverted for the contra subtypes; DECREASE the opposite.
     * Wave rejects a transaction unless it has at least one debit and one credit.
     */
    private const CONTRA_SUBTYPES = ['DISCOUNTS', 'DEPRECIATION_AND_AMORTIZATION'];

    /** DEBIT or CREDIT for a Wave account type's normal balance; '' when unknown. */
    public static function normal_side(string $type_value): string
    {
        return match (strtoupper($type_value)) {
            'ASSET', 'EXPENSE' => 'DEBIT',
            'INCOME', 'LIABILITY', 'EQUITY' => 'CREDIT',
            default => '',
        };
    }

    private static function opposite_side(string $side): string
    {
        return $side === 'DEBIT' ? 'CREDIT' : ($side === 'CREDIT' ? 'DEBIT' : '');
    }

    /** DEBIT or CREDIT applied to the anchor account; '' when the anchor type or direction is unusable. */
    public static function anchor_side(array $anchor_account, string $direction): string
    {
        $type = strtoupper((string)($anchor_account['type_value'] ?? ''));
        if (!in_array($type, ['ASSET', 'LIABILITY'], true) || !in_array($direction, ['DEPOSIT', 'WITHDRAWAL'], true)) {
            return '';
        }
        $deposit = $type === 'ASSET' ? 'DEBIT' : 'CREDIT';
        return $direction === 'DEPOSIT' ? $deposit : self::opposite_side($deposit);
    }

    /** DEBIT or CREDIT that a line balance applies to its account; '' when the account type is unknown. */
    public static function line_side(array $line_account, string $balance): string
    {
        if (in_array($balance, ['DEBIT', 'CREDIT'], true)) {
            return $balance;
        }
        $increase = self::normal_side((string)($line_account['type_value'] ?? ''));
        if (in_array(strtoupper((string)($line_account['subtype_value'] ?? '')), self::CONTRA_SUBTYPES, true)) {
            $increase = self::opposite_side($increase);
        }
        if ($increase === '' || !in_array($balance, ['INCREASE', 'DECREASE'], true)) {
            return '';
        }
        return $balance === 'INCREASE' ? $increase : self::opposite_side($increase);
    }

    /**
     * INCREASE or DECREASE that places a categorization line opposite the anchor, so the transaction
     * balances. Derived from the anchor account type, anchor direction and line account type; never
     * from the amount sign alone. '' when either account type is unknown.
     */
    public static function balancing_line_balance(array $anchor_account, string $direction, array $line_account): string
    {
        $needed = self::opposite_side(self::anchor_side($anchor_account, $direction));
        if ($needed === '') {
            return '';
        }
        foreach (['INCREASE', 'DECREASE'] as $balance) {
            if (self::line_side($line_account, $balance) === $needed) {
                return $balance;
            }
        }
        return '';
    }

    /** Debit/credit errors for an anchor and its lines; each line is [line_number, account, balance, amount]. */
    public static function double_entry_errors(array $anchor_account, string $direction, string $amount, array $lines): array
    {
        $anchor_side = self::anchor_side($anchor_account, $direction);
        if ($anchor_side === '') {
            return ['The anchor account type is not a Wave asset or liability, so its debit/credit side is unknown. Rediscover Wave accounts in Settings.'];
        }
        $errors = [];
        $cents = [self::opposite_side($anchor_side) => 0, $anchor_side => (int)round(abs((float)$amount) * 100)];
        $described = [];
        foreach ($lines as [$number, $account, $balance, $line_amount]) {
            $side = self::line_side($account, (string)$balance);
            if ($side === '') {
                $errors[] = 'Line ' . (int)$number . ' account type is unknown, so its debit/credit side cannot be checked. Rediscover Wave accounts in Settings.';
                continue;
            }
            $cents[$side] += (int)round(abs((float)$line_amount) * 100);
            $described[] = 'line ' . (int)$number . ' (' . $balance . ' on ' . strtolower((string)($account['type_value'] ?? '')) . ' account ' . ($account['name'] ?? '') . ') is a ' . strtolower($side);
        }
        if ($errors) {
            return $errors;
        }
        if ($cents['DEBIT'] === 0 || $cents['CREDIT'] === 0) {
            $only = $cents['DEBIT'] === 0 ? 'credits' : 'debits';
            $errors[] = 'One-sided transaction: the anchor (' . $direction . ' on ' . strtolower((string)$anchor_account['type_value']) . ' account ' . ($anchor_account['name'] ?? '')
                . ') is a ' . strtolower($anchor_side) . ' and ' . implode('; ', $described) . '. All sides are ' . $only
                . ', and Wave requires at least one debit and one credit. Save the preview to recalculate line directions.';
        } elseif ($cents['DEBIT'] !== $cents['CREDIT']) {
            $errors[] = 'Debits and credits do not balance. Save the preview to recalculate line directions.';
        }
        return $errors;
    }

    private function bank_sources(): array
    {
        return array_keys($this->banks()) ?: ['__none__'];
    }

    private function in_banks(): string
    {
        return implode(',', array_fill(0, count($this->bank_sources()), '?'));
    }

    private function observation(int $id): ?array
    {
        $q = $this->db()->prepare('SELECT * FROM inbox_observations WHERE observation_id = ? AND source_system IN (' . $this->in_banks() . ')');
        $q->execute(array_merge([$id], $this->bank_sources()));
        return $q->fetch() ?: null;
    }

    private function allocations(int $id): array
    {
        $q = $this->db()->prepare('SELECT * FROM inbox_allocations WHERE bank_observation_id = ? ORDER BY line_number, allocation_id');
        $q->execute([$id]);
        return $q->fetchAll();
    }

    private function review(int $id): ?array
    {
        $q = $this->db()->prepare('SELECT * FROM inbox_reviews WHERE bank_observation_id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    private function matched_rows(int $id): array
    {
        $q = $this->db()->prepare(
            'SELECT o.* FROM inbox_matches m
             JOIN inbox_observations o ON o.observation_id = CASE WHEN m.left_observation_id = ? THEN m.right_observation_id ELSE m.left_observation_id END
             WHERE m.left_observation_id = ? OR m.right_observation_id = ?
             ORDER BY o.transaction_date, o.observation_id'
        );
        $q->execute([$id, $id, $id]);
        return $q->fetchAll();
    }

    private function matched_bank(int $id): ?array
    {
        $sources = array_fill_keys($this->bank_sources(), true);
        foreach ($this->matched_rows($id) as $row) {
            if (isset($sources[(string)$row['source_system']])) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Observations frozen in an existing preview item, whatever its status, keyed to their batch ID.
     * Source rows whose item no longer exists do not stage an observation.
     */
    public function staged_observation_ids(): array
    {
        $rows = $this->db()->query(
            'SELECT s.observation_id, i.batch_id FROM sat_wave_export_sources s
             JOIN sat_wave_export_items i ON i.item_id = s.item_id'
        )->fetchAll();
        $ids = [];
        foreach ($rows as $row) {
            $ids[(int)$row['observation_id']] = (int)$row['batch_id'];
        }
        return $ids;
    }

    private function staged_batch_id(int $id): int
    {
        $q = $this->db()->prepare(
            'SELECT i.batch_id FROM sat_wave_export_sources s
             JOIN sat_wave_export_items i ON i.item_id = s.item_id
             WHERE s.observation_id = ? LIMIT 1'
        );
        $q->execute([$id]);
        return (int)($q->fetchColumn() ?: 0);
    }

    /** Ensure a source is still in Ready for Wave before freezing it. */
    private function ready_source(int $id): array
    {
        $observation = $this->observation($id);
        if (!$observation) {
            throw new RuntimeException('Transaction #' . $id . ' was not found.');
        }
        $blocked = SAT_Transfer_OFX::instance()->api_block_reason($id);
        if ($blocked !== '') {
            throw new RuntimeException($blocked);
        }
        $staged = $this->staged_batch_id($id);
        if ($staged > 0) {
            throw new RuntimeException('Transaction #' . $id . ' is already in Wave preview #' . $staged . '. Export or retry it from that preview, or discard the preview first.');
        }
        $review = $this->review($id);
        if (in_array($review['review_status'] ?? '', ['excluded', 'exported'], true)) {
            throw new RuntimeException('Transaction #' . $id . ' is closed and cannot be previewed.');
        }
        $allocations = $this->allocations($id);
        $sum = 0;
        foreach ($allocations as $line) {
            $sum += $this->cents($line['amount']);
        }
        if (!$allocations || $sum !== $this->cents($observation['amount'])) {
            throw new RuntimeException('Transaction #' . $id . ' is no longer Ready for Wave.');
        }
        return ['observation' => $observation, 'allocations' => $allocations];
    }

    /** Stable across retries and batches; sensitive only to business and canonical source keys. */
    private function external_id(array $observations, string $business_id): string
    {
        $keys = [];
        foreach ($observations as $row) {
            $keys[] = (string)$row['source_system'] . ':' . (string)$row['source_key'];
        }
        sort($keys, SORT_STRING);
        return 'SAT-' . strtoupper(substr(hash('sha256', $business_id . '|' . implode('|', $keys)), 0, 48));
    }

    /** Cleaned bank or card text, used when no richer evidence is matched. */
    private function bank_description(array $row): string
    {
        $payload = $this->payload($row);
        $parts = [];
        foreach ([$payload['name'] ?? '', $payload['memo'] ?? '', $row['description'] ?? ''] as $part) {
            $part = $this->clean_text($part);
            if ($part === '') {
                continue;
            }
            $duplicate = false;
            foreach ($parts as $existing) {
                if (stripos($existing, $part) !== false || stripos($part, $existing) !== false) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $parts[] = $part;
            }
        }
        $description = implode(' — ', array_slice($parts, 0, 2));
        return $this->clean_text($description !== '' ? $description : $this->bank_label((string)$row['source_system']) . ' transaction #' . $row['observation_id']);
    }

    /** Purpose of one claim: its effective description, prefixed by the effective payee when the payee adds information. */
    private function claim_purpose(array $evidence): string
    {
        $values = SAT_Claim_Corrections::effective_values($evidence);
        $description = $this->clean_text($values['description']);
        $payee = $this->clean_text($values['payee']);
        if ($description === '') {
            return $payee;
        }
        if ($payee !== '' && stripos($description, $payee) === false) {
            return $payee . ' - ' . $description;
        }
        return $description;
    }

    /**
     * Purpose of one Interac payment: "from <payer> - <message>", "from <payer>", or the message alone.
     * A message that already starts with the payer's name does not repeat it.
     */
    private function interac_purpose(array $evidence): string
    {
        $payload = $this->payload($evidence);
        $payer = $this->clean_text($payload['payer_name'] ?? '');
        $message = $this->clean_text($payload['payment_message'] ?? '');
        if ($payer === '') {
            return $message;
        }
        if ($message !== '' && strncasecmp($message, $payer, strlen($payer)) === 0
            && preg_match('/^[^\p{L}\p{N}]|^$/u', substr($message, strlen($payer)))) {
            $message = (string)preg_replace('/^[\s\-:,;.–—]+/u', '', substr($message, strlen($payer)));
        }
        return $this->clean_text('from ' . $payer . ($message !== '' ? ' - ' . $message : ''));
    }

    /**
     * Wave description: the purpose of the money, taken from matched evidence where possible.
     *   Interac income  -> from <payer> - <payment message> (see interac_purpose())
     *   Expense claims  -> the claim description (with payee); several claims are joined with "; "
     *   Otherwise       -> cleaned bank or card text
     */
    private function default_description(array $row, array $matched = []): string
    {
        $parts = [];
        foreach ($matched as $evidence) {
            $source = (string)($evidence['source_system'] ?? '');
            if (isset($this->banks()[$source])) {
                continue;
            }
            if ($source === 'gravity_forms') {
                $part = $this->claim_purpose($evidence);
            } else {
                $part = $this->interac_purpose($evidence);
            }
            if ($part !== '' && !in_array(strtolower($part), array_map('strtolower', $parts), true)) {
                $parts[] = $part;
            }
        }
        $description = $this->clean_text(implode('; ', $parts));
        return $description !== '' ? $description : $this->bank_description($row);
    }

    /** Audit trail for Wave notes: who, which section or claim, and the source identifiers. */
    private function default_note(array $row, array $matched): string
    {
        $parts = [];
        foreach ($matched as $evidence) {
            $source = (string)($evidence['source_system'] ?? '');
            if (isset($this->banks()[$source])) {
                continue;
            }
            $payload = $this->payload($evidence);
            if ($source === 'gravity_forms') {
                $entry = $evidence['gf_entry_id'] ?: ($payload['entry_id'] ?? '');
                $bits = ['Claim' . ($entry !== '' ? ' #' . $entry : '')];
                if (!empty($payload['external_id'])) {
                    $bits[] = $payload['external_id'];
                }
                if (!empty($payload['submitter_name'])) {
                    $bits[] = 'claimant ' . $payload['submitter_name'];
                }
            } else {
                $bits = ['Interac'];
                if (!empty($payload['payer_name'])) {
                    $bits[] = 'from ' . $payload['payer_name'];
                }
                if (!empty($payload['section'])) {
                    $bits[] = $payload['section'];
                }
                if (!empty($payload['reference_number'])) {
                    $bits[] = 'ref ' . $payload['reference_number'];
                }
            }
            $parts[] = implode(' · ', $bits);
        }
        $payload = $this->payload($row);
        if (!empty($payload['fitid'])) {
            $parts[] = $this->bank_label((string)$row['source_system']) . ' FITID ' . $payload['fitid'];
        }
        $parts[] = 'SAT #' . $row['observation_id'];
        return $this->clean_text(implode(' | ', array_values(array_unique($parts))));
    }

    private function ordinary_plan(array $source, string $business_id): array
    {
        $row = $source['observation'];
        $matched = $this->matched_rows((int)$row['observation_id']);
        $note = $this->default_note($row, $matched);
        $anchor = $this->bank_wave_account((string)$row['source_system']);
        $direction = $this->anchor_direction((string)$row['source_system'], $row['amount']);
        $lines = [];
        foreach ($source['allocations'] as $index => $allocation) {
            $account = $this->account_by_name((string)$allocation['export_account']);
            $line_note = trim((string)($allocation['allocation_note'] ?? ''));
            if ($line_note === '' && count($source['allocations']) > 1) {
                $line_note = 'Split ' . ($index + 1) . '/' . count($source['allocations']);
                if ($note !== '') {
                    $line_note .= ' · ' . $note;
                }
            }
            $lines[] = [
                'account_id' => (string)($account['id'] ?? ''),
                'account_name' => (string)$allocation['export_account'],
                'amount' => number_format(abs((float)$allocation['amount']), 2, '.', ''),
                'balance_direction' => self::balancing_line_balance($anchor ?? [], $direction, $account ?? []),
                'note' => $this->clean_text($line_note),
                'source_allocation_id' => (int)$allocation['allocation_id'],
            ];
        }
        return [
            'kind' => count($lines) > 1 ? 'split' : 'transaction',
            'observations' => [$row],
            'source_label' => $this->bank_label((string)$row['source_system']),
            'transaction_date' => (string)$row['transaction_date'],
            'anchor_source' => (string)$row['source_system'],
            'anchor_account_id' => (string)($anchor['id'] ?? ''),
            'anchor_account_name' => (string)($anchor['name'] ?? ($this->banks()[$row['source_system']]['wave'] ?? '')),
            'anchor_direction' => $direction,
            'amount' => number_format(abs((float)$row['amount']), 2, '.', ''),
            'description' => $this->default_description($row, $matched),
            'note' => $note,
            'external_id' => $this->external_id([$row], $business_id),
            'lines' => $lines,
        ];
    }

    private function transfer_plan(array $first, array $second, string $business_id): array
    {
        $a = $first['observation'];
        $b = $second['observation'];
        if ($a['source_system'] === $b['source_system']) {
            throw new RuntimeException('Matched transfer #' . $a['observation_id'] . ' uses the same account on both sides.');
        }
        if ($this->cents($a['amount']) !== $this->cents($b['amount']) || ((float)$a['amount'] < 0) === ((float)$b['amount'] < 0)) {
            throw new RuntimeException('Matched transfer #' . $a['observation_id'] . ' does not have equal and opposite sides.');
        }
        $anchor_row = (float)$a['amount'] < 0 ? $a : $b;
        $other_row = (int)$anchor_row['observation_id'] === (int)$a['observation_id'] ? $b : $a;
        $anchor = $this->bank_wave_account((string)$anchor_row['source_system']);
        $other = $this->bank_wave_account((string)$other_row['source_system']);
        $from = $this->bank_label((string)$anchor_row['source_system']);
        $to = $this->bank_label((string)$other_row['source_system']);
        $other_balance = ($this->banks()[$other_row['source_system']]['type'] ?? '') === 'credit_card' ? 'DECREASE' : 'INCREASE';
        return [
            'kind' => 'transfer',
            'observations' => [$a, $b],
            'source_label' => $from . ' → ' . $to,
            'transaction_date' => (string)$anchor_row['transaction_date'],
            'anchor_source' => (string)$anchor_row['source_system'],
            'anchor_account_id' => (string)($anchor['id'] ?? ''),
            'anchor_account_name' => (string)($anchor['name'] ?? ($this->banks()[$anchor_row['source_system']]['wave'] ?? '')),
            'anchor_direction' => $this->anchor_direction((string)$anchor_row['source_system'], $anchor_row['amount']),
            'amount' => number_format(abs((float)$anchor_row['amount']), 2, '.', ''),
            'description' => $this->clean_text('Transfer: ' . $from . ' to ' . $to),
            'note' => $this->clean_text('Matched inbox transactions #' . $a['observation_id'] . ' and #' . $b['observation_id']),
            'external_id' => $this->external_id([$a, $b], $business_id),
            'lines' => [[
                'account_id' => (string)($other['id'] ?? ''),
                'account_name' => (string)($other['name'] ?? ($this->banks()[$other_row['source_system']]['wave'] ?? $to)),
                'amount' => number_format(abs((float)$anchor_row['amount']), 2, '.', ''),
                'balance_direction' => $other_balance,
                'note' => $this->clean_text('Other side of matched transfer: ' . $to),
                'source_allocation_id' => null,
            ]],
        ];
    }

    private function batch(int $batch_id): ?array
    {
        $q = $this->db()->prepare('SELECT * FROM sat_wave_export_batches WHERE batch_id = ?');
        $q->execute([$batch_id]);
        return $q->fetch() ?: null;
    }

    private function items(int $batch_id): array
    {
        $q = $this->db()->prepare('SELECT * FROM sat_wave_export_items WHERE batch_id = ? ORDER BY sort_order, item_id');
        $q->execute([$batch_id]);
        return $q->fetchAll();
    }

    private function item(int $item_id): ?array
    {
        $q = $this->db()->prepare('SELECT * FROM sat_wave_export_items WHERE item_id = ?');
        $q->execute([$item_id]);
        return $q->fetch() ?: null;
    }

    private function lines(int $item_id): array
    {
        $q = $this->db()->prepare('SELECT * FROM sat_wave_export_lines WHERE item_id = ? ORDER BY line_number, line_id');
        $q->execute([$item_id]);
        return $q->fetchAll();
    }

    private function sources(int $item_id): array
    {
        $q = $this->db()->prepare('SELECT * FROM sat_wave_export_sources WHERE item_id = ? ORDER BY observation_id');
        $q->execute([$item_id]);
        return $q->fetchAll();
    }

    private function attempts(int $item_id): array
    {
        $q = $this->db()->prepare('SELECT * FROM sat_wave_export_attempts WHERE item_id = ? ORDER BY attempt_number DESC');
        $q->execute([$item_id]);
        return $q->fetchAll();
    }

    /**
     * Unexported items whose matched claims had their effective payee or description corrected or
     * reset since the preview was frozen. The frozen preview itself is never changed.
     */
    private function claim_changed_items(array $batch): array
    {
        try {
            $q = $this->db()->prepare(
                "SELECT DISTINCT s.item_id FROM sat_wave_export_sources s
                 JOIN sat_wave_export_items i ON i.item_id = s.item_id
                 JOIN inbox_matches m ON s.observation_id IN (m.left_observation_id, m.right_observation_id)
                 JOIN sat_observation_correction_events e ON e.observation_id IN (m.left_observation_id, m.right_observation_id) AND e.observation_id <> s.observation_id
                 WHERE i.batch_id = ? AND i.item_status IN ('pending', 'failed')
                   AND e.field_name IN ('payee', 'description') AND e.event_type IN ('created', 'value_changed', 'reset')
                   AND e.created_at >= ?"
            );
            $q->execute([(int)$batch['batch_id'], (string)$batch['created_at']]);
        } catch (PDOException $e) {
            if (SAT_Claim_Corrections::is_missing_table($e)) {
                return [];
            }
            throw $e;
        }
        return array_fill_keys(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Debit/credit check of an item against current Wave account types. Skipped when the anchor or a
     * line account is not active, because validate_item() already reports those accounts.
     */
    private function double_entry_check(array $item, array $lines): array
    {
        $active = $this->wave_accounts()['id'];
        $anchor = $active[(string)($item['anchor_account_id'] ?? '')] ?? null;
        if (!$lines || !$anchor || !in_array($item['anchor_direction'], ['DEPOSIT', 'WITHDRAWAL'], true)) {
            return [];
        }
        $checked = [];
        foreach ($lines as $line) {
            $account = $active[(string)($line['account_id'] ?? '')] ?? null;
            if (!$account) {
                return [];
            }
            $checked[] = [(int)$line['line_number'], $account, (string)$line['balance_direction'], (string)$line['amount']];
        }
        return self::double_entry_errors($anchor, (string)$item['anchor_direction'], (string)$item['amount'], $checked);
    }

    /** Stored validation errors plus the live debit/credit check, so a preview frozen before the check is never shown as Valid. */
    private function display_errors(array $item): array
    {
        $errors = $this->errors_array($item['validation_errors']);
        if ($item['item_status'] === 'exported') {
            return $errors;
        }
        return array_values(array_unique(array_merge($errors, $this->double_entry_check($item, $this->lines((int)$item['item_id'])))));
    }

    private function validate_item(array $item, array $lines, array $sources): array
    {
        $errors = [];
        $wave = SAT_Settings::get('wave');
        $active = $this->wave_accounts()['id'];

        if (SAT_Settings::wave_token() === '') {
            $errors[] = 'SAT_WAVE_TOKEN is not configured.';
        }
        if (empty($item['wave_business_id'])) {
            $errors[] = 'No Wave business was frozen into this preview.';
        } elseif (($wave['business_id'] ?? '') !== $item['wave_business_id']) {
            $errors[] = 'The frozen Wave business is no longer selected in Settings.';
        }
        if (empty($item['anchor_account_id']) || !isset($active[$item['anchor_account_id']])) {
            $errors[] = 'The anchor account is not mapped to an active Wave account.';
        } else {
            $anchor = $active[$item['anchor_account_id']];
            $expected_subtype = ($this->banks()[$item['anchor_source']]['type'] ?? '') === 'credit_card' ? 'CREDIT_CARD' : 'CASH_AND_BANK';
            if (($anchor['subtype_value'] ?? '') !== $expected_subtype) {
                $errors[] = 'The anchor account type does not match the configured bank/card source.';
            }
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$item['transaction_date']);
        if (!$date || $date->format('Y-m-d') !== (string)$item['transaction_date']) {
            $errors[] = 'The transaction date is invalid.';
        }
        if (trim((string)$item['description']) === '') {
            $errors[] = 'Description is required.';
        }
        if (strlen((string)$item['external_id']) < 12) {
            $errors[] = 'External ID is invalid.';
        }
        if ($this->cents($item['amount']) < 1) {
            $errors[] = 'Amount must be at least 0.01.';
        }
        if (!in_array($item['anchor_direction'], ['DEPOSIT', 'WITHDRAWAL'], true)) {
            $errors[] = 'Anchor direction is invalid.';
        }
        if (!$lines) {
            $errors[] = 'At least one line item is required.';
        }
        $sum = 0;
        foreach ($lines as $line) {
            $sum += $this->cents($line['amount']);
            if ($this->cents($line['amount']) < 1) {
                $errors[] = 'Line ' . (int)$line['line_number'] . ' amount must be at least 0.01.';
            }
            if (empty($line['account_id']) || !isset($active[$line['account_id']])) {
                $errors[] = 'Line ' . (int)$line['line_number'] . ' account is not active in Wave.';
            }
            if (!in_array($line['balance_direction'], ['INCREASE', 'DECREASE'], true)) {
                $errors[] = 'Line ' . (int)$line['line_number'] . ' balance direction is invalid.';
            }
        }
        if ($sum !== $this->cents($item['amount'])) {
            $errors[] = 'Line items do not balance to the transaction amount.';
        }
        array_push($errors, ...$this->double_entry_check($item, $lines));
        $expected_sources = $item['item_kind'] === 'transfer' ? 2 : 1;
        if (count($sources) !== $expected_sources) {
            $errors[] = ucfirst((string)$item['item_kind']) . ' item has an invalid number of frozen sources.';
        }
        $relation_ids = array_map('intval', array_column($sources, 'observation_id'));
        sort($relation_ids);
        if ($relation_ids !== $this->source_ids($item)) {
            $errors[] = 'Frozen source identifiers are inconsistent.';
        }
        if ($sources) {
            $expected_external_id = $this->external_id($sources, (string)$item['wave_business_id']);
            if (!hash_equals($expected_external_id, (string)$item['external_id'])) {
                $errors[] = 'External ID does not match the frozen sources.';
            }
        }
        if ($item['item_kind'] === 'transfer' && count($sources) === 2) {
            if ($sources[0]['source_system'] === $sources[1]['source_system']) {
                $errors[] = 'Transfer sources use the same account.';
            }
            if ($this->cents($sources[0]['amount']) !== $this->cents($sources[1]['amount'])
                || ((float)$sources[0]['amount'] < 0) === ((float)$sources[1]['amount'] < 0)) {
                $errors[] = 'Transfer sources are not equal and opposite.';
            }
        } elseif (count($sources) === 1 && $this->cents($sources[0]['amount']) !== $this->cents($item['amount'])) {
            $errors[] = 'Frozen source amount does not match the preview amount.';
        }
        foreach ($sources as $source) {
            $blocked = SAT_Transfer_OFX::instance()->api_block_reason((int)$source['observation_id']);
            if ($blocked !== '') {
                $errors[] = $blocked;
            }
            $review = $this->review((int)$source['observation_id']);
            if (($review['review_status'] ?? '') === 'excluded') {
                $errors[] = 'Source transaction #' . $source['observation_id'] . ' is now excluded.';
            }
            if (($review['review_status'] ?? '') === 'exported') {
                if (($review['wave_external_id'] ?? '') === $item['external_id']) {
                    $errors[] = 'Source transaction #' . $source['observation_id'] . ' is already marked exported with this external ID; reconcile the batch before retrying.';
                } else {
                    $errors[] = 'Source transaction #' . $source['observation_id'] . ' was exported by another item.';
                }
            }
        }
        return array_values(array_unique($errors));
    }

    private function refresh_validation(int $item_id): array
    {
        $item = $this->item($item_id);
        if (!$item) {
            throw new RuntimeException('Export item not found.');
        }
        $errors = $this->validate_item($item, $this->lines($item_id), $this->sources($item_id));
        $this->db()->prepare('UPDATE sat_wave_export_items SET validation_errors = ? WHERE item_id = ?')
            ->execute([$this->errors_json($errors), $item_id]);
        return $errors;
    }

    private function return_url(int $batch_id, string $type, string $message): string
    {
        $base = esc_url_raw(wp_unslash($_POST['return_url'] ?? home_url('/')));
        $url = add_query_arg(['sat_view' => 'wave', 'sat_batch' => $batch_id], $base);
        return add_query_arg(['sat_notice' => $type, 'sat_message' => rawurlencode($message)], $url);
    }

    private function redirect(int $batch_id, string $type, string $message): void
    {
        wp_safe_redirect($this->return_url($batch_id, $type, $message));
        exit;
    }

    private function create_batch(array $selected_ids): int
    {
        $selected_ids = array_values(array_unique(array_filter(array_map('intval', $selected_ids))));
        sort($selected_ids);
        if (!$selected_ids) {
            throw new RuntimeException('Select at least one Ready for Wave transaction.');
        }

        $wave = SAT_Settings::get('wave');
        $business_id = (string)($wave['business_id'] ?? '');
        if ($business_id === '') {
            throw new RuntimeException('Select and save a Wave business before creating a preview.');
        }
        $consumed = [];
        $plans = [];
        foreach ($selected_ids as $id) {
            if (isset($consumed[$id])) {
                continue;
            }
            $source = $this->ready_source($id);
            $pair_row = $this->matched_bank($id);
            if ($pair_row) {
                $pair_id = (int)$pair_row['observation_id'];
                $pair = $this->ready_source($pair_id);
                $plans[] = $this->transfer_plan($source, $pair, $business_id);
                $consumed[$id] = true;
                $consumed[$pair_id] = true;
            } else {
                $plans[] = $this->ordinary_plan($source, $business_id);
                $consumed[$id] = true;
            }
        }

        $db = $this->db();
        $db->beginTransaction();
        try {
            $source_ids = [];
            foreach ($plans as $plan) {
                foreach ($plan['observations'] as $observation) {
                    $source_ids[] = (int)$observation['observation_id'];
                }
            }
            $source_ids = array_values(array_unique($source_ids));
            sort($source_ids);
            $in = implode(',', array_fill(0, count($source_ids), '?'));
            $lock = $db->prepare(
                "SELECT observation_id FROM inbox_observations
                 WHERE observation_id IN ($in) ORDER BY observation_id FOR UPDATE"
            );
            $lock->execute($source_ids);
            if (count($lock->fetchAll(PDO::FETCH_COLUMN)) !== count($source_ids)) {
                throw new RuntimeException('A selected transaction no longer exists.');
            }
            foreach ($source_ids as $source_id) {
                $this->ready_source($source_id);
            }
            $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : hash('sha256', uniqid('', true));
            $db->prepare(
                "INSERT INTO sat_wave_export_batches (batch_uuid, batch_status, item_count, created_by)
                 VALUES (?, 'preview', ?, ?)"
            )->execute([$uuid, count($plans), get_current_user_id()]);
            $batch_id = (int)$db->lastInsertId();

            $insert_item = $db->prepare(
                "INSERT INTO sat_wave_export_items
                 (batch_id, sort_order, item_kind, source_observation_ids, source_label,
                  wave_business_id, wave_business_name, transaction_date, anchor_source,
                  anchor_account_id, anchor_account_name, anchor_direction, amount,
                  description, item_note, external_id, item_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $insert_source = $db->prepare(
                'INSERT INTO sat_wave_export_sources
                 (item_id, observation_id, source_system, source_key, transaction_date, amount, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert_line = $db->prepare(
                'INSERT INTO sat_wave_export_lines
                 (item_id, line_number, source_allocation_id, account_id, account_name, amount, balance_direction, line_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($plans as $index => $plan) {
                $source_ids = array_map(static function ($row) {
                    return (int)$row['observation_id'];
                }, $plan['observations']);
                sort($source_ids);
                $insert_item->execute([
                    $batch_id,
                    $index + 1,
                    $plan['kind'],
                    wp_json_encode($source_ids),
                    $plan['source_label'],
                    $business_id ?: null,
                    (string)($wave['business_name'] ?? ''),
                    $plan['transaction_date'],
                    $plan['anchor_source'],
                    $plan['anchor_account_id'] ?: null,
                    $plan['anchor_account_name'] ?: null,
                    $plan['anchor_direction'],
                    $plan['amount'],
                    $plan['description'],
                    $plan['note'] ?: null,
                    $plan['external_id'],
                ]);
                $item_id = (int)$db->lastInsertId();

                foreach ($plan['observations'] as $observation) {
                    $insert_source->execute([
                        $item_id,
                        (int)$observation['observation_id'],
                        $observation['source_system'],
                        $observation['source_key'],
                        $observation['transaction_date'],
                        $observation['amount'],
                        $this->clean_text($observation['description'] ?? ''),
                    ]);
                }
                foreach ($plan['lines'] as $line_index => $line) {
                    $insert_line->execute([
                        $item_id,
                        $line_index + 1,
                        $line['source_allocation_id'],
                        $line['account_id'] ?: null,
                        $line['account_name'],
                        $line['amount'],
                        $line['balance_direction'],
                        $line['note'] ?: null,
                    ]);
                }
                $this->refresh_validation($item_id);
            }
            $db->commit();
            return $batch_id;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('At least one selected transaction already belongs to a Wave preview. Open Wave Export history to continue it.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function action_create_preview(): void
    {
        $this->guard();
        try {
            $batch_id = $this->create_batch((array)($_POST['selected'] ?? []));
            $this->redirect($batch_id, 'success', 'Frozen Wave preview created. Review it before exporting.');
        } catch (Throwable $e) {
            $base = esc_url_raw(wp_unslash($_POST['return_url'] ?? home_url('/')));
            wp_safe_redirect(add_query_arg(['sat_notice' => 'error', 'sat_message' => rawurlencode($e->getMessage())], $base));
            exit;
        }
    }

    /**
     * Re-resolve frozen account names to current Wave account IDs and, for ordinary items, recalculate
     * each line's balance direction from the anchor and line account types. Amounts, accounts, the
     * anchor direction and the external ID are unchanged. Returns the number of line directions changed.
     */
    private function re_resolve_accounts(array $item): int
    {
        $db = $this->db();
        $anchor = $this->bank_wave_account((string)$item['anchor_source']);
        if ($anchor) {
            $db->prepare('UPDATE sat_wave_export_items SET anchor_account_id = ?, anchor_account_name = ? WHERE item_id = ?')
                ->execute([$anchor['id'], $anchor['name'], $item['item_id']]);
        }
        $corrected = 0;
        foreach ($this->lines((int)$item['item_id']) as $line) {
            $account = $this->account_by_name((string)$line['account_name']);
            if ($account) {
                $db->prepare('UPDATE sat_wave_export_lines SET account_id = ? WHERE line_id = ?')
                    ->execute([$account['id'], $line['line_id']]);
            }
            if ($item['item_kind'] === 'transfer' || !$anchor || !$account) {
                continue;
            }
            $balance = self::balancing_line_balance($anchor, (string)$item['anchor_direction'], $account);
            if ($balance !== '' && $balance !== $line['balance_direction']) {
                $db->prepare('UPDATE sat_wave_export_lines SET balance_direction = ? WHERE line_id = ?')
                    ->execute([$balance, $line['line_id']]);
                $corrected++;
            }
        }
        return $corrected;
    }

    /** A preview can be discarded only while nothing in it has been sent to Wave. */
    private function discard_blocker(int $batch_id): string
    {
        $q = $this->db()->prepare(
            "SELECT
                (SELECT COUNT(*) FROM sat_wave_export_items WHERE batch_id = ? AND item_status IN ('exported', 'exporting')) AS sent,
                (SELECT COUNT(*) FROM sat_wave_export_attempts a JOIN sat_wave_export_items i ON i.item_id = a.item_id WHERE i.batch_id = ?) AS attempts"
        );
        $q->execute([$batch_id, $batch_id]);
        $row = $q->fetch() ?: [];
        if ((int)($row['sent'] ?? 0) > 0) {
            return 'This batch has items exported or being exported, so it is kept as an audit record.';
        }
        if ((int)($row['attempts'] ?? 0) > 0) {
            return 'Export was attempted for this batch, so it is kept as an audit record.';
        }
        return '';
    }

    /** Delete an untouched preview so its transactions return to Ready for Wave and can be previewed again. */
    public function action_discard(): void
    {
        $this->guard();
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        try {
            $db = $this->db();
            $db->beginTransaction();
            try {
                $this->lock_batch($batch_id);
                $blocker = $this->discard_blocker($batch_id);
                if ($blocker !== '') {
                    throw new RuntimeException($blocker);
                }
                $items = 'SELECT item_id FROM sat_wave_export_items WHERE batch_id = ?';
                $db->prepare("DELETE FROM sat_wave_export_lines WHERE item_id IN ($items)")->execute([$batch_id]);
                $db->prepare("DELETE FROM sat_wave_export_sources WHERE item_id IN ($items)")->execute([$batch_id]);
                $db->prepare('DELETE FROM sat_wave_export_items WHERE batch_id = ?')->execute([$batch_id]);
                $db->prepare('DELETE FROM sat_wave_export_batches WHERE batch_id = ?')->execute([$batch_id]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            $this->redirect(0, 'success', 'Preview #' . $batch_id . ' discarded. Its transactions are back in Ready for Wave and can be previewed again.');
        } catch (Throwable $e) {
            $this->redirect($batch_id, 'error', $e->getMessage());
        }
    }

    public function action_save_preview(): void
    {
        $this->guard();
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        try {
            $descriptions = (array)($_POST['description'] ?? []);
            $notes = (array)($_POST['item_note'] ?? []);
            $line_notes = (array)($_POST['line_note'] ?? []);
            $corrected = 0;
            $db = $this->db();
            $db->beginTransaction();
            try {
                $batch = $this->lock_batch($batch_id);
                if ($batch['batch_status'] === 'completed') {
                    throw new RuntimeException('This preview is not editable.');
                }
                foreach ($this->items($batch_id) as $item) {
                    if (!in_array($item['item_status'], ['pending', 'failed'], true)) {
                        continue;
                    }
                    $item_id = (int)$item['item_id'];
                    if (array_key_exists($item_id, $descriptions)) {
                        $description = $this->clean_text(sanitize_text_field(wp_unslash($descriptions[$item_id])));
                        $note = $this->clean_text(sanitize_text_field(wp_unslash($notes[$item_id] ?? '')));
                        $db->prepare('UPDATE sat_wave_export_items SET description = ?, item_note = ? WHERE item_id = ?')
                            ->execute([$description, $note !== '' ? $note : null, $item_id]);
                    }
                    foreach ($this->lines($item_id) as $line) {
                        $line_id = (int)$line['line_id'];
                        if (array_key_exists($line_id, $line_notes)) {
                            $line_note = $this->clean_text(sanitize_text_field(wp_unslash($line_notes[$line_id])));
                            $db->prepare('UPDATE sat_wave_export_lines SET line_note = ? WHERE line_id = ?')
                                ->execute([$line_note !== '' ? $line_note : null, $line_id]);
                        }
                    }
                    $corrected += $this->re_resolve_accounts($item);
                    $this->refresh_validation($item_id);
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            $this->redirect($batch_id, 'success', 'Descriptions and notes saved. Validation refreshed.'
                . ($corrected > 0 ? ' Accounting direction recalculated on ' . $corrected . ' line(s); review the line directions before exporting.' : ''));
        } catch (Throwable $e) {
            $this->redirect($batch_id, 'error', $e->getMessage());
        }
    }

    private function wave_description(array $item): string
    {
        return $this->clean_text($item['description']);
    }

    private function wave_input(array $item, array $lines): array
    {
        $line_items = [];
        foreach ($lines as $line) {
            $line_items[] = [
                'accountId' => $line['account_id'],
                'amount' => (float)$line['amount'],
                'balance' => $line['balance_direction'],
            ];
        }
        return [
            'businessId' => $item['wave_business_id'],
            'externalId' => $item['external_id'],
            'date' => $item['transaction_date'],
            'description' => $this->wave_description($item),
            'notes' => trim((string)($item['item_note'] ?? '')),
            'anchor' => [
                'accountId' => $item['anchor_account_id'],
                'amount' => (float)$item['amount'],
                'direction' => $item['anchor_direction'],
            ],
            'lineItems' => $line_items,
        ];
    }

    private function update_batch_status(int $batch_id): void
    {
        $q = $this->db()->prepare(
            "SELECT COUNT(*) total,
                    SUM(item_status = 'exported') exported,
                    SUM(item_status = 'failed') failed,
                    SUM(item_status = 'exporting') exporting
             FROM sat_wave_export_items WHERE batch_id = ?"
        );
        $q->execute([$batch_id]);
        $counts = $q->fetch() ?: [];
        $total = (int)($counts['total'] ?? 0);
        $exported = (int)($counts['exported'] ?? 0);
        $failed = (int)($counts['failed'] ?? 0);
        $exporting = (int)($counts['exporting'] ?? 0);
        if ($total > 0 && $exported === $total) {
            $status = 'completed';
        } elseif ($failed > 0 || $exported > 0) {
            $status = 'partial';
        } elseif ($exporting > 0) {
            $status = 'exporting';
        } else {
            $status = 'preview';
        }
        $completed_at = $status === 'completed' ? current_time('mysql') : null;
        $this->db()->prepare(
            'UPDATE sat_wave_export_batches
             SET batch_status = ?, item_count = ?, exported_count = ?, failed_count = ?, completed_at = ?
             WHERE batch_id = ?'
        )->execute([$status, $total, $exported, $failed, $completed_at, $batch_id]);
    }

    /** Mark abandoned requests retryable without changing their external IDs. */
    private function recover_stale_exports(): void
    {
        $db = $this->db();
        $batch_ids = $db->query(
            "SELECT DISTINCT batch_id FROM sat_wave_export_items
             WHERE item_status = 'exporting' AND attempted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$batch_ids) {
            return;
        }
        $message = 'The previous export request did not finish. Verify Wave if necessary, then retry with the same external ID.';
        $db->prepare(
            "UPDATE sat_wave_export_items SET item_status = 'failed', last_error = ?
             WHERE item_status = 'exporting' AND attempted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)"
        )->execute([$message]);
        $db->prepare(
            "UPDATE sat_wave_export_attempts SET attempt_status = 'interrupted', error_message = ?, finished_at = CURRENT_TIMESTAMP
             WHERE attempt_status = 'started' AND started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)"
        )->execute([$message]);
        foreach ($batch_ids as $batch_id) {
            $this->update_batch_status((int)$batch_id);
        }
    }

    private function record_failure(array $item, int $attempt_id, string $message): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE sat_wave_export_items SET item_status = 'failed', last_error = ? WHERE item_id = ?")
                ->execute([$message, $item['item_id']]);
            $db->prepare(
                "UPDATE sat_wave_export_attempts
                 SET attempt_status = 'failed', error_message = ?, finished_at = CURRENT_TIMESTAMP
                 WHERE attempt_id = ?"
            )->execute([$message, $attempt_id]);
            foreach ($this->source_ids($item) as $id) {
                $db->prepare(
                    "INSERT INTO inbox_reviews
                     (bank_observation_id, review_status, wave_external_id, last_export_error, reviewed_by, reviewed_at)
                     VALUES (?, 'ready', ?, ?, ?, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE wave_external_id = VALUES(wave_external_id),
                       last_export_error = VALUES(last_export_error), reviewed_by = VALUES(reviewed_by),
                       reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP"
                )->execute([$id, $item['external_id'], $message, get_current_user_id()]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $this->update_batch_status((int)$item['batch_id']);
    }

    private function record_success(array $item, int $attempt_id, string $wave_id): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE sat_wave_export_items
                 SET item_status = 'exported', wave_transaction_id = ?, last_error = NULL, exported_at = CURRENT_TIMESTAMP
                 WHERE item_id = ?"
            )->execute([$wave_id, $item['item_id']]);
            $db->prepare(
                "UPDATE sat_wave_export_attempts
                 SET attempt_status = 'exported', wave_transaction_id = ?, error_message = NULL, finished_at = CURRENT_TIMESTAMP
                 WHERE attempt_id = ?"
            )->execute([$wave_id, $attempt_id]);
            foreach ($this->source_ids($item) as $id) {
                $db->prepare(
                    "INSERT INTO inbox_reviews
                     (bank_observation_id, review_status, final_description, wave_transaction_id, wave_external_id,
                      last_export_error, exported_at, reviewed_by, reviewed_at)
                     VALUES (?, 'exported', ?, ?, ?, NULL, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE review_status = 'exported',
                       final_description = VALUES(final_description),
                       wave_transaction_id = VALUES(wave_transaction_id),
                       wave_external_id = VALUES(wave_external_id), last_export_error = NULL,
                       exported_at = CURRENT_TIMESTAMP, reviewed_by = VALUES(reviewed_by),
                       reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP"
                )->execute([$id, $this->wave_description($item), $wave_id, $item['external_id'], get_current_user_id()]);
                $db->prepare('UPDATE inbox_observations SET wave_transaction_id = ? WHERE observation_id = ?')
                    ->execute([$wave_id, $id]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $this->update_batch_status((int)$item['batch_id']);
    }

    /** Serialize discard, editing and the start of an export on the same batch. */
    private function lock_batch(int $batch_id): array
    {
        $q = $this->db()->prepare('SELECT * FROM sat_wave_export_batches WHERE batch_id = ? FOR UPDATE');
        $q->execute([$batch_id]);
        $batch = $q->fetch();
        if (!$batch) {
            throw new RuntimeException('Wave export batch not found.');
        }
        return $batch;
    }

    /**
     * Export one frozen preview item through the single Wave mutation path.
     *
     * Returns true when this call created the Wave transaction and false when the item was
     * already exported. Throws when validation fails, the item is already being exported, or
     * Wave fails or returns an uncertain result; the attempt is recorded either way.
     */
    private function export_item(int $batch_id, int $item_id): bool
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $this->lock_batch($batch_id);
            $q = $db->prepare('SELECT * FROM sat_wave_export_items WHERE item_id = ? AND batch_id = ? FOR UPDATE');
            $q->execute([$item_id, $batch_id]);
            $item = $q->fetch();
            if (!$item) {
                throw new RuntimeException('Export item not found.');
            }
            if ($item['item_status'] === 'exported') {
                $db->commit();
                return false;
            }
            if ($item['item_status'] === 'exporting') {
                throw new RuntimeException('This item is already being exported.');
            }
            $errors = $this->validate_item($item, $this->lines($item_id), $this->sources($item_id));
            $db->prepare('UPDATE sat_wave_export_items SET validation_errors = ? WHERE item_id = ?')
                ->execute([$this->errors_json($errors), $item_id]);
            if ($errors) {
                $db->commit();
                throw new RuntimeException('Validation failed: ' . implode(' ', $errors));
            }
            $attempt_number = (int)$item['attempt_count'] + 1;
            $db->prepare(
                "INSERT INTO sat_wave_export_attempts
                 (item_id, attempt_number, attempt_status, external_id, started_by)
                 VALUES (?, ?, 'started', ?, ?)"
            )->execute([$item_id, $attempt_number, $item['external_id'], get_current_user_id()]);
            $attempt_id = (int)$db->lastInsertId();
            $db->prepare(
                "UPDATE sat_wave_export_items
                 SET item_status = 'exporting', last_error = NULL,
                   attempt_count = ?, attempted_at = CURRENT_TIMESTAMP
                 WHERE item_id = ?"
            )->execute([$attempt_number, $item_id]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $item = $this->item($item_id);
        $result = SAT_Wave::instance()->create_money_transaction($this->wave_input($item, $this->lines($item_id)));
        if (is_wp_error($result)) {
            $message = $this->clean_text($result->get_error_message(), 4000);
            $this->record_failure($item, $attempt_id, $message);
            throw new RuntimeException('Wave export failed. Retry will reuse the same external ID. ' . $message);
        }
        $wave_id = $this->clean_text($result['id'] ?? '');
        if ($wave_id === '') {
            $message = 'Wave reported success without returning a transaction ID.';
            $this->record_failure($item, $attempt_id, $message);
            throw new RuntimeException($message);
        }
        $this->record_success($item, $attempt_id, $wave_id);
        return true;
    }

    public function action_export_one(): void
    {
        $this->guard();
        $this->recover_stale_exports();
        $item_id = (int)($_POST['item_id'] ?? 0);
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        try {
            $exported = $this->export_item($batch_id, $item_id);
            $type = 'success';
            $message = $exported ? 'One transaction exported to Wave.' : 'This item was already exported.';
        } catch (Throwable $e) {
            $type = 'error';
            $message = $e->getMessage();
        }
        $this->redirect($batch_id, $type, $message);
    }

    /**
     * Process preview items one at a time in sort order, stopping at the first failure.
     *
     * Already-exported items are skipped, and items after a failure are left unattempted so a
     * later run resumes with them. $send returns true when it exported the item, false when it
     * found the item already exported, and throws on any failed or uncertain export.
     */
    public static function run_sequence(array $items, callable $send): array
    {
        usort($items, static function (array $a, array $b): int {
            return [(int)$a['sort_order'], (int)$a['item_id']] <=> [(int)$b['sort_order'], (int)$b['item_id']];
        });
        $result = [
            'total' => count($items),
            'exported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'remaining' => 0,
            'attempted_item_ids' => [],
            'failed_item' => null,
            'error' => '',
        ];
        foreach ($items as $item) {
            if (($item['item_status'] ?? '') === 'exported') {
                $result['skipped']++;
                continue;
            }
            if ($result['failed'] > 0) {
                $result['remaining']++;
                continue;
            }
            $result['attempted_item_ids'][] = (int)$item['item_id'];
            try {
                if ($send($item)) {
                    $result['exported']++;
                } else {
                    $result['skipped']++;
                }
            } catch (Throwable $e) {
                $result['failed'] = 1;
                $result['failed_item'] = (int)$item['sort_order'];
                $result['error'] = $e->getMessage();
            }
        }
        return $result;
    }

    /** [notice type, message] for a Send all result. */
    public static function send_all_notice(array $result): array
    {
        if ($result['exported'] === 0 && $result['failed'] === 0) {
            return ['success', 'Nothing to send: every item in this preview is already exported.'];
        }
        $message = sprintf(
            'Send all: %d exported, %d failed, %d remaining.',
            $result['exported'],
            $result['failed'],
            $result['remaining']
        );
        if ($result['skipped'] > 0) {
            $message .= ' ' . $result['skipped'] . ' already exported and skipped.';
        }
        if ($result['failed'] > 0) {
            $message .= ' Stopped at item ' . $result['failed_item'] . ': ' . $result['error']
                . ' Later items were not attempted. Resolve or verify the failed item, then use Send all again to resume or Retry one on that item.';
            return ['error', $message];
        }
        return ['success', $message . ' All items in this preview are now exported.'];
    }

    /** Send every unexported item of one preview, in order, through export_item(). Not atomic across Wave calls. */
    public function action_export_all(): void
    {
        $this->guard(self::NONCE_ALL);
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        try {
            $this->recover_stale_exports();
            if (!$this->batch($batch_id)) {
                throw new RuntimeException('Wave export batch not found.');
            }
            // Leaving the page must not abandon an item mid-request in the exporting state.
            ignore_user_abort(true);
            $result = self::run_sequence($this->items($batch_id), function (array $item) use ($batch_id): bool {
                if (function_exists('set_time_limit')) {
                    @set_time_limit(60);
                }
                return $this->export_item($batch_id, (int)$item['item_id']);
            });
            [$type, $message] = self::send_all_notice($result);
        } catch (Throwable $e) {
            $type = 'error';
            $message = $e->getMessage();
        }
        $this->redirect($batch_id, $type, $message);
    }

    public function action_csv(): void
    {
        $this->guard();
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        $kind = sanitize_key(wp_unslash($_POST['csv_kind'] ?? 'preview'));
        if (!in_array($kind, ['preview', 'result'], true) || !$this->batch($batch_id)) {
            wp_die('Export batch not found.', 'Scout Accounting', ['response' => 404]);
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sat-wave-batch-' . $batch_id . '-' . $kind . '.csv"');
        $stream = fopen('php://output', 'w');
        fputcsv($stream, [
            'batch_id', 'item_order', 'item_kind', 'source_observation_ids', 'source_systems',
            'wave_business_id', 'wave_business_name', 'transaction_date', 'source_label',
            'anchor_account', 'anchor_direction', 'amount', 'description', 'note', 'external_id',
            'line_number', 'line_account', 'line_amount', 'line_balance', 'line_note',
            'validation_errors', 'status', 'wave_transaction_id', 'last_error',
            'attempt_count', 'attempted_at', 'exported_at',
        ]);
        foreach ($this->items($batch_id) as $item) {
            $sources = $this->sources((int)$item['item_id']);
            $source_ids = implode('|', array_column($sources, 'observation_id'));
            $source_systems = implode('|', array_values(array_unique(array_column($sources, 'source_system'))));
            $validation = implode(' | ', $this->errors_array($item['validation_errors']));
            foreach ($this->lines((int)$item['item_id']) as $line) {
                fputcsv($stream, [
                    $batch_id,
                    $item['sort_order'],
                    $item['item_kind'],
                    $source_ids,
                    $source_systems,
                    $item['wave_business_id'],
                    $item['wave_business_name'],
                    $item['transaction_date'],
                    $item['source_label'],
                    $item['anchor_account_name'],
                    $item['anchor_direction'],
                    $item['amount'],
                    $item['description'],
                    $item['item_note'],
                    $item['external_id'],
                    $line['line_number'],
                    $line['account_name'],
                    $line['amount'],
                    $line['balance_direction'],
                    $line['line_note'],
                    $validation,
                    $kind === 'result' ? $item['item_status'] : 'preview',
                    $kind === 'result' ? $item['wave_transaction_id'] : '',
                    $kind === 'result' ? $item['last_error'] : '',
                    $kind === 'result' ? $item['attempt_count'] : '',
                    $kind === 'result' ? $item['attempted_at'] : '',
                    $kind === 'result' ? $item['exported_at'] : '',
                ]);
            }
        }
        fclose($stream);
        exit;
    }

    private function hidden(string $action, int $batch_id = 0, string $nonce = self::NONCE): void
    {
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        if ($batch_id > 0) {
            echo '<input type="hidden" name="batch_id" value="' . $batch_id . '">';
        }
        echo '<input type="hidden" name="return_url" value="' . esc_url(remove_query_arg(['sat_notice', 'sat_message'])) . '">';
        wp_nonce_field($nonce);
    }

    /** Bottom action bar used only by the Ready for Wave view. */
    public function render_selection_form(): void
    {
        echo '<form id="sat-bulk-form" class="bulk" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $this->hidden(self::ACTION_CREATE);
        echo '<button type="submit" class="primary accept-selected">Create Wave preview</button>';
        echo '<span class="bulk-help">Freezes selected ready transactions. Accepted transfers between payment accounts are handled only in Transfers to Wave.</span></form>';
    }

    public function render(): void
    {
        $this->recover_stale_exports();
        $batch_id = (int)($_GET['sat_batch'] ?? 0);
        echo '<div class="sat-wave-export">';
        $this->notice();
        echo '<p class="help">Wave previews are persistent snapshots. Edit descriptions and notes, save to revalidate, then export one item at a time or send all ready items in order.</p>';
        if ($batch_id > 0) {
            $this->render_batch($batch_id);
        } else {
            $this->render_history();
        }
        echo '</div>';
    }

    private function notice(): void
    {
        if (isset($_GET['sat_notice'], $_GET['sat_message'])) {
            $type = sanitize_key(wp_unslash($_GET['sat_notice']));
            $message = sanitize_text_field(rawurldecode(wp_unslash($_GET['sat_message'])));
            echo '<div class="msg ' . esc_attr($type) . '">' . esc_html($message) . '</div>';
        }
    }

    private function render_history(): void
    {
        $rows = $this->db()->query('SELECT * FROM sat_wave_export_batches ORDER BY batch_id DESC')->fetchAll();
        echo '<h3>Wave export batch history</h3>';
        echo '<p>Create a batch by selecting transactions in <strong>Ready for Wave</strong>.</p>';
        echo '<div class="scroll"><table class="sat-wave-history"><thead><tr>';
        echo '<th>Batch</th><th>Created</th><th>Status</th><th>Items</th><th>Exported</th><th>Failed</th><th></th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $url = add_query_arg(['sat_view' => 'wave', 'sat_batch' => $row['batch_id']], remove_query_arg(['sat_batch', 'sat_notice', 'sat_message']));
            echo '<tr><td>#' . (int)$row['batch_id'] . '</td><td>' . esc_html($row['created_at']) . '</td>';
            echo '<td><span class="pill">' . esc_html(strtoupper((string)$row['batch_status'])) . '</span></td>';
            echo '<td>' . (int)$row['item_count'] . '</td><td>' . (int)$row['exported_count'] . '</td><td>' . (int)$row['failed_count'] . '</td>';
            echo '<td><a href="' . esc_url($url) . '">Open</a></td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="7" class="empty">No Wave export previews have been created.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function render_csv_actions(int $batch_id): void
    {
        foreach (['preview' => 'Download preview CSV', 'result' => 'Download result CSV'] as $kind => $label) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            $this->hidden(self::ACTION_CSV, $batch_id);
            echo '<input type="hidden" name="csv_kind" value="' . esc_attr($kind) . '">';
            echo '<button type="submit">' . esc_html($label) . '</button></form>';
        }
    }

    /** Batch actions, rendered above the horizontally scrolling preview so they are visible without scrolling. */
    private function render_action_bar(array $batch, array $items): void
    {
        $batch_id = (int)$batch['batch_id'];
        $unsent = 0;
        $invalid = 0;
        $failed = 0;
        foreach ($items as $item) {
            if ($item['item_status'] === 'exported') {
                continue;
            }
            $unsent++;
            if ($this->display_errors($item)) {
                $invalid++;
            }
            if ($item['item_status'] === 'failed') {
                $failed++;
            }
        }
        $post_url = esc_url(admin_url('admin-post.php'));
        echo '<div class="sat-wave-actionbar" role="toolbar" aria-label="Preview actions">';
        if ($unsent > 0) {
            $confirm = 'Send ' . $unsent . ' unexported item(s) to Wave, one at a time in order? Sending stops at the first failure; items already sent stay in Wave.';
            if ($failed > 0) {
                $confirm .= ' Failed items are retried with the same external ID, so first confirm in Wave that they were not created.';
            }
            echo '<form class="sat-wave-send-all-form" data-total="' . $unsent . '" method="post" action="' . $post_url . '" onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');">';
            $this->hidden(self::ACTION_ALL, $batch_id, self::NONCE_ALL);
            echo '<button type="submit" class="primary sat-wave-export-all"';
            if ($invalid > 0) {
                echo ' disabled title="' . esc_attr($invalid . ' unexported item(s) have validation errors. Resolve them and save the preview first.') . '"';
            }
            echo '>Send all ready items to Wave</button> <span class="sat-help">' . $unsent . ' to send</span></form>';
        }
        $this->render_csv_actions($batch_id);
        if ($this->discard_blocker($batch_id) === '') {
            echo '<form method="post" action="' . $post_url . '" onsubmit="return confirm(\'Discard this preview? Nothing has been sent to Wave.\');" title="Removes this preview so its transactions return to Ready for Wave. Only possible before any export is attempted.">';
            $this->hidden(self::ACTION_DISCARD, $batch_id);
            echo '<button type="submit" class="button-danger">Discard preview</button></form>';
        }
        echo '</div>';
    }

    private function render_batch(int $batch_id): void
    {
        $batch = $this->batch($batch_id);
        if (!$batch) {
            echo '<div class="msg error">Wave export batch not found.</div>';
            return;
        }
        $items = $this->items($batch_id);
        echo '<p><a href="' . esc_url(remove_query_arg('sat_batch')) . '">← Batch history</a></p>';
        echo '<h3>Wave export batch #' . $batch_id . '</h3>';
        echo '<p><strong>Status:</strong> ' . esc_html(ucfirst((string)$batch['batch_status']));
        echo ' · ' . (int)$batch['exported_count'] . '/' . (int)$batch['item_count'] . ' exported';
        echo ' · ' . (int)$batch['failed_count'] . ' failed</p>';
        $this->render_action_bar($batch, $items);
        $claim_changed = $batch['batch_status'] !== 'completed' ? $this->claim_changed_items($batch) : [];
        if ($claim_changed) {
            $orders = [];
            foreach ($items as $item) {
                if (isset($claim_changed[(int)$item['item_id']])) {
                    $orders[] = '#' . (int)$item['sort_order'];
                }
            }
            echo '<div class="msg warning sat-wave-claim-changed">Claim payee or description corrections were saved after this preview was created (items '
                . esc_html(implode(', ', $orders)) . '). The frozen descriptions are unchanged. Discard and recreate the preview to use the corrections (possible only before any export attempt), or edit those descriptions before exporting.</div>';
        }

        echo '<form id="sat-wave-preview-edit" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $this->hidden(self::ACTION_SAVE, $batch_id);
        echo '<div class="scroll"><table class="sat-wave-preview"><thead><tr>';
        echo '<th>Date / source</th><th>Anchor</th><th class="money">Amount</th>';
        echo '<th>Description / note / external ID</th><th>Lines</th><th>Validation / result</th>';
        echo '</tr></thead>';

        foreach ($items as $item) {
            $item_id = (int)$item['item_id'];
            $editable = in_array($item['item_status'], ['pending', 'failed'], true);
            $errors = $this->display_errors($item);
            $sources = $this->sources($item_id);
            echo '<tbody class="sat-wave-item ' . esc_attr((string)$item['item_status']) . '" id="sat-wave-item-' . $item_id . '">';
            echo '<tr class="sat-wave-item-head"><th colspan="6" scope="rowgroup"><div class="sat-wave-item-bar">';
            echo '<span class="sat-wave-item-order">#' . (int)$item['sort_order'] . '</span>';
            echo '<span class="pill">' . esc_html(strtoupper((string)$item['item_kind'])) . '</span>';
            echo '<span class="pill sat-wave-status">' . esc_html(strtoupper((string)$item['item_status'])) . '</span>';
            if (isset($claim_changed[$item_id])) {
                echo '<span class="pill sat-wave-claim-changed">CLAIM CORRECTED</span>';
            }
            if ($editable) {
                echo '<button type="submit" class="primary sat-wave-export-one" form="sat-wave-export-' . $item_id . '"';
                if ($errors) {
                    echo ' disabled title="Resolve validation errors and save the preview first."';
                }
                echo ' onclick="return confirm(\'Create this one transaction in Wave?\');">';
                echo $item['item_status'] === 'failed' ? 'Retry one' : 'Export one';
                echo '</button>';
            }
            echo '<span class="sat-wave-item-summary"><strong>' . esc_html($item['description']) . '</strong>';
            echo ' <span class="sat-help">' . esc_html($item['transaction_date']) . ' · $' . number_format((float)$item['amount'], 2) . '</span></span>';
            echo '</div></th></tr>';

            echo '<tr class="sat-wave-item-detail">';
            echo '<td>' . esc_html($item['transaction_date']) . '<br>' . esc_html($item['source_label']) . '<br><small>Inbox ';
            echo esc_html(implode(', ', array_map(static function ($source) {
                return '#' . $source['observation_id'];
            }, $sources))) . '</small></td>';
            echo '<td>' . esc_html($item['anchor_account_name'] ?: '(unmapped)') . '<br><small>' . esc_html($item['anchor_direction']) . '</small></td>';
            echo '<td class="money">$' . number_format((float)$item['amount'], 2) . '</td><td>';
            if ($editable) {
                echo '<label>Description <input class="sat-wave-description" name="description[' . $item_id . ']" maxlength="255" required value="' . esc_attr($item['description']) . '"></label>';
                echo '<label>Note <input class="sat-wave-note" name="item_note[' . $item_id . ']" maxlength="255" value="' . esc_attr($item['item_note']) . '"></label>';
            } else {
                echo '<strong>' . esc_html($item['description']) . '</strong>';
                if ($item['item_note']) {
                    echo '<br><small>' . esc_html($item['item_note']) . '</small>';
                }
            }
            echo '<br><code>' . esc_html($item['external_id']) . '</code></td><td><ol class="sat-wave-lines">';
            foreach ($this->lines($item_id) as $line) {
                echo '<li><strong>' . esc_html($line['account_name']) . '</strong> · $' . number_format((float)$line['amount'], 2) . ' · ' . esc_html($line['balance_direction']) . '<br>';
                if ($editable) {
                    echo '<label>Line note <input name="line_note[' . (int)$line['line_id'] . ']" maxlength="255" value="' . esc_attr($line['line_note']) . '"></label>';
                } elseif ($line['line_note']) {
                    echo '<small>' . esc_html($line['line_note']) . '</small>';
                }
                echo '</li>';
            }
            echo '</ol></td><td>';
            if ($errors) {
                echo '<div class="sat-wave-errors">' . implode('<br>', array_map('esc_html', $errors)) . '</div>';
            } else {
                echo '<span class="sat-wave-valid">Valid</span>';
            }
            if ($item['wave_transaction_id']) {
                echo '<br><code>' . esc_html($item['wave_transaction_id']) . '</code>';
            }
            if ($item['last_error']) {
                echo '<div class="sat-wave-errors">' . esc_html($item['last_error']) . '</div>';
            }
            $attempts = $this->attempts($item_id);
            if ($attempts) {
                echo '<details class="sat-wave-attempts"><summary>Attempts (' . count($attempts) . ')</summary><ul>';
                foreach ($attempts as $attempt) {
                    echo '<li>#' . (int)$attempt['attempt_number'] . ' · ' . esc_html(strtoupper((string)$attempt['attempt_status']));
                    echo ' · ' . esc_html((string)$attempt['started_at']);
                    if ($attempt['wave_transaction_id']) {
                        echo ' · <code>' . esc_html($attempt['wave_transaction_id']) . '</code>';
                    }
                    if ($attempt['error_message']) {
                        echo '<br><small>' . esc_html($attempt['error_message']) . '</small>';
                    }
                    echo '</li>';
                }
                echo '</ul></details>';
            }
            echo '</td></tr></tbody>';
        }
        echo '</table></div>';
        if ($batch['batch_status'] !== 'completed') {
            echo '<p><button type="submit">Save descriptions and notes; revalidate</button></p>';
        }
        echo '</form>';

        foreach ($items as $item) {
            if (!in_array($item['item_status'], ['pending', 'failed'], true)) {
                continue;
            }
            $item_id = (int)$item['item_id'];
            echo '<form id="sat-wave-export-' . $item_id . '" class="sat-hidden-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            $this->hidden(self::ACTION_ONE, $batch_id);
            echo '<input type="hidden" name="item_id" value="' . $item_id . '"></form>';
        }

        if ($batch['batch_status'] === 'completed') {
            echo '<p class="msg success"><strong>Batch complete.</strong> All source transactions are locked as exported.</p>';
        }
        echo '<p class="sat-wave-api-note"><strong>Scope:</strong> Export one makes one direct Wave money-transaction mutation for one item. Send all repeats that same single-item request for each unexported item in order and stops at the first failure; it is not atomic, so items sent before a failure stay in Wave. The description and note are sent to the Wave description and notes fields; line notes remain in SAT history and the CSV audit files. No scheduled export, tax processing, contact matching, receipt upload, Wave update/delete, bulk mutation, or OFX export is performed.</p>';
    }
}
