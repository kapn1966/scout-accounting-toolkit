<?php
/**
 * Expense claim corrections: SAT's audited corrections of a claim's Expense Date, Payee,
 * Description and Amount.
 *
 * The imported source (the Gravity Forms payload and observation columns) is never changed.
 * The effective value of a field is its active correction when there is one, otherwise the
 * current imported value. Every consumer obtains these four values through resolve() or
 * effective_values(); no other code decides which value wins.
 *
 * Active corrections live in sat_observation_corrections (one per claim and field). Every change
 * appends an immutable row to sat_observation_correction_events. A backfill that changes an
 * imported value is reconciled in the same transaction (reconcile()), keeping the correction
 * effective and flagging a conflict for review when the new value differs from both the value
 * captured with the correction and the corrected value.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** A refusal whose message (and per-field messages) are safe to show to the reviewer. */
final class SAT_Claim_Correction_Error extends RuntimeException
{
    public function __construct(string $message, private int $status = 400, private array $field_errors = [], private string $kind = 'invalid')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function field_errors(): array
    {
        return $this->field_errors;
    }

    public function kind(): string
    {
        return $this->kind;
    }
}

final class SAT_Claim_Corrections
{
    /** The only correctable fields, keyed by stored field name. */
    public const FIELDS = [
        'transaction_date' => 'Expense Date',
        'payee' => 'Payee',
        'description' => 'Description',
        'amount' => 'Amount',
    ];

    /** Stable reason codes and their display labels. */
    public const REASONS = [
        'other' => 'Other',
        'data_entry' => 'Data entry error',
        'ocr_extraction' => 'OCR or extraction error',
        'missing_source' => 'Missing source value',
    ];
    public const DEFAULT_REASON = 'other';

    /** Limits of the expense form's Payee and Description fields. */
    public const PAYEE_MAX = 100;
    public const DESCRIPTION_MAX = 200;
    /** Largest amount that inbox_observations.amount, decimal(10,2), can hold. */
    public const AMOUNT_MAX_CENTS = 9999999999;

    public const STATE_CLEAR = 'clear';
    public const STATE_AGREES = 'agrees';
    public const STATE_CONFLICT = 'conflict';

    public const EVENTS = [
        'created' => 'Correction created',
        'value_changed' => 'Corrected value changed',
        'reset' => 'Reset to imported value',
        'source_conflict' => 'Imported value changed; review needed',
        'conflict_kept' => 'SAT correction kept after review',
        'source_agrees' => 'Imported value now matches the correction',
        'source_restored' => 'Imported value returned to the value captured with the correction',
        'observation_deleted' => 'Removed with the claim by cleanup or reset',
    ];

    public const AJAX_LOAD = 'sat_claim_correction_load';
    public const AJAX_SAVE = 'sat_claim_correction_save';
    public const AJAX_FIELD = 'sat_claim_correction_field';
    public const NONCE_READ = 'sat_claim_correction_read';
    public const NONCE_WRITE = 'sat_claim_correction_write';

    public const UNAVAILABLE = 'Claim corrections need the database tables from database/migrations/003-claim-corrections.sql.';

    private const COLUMNS = 'correction_id, observation_id, field_name, corrected_value, source_value, observed_source_value, reason_code, source_state, version, created_by, created_at, updated_by, updated_at';

    private static ?self $instance = null;
    private static ?array $cache = null;
    private static bool $unavailable = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('wp_ajax_' . self::AJAX_LOAD, [$this, 'ajax_load']);
        add_action('wp_ajax_' . self::AJAX_SAVE, [$this, 'ajax_save']);
        add_action('wp_ajax_' . self::AJAX_FIELD, [$this, 'ajax_field']);
    }

    /* ------------------------------------------------------------------ */
    /* Values                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Canonical positive amount ("1234.50") from a stored or imported decimal, or '' when unusable.
     * Decimal strings are handled as text, never through binary floating point.
     */
    public static function decimal_amount(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string)$value;
        } elseif (is_float($value)) {
            $value = is_finite($value) ? sprintf('%.2F', $value) : '';
        }
        if (!is_string($value) || preg_match('/^[+-]?(\d+)(?:\.(\d*))?$/D', trim($value), $m) !== 1) {
            return '';
        }
        $fraction = $m[2] ?? '';
        if (strlen(rtrim($fraction, '0')) > 2) {
            return '';
        }
        $whole = ltrim($m[1], '0');
        return ($whole === '' ? '0' : $whole) . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function amount_cents(string $amount): int
    {
        $amount = self::decimal_amount($amount);
        return $amount === '' ? 0 : (int)str_replace('.', '', $amount);
    }

    public static function cents_amount(int $cents): string
    {
        return sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    /** Current imported values of a claim observation row. */
    public static function source_values(array $row): array
    {
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        return [
            'transaction_date' => (string)($row['transaction_date'] ?? ''),
            'payee' => (string)($payload['payee'] ?? ''),
            'description' => (string)($payload['description'] ?? ($row['description'] ?? '')),
            'amount' => self::decimal_amount($row['amount'] ?? ''),
        ];
    }

    /** Comparison form: amounts by cents, descriptions with uniform line endings, other text exactly. */
    private static function comparable(string $field, string $value): string
    {
        if ($field === 'amount') {
            $amount = self::decimal_amount($value);
            return $amount === '' ? 'invalid:' . $value : $amount;
        }
        return $field === 'description' ? str_replace(["\r\n", "\r"], "\n", $value) : $value;
    }

    public static function same(string $field, string $a, string $b): bool
    {
        return self::comparable($field, $a) === self::comparable($field, $b);
    }

    /** clear: source unchanged since captured; agrees: source now equals the correction; conflict: a third value. */
    public static function state_for(string $field, string $captured, string $current, string $corrected): string
    {
        if (self::same($field, $current, $captured)) {
            return self::STATE_CLEAR;
        }
        return self::same($field, $current, $corrected) ? self::STATE_AGREES : self::STATE_CONFLICT;
    }

    private static function trim_text(string $value): string
    {
        return (string)preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $value);
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * Server-side validation of one submitted value. Returns [canonical value, error message].
     * Dates are strict YYYY-MM-DD; text keeps the entered capitalization and punctuation.
     */
    public static function validate_value(string $field, mixed $raw): array
    {
        if (!isset(self::FIELDS[$field])) {
            return ['', 'This field cannot be corrected.'];
        }
        if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
            return ['', 'Enter a valid value.'];
        }
        switch ($field) {
            case 'transaction_date':
                if (preg_match('/^[12]\d{3}-\d{2}-\d{2}$/D', $raw) !== 1) {
                    return ['', 'Enter the Expense Date as YYYY-MM-DD.'];
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));
                if ($date === false || $date->format('Y-m-d') !== $raw) {
                    return ['', 'Enter a real calendar date.'];
                }
                return [$raw, ''];

            case 'amount':
                if (preg_match('/^\$?(\d{1,9})(?:\.(\d{1,2}))?$/D', trim($raw), $m) !== 1) {
                    return ['', 'Enter a positive amount with at most two decimal places, for example 28.91.'];
                }
                $cents = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
                if ($cents <= 0) {
                    return ['', 'The amount must be greater than zero.'];
                }
                if ($cents > self::AMOUNT_MAX_CENTS) {
                    return ['', 'The amount must not exceed $' . number_format(self::AMOUNT_MAX_CENTS / 100, 2) . '.'];
                }
                return [self::cents_amount($cents), ''];

            case 'payee':
                $text = self::trim_text($raw);
                if ($text === '') {
                    return ['', 'Enter the payee.'];
                }
                if (preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
                    return ['', 'The payee must be one line without control characters.'];
                }
                if (self::length($text) > self::PAYEE_MAX) {
                    return ['', 'The payee must be at most ' . self::PAYEE_MAX . ' characters.'];
                }
                return [$text, ''];

            default:
                $text = self::trim_text(str_replace(["\r\n", "\r"], "\n", $raw));
                if ($text === '') {
                    return ['', 'Enter a description. The expense form requires one.'];
                }
                if (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $text) === 1) {
                    return ['', 'The description must not contain control characters.'];
                }
                if (self::length($text) > self::DESCRIPTION_MAX) {
                    return ['', 'The description must be at most ' . self::DESCRIPTION_MAX . ' characters.'];
                }
                return [$text, ''];
        }
    }

    /* ------------------------------------------------------------------ */
    /* Resolver                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Source, correction and effective value of every correctable field.
     *
     * @param array $source      field => current imported value (source_values())
     * @param array $corrections field => active correction row for this claim
     */
    public static function resolve(array $source, array $corrections): array
    {
        $fields = [];
        $corrected = false;
        $review = false;
        foreach (self::FIELDS as $field => $label) {
            $current = (string)($source[$field] ?? '');
            $c = $corrections[$field] ?? null;
            $active = is_array($c);
            $state = $active ? self::state_for($field, (string)$c['source_value'], $current, (string)$c['corrected_value']) : self::STATE_CLEAR;
            $fields[$field] = [
                'field' => $field,
                'label' => $label,
                'source' => $current,
                'corrected' => $active ? (string)$c['corrected_value'] : null,
                'effective' => $active ? (string)$c['corrected_value'] : $current,
                'active' => $active,
                'reason' => $active ? (string)$c['reason_code'] : '',
                'state' => $state,
                'captured_source' => $active ? (string)$c['source_value'] : null,
                'created_by' => $active ? (int)($c['created_by'] ?? 0) : 0,
                'created_at' => $active ? (string)($c['created_at'] ?? '') : '',
                'updated_by' => $active ? (int)($c['updated_by'] ?? 0) : 0,
                'updated_at' => $active ? (string)($c['updated_at'] ?? '') : '',
            ];
            $corrected = $corrected || $active;
            $review = $review || $state === self::STATE_CONFLICT;
        }
        return ['fields' => $fields, 'corrected' => $corrected, 'review' => $review, 'token' => self::token($source, $corrections)];
    }

    /** field => effective value. */
    public static function effective(array $resolved): array
    {
        return array_map(static fn (array $f): string => $f['effective'], $resolved['fields']);
    }

    /**
     * Optimistic-concurrency token for a modal: changes when any imported value or any active
     * correction changes (correction IDs are never reused, versions only increase).
     */
    public static function token(array $source, array $corrections): string
    {
        $parts = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $c = $corrections[$field] ?? null;
            $parts[] = [
                $field,
                self::comparable($field, (string)($source[$field] ?? '')),
                is_array($c) ? [(int)$c['correction_id'], (int)$c['version'], (string)$c['corrected_value'], (string)$c['reason_code']] : null,
            ];
        }
        return substr(hash('sha256', (string)json_encode($parts)), 0, 40);
    }

    /** Effective values of a claim observation row, using this request's correction cache. */
    public static function effective_values(array $row): array
    {
        $id = (int)($row['observation_id'] ?? 0);
        return self::effective(self::resolve(self::source_values($row), self::all()[$id] ?? []));
    }

    /* ------------------------------------------------------------------ */
    /* Storage                                                             */
    /* ------------------------------------------------------------------ */

    /** Every active correction, observation ID => field => row, cached for the request. */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::load(SAT_DB::pdo(), null);
        }
        return self::$cache;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** False when the correction tables have not been created yet (corrections cannot exist then). */
    public static function available(): bool
    {
        self::all();
        return !self::$unavailable;
    }

    public static function is_missing_table(PDOException $e): bool
    {
        return (string)$e->getCode() === '42S02' || (int)($e->errorInfo[1] ?? 0) === 1146;
    }

    private static function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /**
     * Active corrections for the given observations (all when $ids is null).
     * A missing table means no corrections exist; it is an error only when $required.
     *
     * Never a locking read: every writer of a claim's corrections (save, reset, keep, import and
     * cleanup) first locks the claim's inbox_observations row, which serialises them per claim.
     * A plain read after that lock sees the latest committed corrections, and avoids InnoDB gap
     * locks that deadlock two concurrent first corrections of different claims.
     */
    public static function load(PDO $db, ?array $ids, bool $required = false): array
    {
        try {
            if ($ids === null) {
                $rows = $db->query('SELECT ' . self::COLUMNS . ' FROM sat_observation_corrections ORDER BY observation_id, field_name')->fetchAll();
            } else {
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
                sort($ids);
                $rows = [];
                foreach (array_chunk($ids, 500) as $chunk) {
                    $q = $db->prepare(
                        'SELECT ' . self::COLUMNS . ' FROM sat_observation_corrections WHERE observation_id IN (' . self::placeholders($chunk) . ')'
                        . ' ORDER BY observation_id, field_name'
                    );
                    $q->execute($chunk);
                    $rows = array_merge($rows, $q->fetchAll());
                }
            }
            self::$unavailable = false;
        } catch (PDOException $e) {
            if (!self::is_missing_table($e)) {
                throw $e;
            }
            self::$unavailable = true;
            if ($required) {
                throw new SAT_Claim_Correction_Error(self::UNAVAILABLE, 503, [], 'unavailable');
            }
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (isset(self::FIELDS[(string)$row['field_name']])) {
                $out[(int)$row['observation_id']][(string)$row['field_name']] = $row;
            }
        }
        return $out;
    }

    /** Number of active corrections, optionally for some observations. 0 when the tables are missing. */
    public static function count_active(PDO $db, ?array $ids = null): int
    {
        if ($ids === null) {
            try {
                return (int)$db->query('SELECT COUNT(*) FROM sat_observation_corrections')->fetchColumn();
            } catch (PDOException $e) {
                if (self::is_missing_table($e)) {
                    return 0;
                }
                throw $e;
            }
        }
        $count = 0;
        foreach (self::load($db, $ids) as $fields) {
            $count += count($fields);
        }
        return $count;
    }

    private static function event(PDO $db, int $observation_id, int $entry_id, string $field, string $type, ?string $old, ?string $new, ?string $source, ?string $captured, ?string $reason, int $user): void
    {
        $db->prepare(
            'INSERT INTO sat_observation_correction_events
                (observation_id, gf_entry_id, field_name, event_type, old_effective_value, new_effective_value, source_value, captured_source_value, reason_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$observation_id, $entry_id > 0 ? $entry_id : null, $field, $type, $old, $new, $source, $captured, $reason, $user > 0 ? $user : null]);
    }

    /** Correction history of one claim, newest first. */
    public static function events(PDO $db, int $observation_id, int $limit = 50): array
    {
        $q = $db->prepare(
            'SELECT event_id, field_name, event_type, old_effective_value, new_effective_value, source_value, captured_source_value, reason_code, created_by, created_at
             FROM sat_observation_correction_events WHERE observation_id = ? ORDER BY event_id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $q->execute([$observation_id]);
        return $q->fetchAll();
    }

    /* ------------------------------------------------------------------ */
    /* Backfill reconciliation                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Reconcile a claim's corrections with freshly imported values. Must run inside the import
     * transaction, after the observation row was written (and so locked), so a refreshed source
     * never becomes visible without its conflict state. Corrections stay effective in every case.
     *
     * @return int Fields newly flagged as needing review.
     */
    public static function reconcile(PDO $db, int $observation_id, array $source, int $entry_id, int $user): int
    {
        if (!$db->inTransaction()) {
            throw new LogicException('Correction reconciliation requires the import transaction.');
        }
        $corrections = self::load($db, [$observation_id])[$observation_id] ?? [];
        $flagged = 0;
        foreach ($corrections as $field => $c) {
            $current = (string)($source[$field] ?? '');
            $state = self::state_for($field, (string)$c['source_value'], $current, (string)$c['corrected_value']);
            if (self::same($field, $current, (string)$c['observed_source_value']) && $state === (string)$c['source_state']) {
                continue;
            }
            $db->prepare('UPDATE sat_observation_corrections SET observed_source_value = ?, source_state = ?, version = version + 1 WHERE correction_id = ?')
                ->execute([$current, $state, (int)$c['correction_id']]);
            $type = [self::STATE_CONFLICT => 'source_conflict', self::STATE_AGREES => 'source_agrees', self::STATE_CLEAR => 'source_restored'][$state];
            self::event($db, $observation_id, $entry_id, $field, $type, (string)$c['corrected_value'], (string)$c['corrected_value'], $current, (string)$c['source_value'], (string)$c['reason_code'], $user);
            if ($state === self::STATE_CONFLICT) {
                $flagged++;
            }
        }
        if ($corrections) {
            self::forget();
        }
        return $flagged;
    }

    /**
     * Cleanup and reset: remove the active corrections of observations being deleted, recording an
     * observation_deleted event for each. Event history is kept. Requires the caller's transaction.
     *
     * @return int Active corrections removed.
     */
    public static function remove_for_observations(PDO $db, array $ids, int $user): int
    {
        if (!$db->inTransaction()) {
            throw new LogicException('Removing corrections requires an active transaction.');
        }
        $all = self::load($db, $ids);
        if (!$all) {
            return 0;
        }
        $claim_ids = array_keys($all);
        sort($claim_ids);
        $entries = [];
        foreach (array_chunk($claim_ids, 500) as $chunk) {
            $q = $db->prepare('SELECT observation_id, gf_entry_id FROM inbox_observations WHERE observation_id IN (' . self::placeholders($chunk) . ')');
            $q->execute($chunk);
            foreach ($q->fetchAll() as $row) {
                $entries[(int)$row['observation_id']] = (int)($row['gf_entry_id'] ?? 0);
            }
        }
        $removed = 0;
        foreach ($all as $observation_id => $fields) {
            foreach ($fields as $field => $c) {
                self::event($db, $observation_id, $entries[$observation_id] ?? 0, $field, 'observation_deleted', (string)$c['corrected_value'], null, (string)$c['observed_source_value'], (string)$c['source_value'], (string)$c['reason_code'], $user);
                $removed++;
            }
        }
        foreach (array_chunk($claim_ids, 500) as $chunk) {
            $db->prepare('DELETE FROM sat_observation_corrections WHERE observation_id IN (' . self::placeholders($chunk) . ')')->execute($chunk);
        }
        self::forget();
        return $removed;
    }

    /* ------------------------------------------------------------------ */
    /* Mutations                                                           */
    /* ------------------------------------------------------------------ */

    /** Lock the claim row (as the importer does), read its corrections and check the modal's token. */
    private static function lock_claim(PDO $db, int $observation_id, string $token): array
    {
        $q = $db->prepare(
            "SELECT observation_id, source_system, transaction_date, amount, description, gf_entry_id, payload_json
             FROM inbox_observations WHERE observation_id = ? AND source_system = 'gravity_forms' FOR UPDATE"
        );
        $q->execute([$observation_id]);
        $row = $q->fetch();
        if (!is_array($row)) {
            throw new SAT_Claim_Correction_Error('Expense claim not found. Refresh the page.', 404, [], 'not_found');
        }
        $corrections = self::load($db, [$observation_id], true)[$observation_id] ?? [];
        $source = self::source_values($row);
        if (!hash_equals(self::token($source, $corrections), $token)) {
            throw new SAT_Claim_Correction_Error('This claim changed after you opened it. The latest values are shown; review them and save again.', 409, [], 'stale');
        }
        return [$row, $source, $corrections];
    }

    private static function transaction(PDO $db, callable $work): array
    {
        $db->beginTransaction();
        try {
            $result = $work();
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        } finally {
            self::forget();
        }
        return $result;
    }

    /**
     * Save edited values. For each submitted field: unchanged values are left alone, a value equal
     * to the imported one removes the correction, anything else creates or updates it (capturing
     * the current imported value). The reason is stored per field, on the fields changed by this save.
     *
     * @return string[] Fields whose correction changed.
     */
    public static function save(PDO $db, int $observation_id, string $token, array $values, string $reason, int $user): array
    {
        $reason = $reason === '' ? self::DEFAULT_REASON : $reason;
        if (!isset(self::REASONS[$reason])) {
            throw new SAT_Claim_Correction_Error('Choose a valid correction reason.', 422, ['reason' => 'Choose a listed reason.']);
        }
        foreach (array_keys($values) as $field) {
            if (!isset(self::FIELDS[(string)$field])) {
                throw new SAT_Claim_Correction_Error('Only Expense Date, Payee, Description and Amount can be corrected.', 400);
            }
        }
        return self::transaction($db, static function () use ($db, $observation_id, $token, $values, $reason, $user): array {
            [$row, $source, $corrections] = self::lock_claim($db, $observation_id, $token);
            $resolved = self::resolve($source, $corrections);
            $entry = (int)($row['gf_entry_id'] ?? 0);
            $plan = [];
            $errors = [];
            foreach (array_keys(self::FIELDS) as $field) {
                if (!array_key_exists($field, $values)) {
                    continue;
                }
                $effective = $resolved['fields'][$field]['effective'];
                // Untouched fields come back verbatim and are not revalidated (imported values may predate these rules).
                if (is_string($values[$field]) && str_replace(["\r\n", "\r"], "\n", $values[$field]) === str_replace(["\r\n", "\r"], "\n", $effective)) {
                    continue;
                }
                [$value, $error] = self::validate_value($field, $values[$field]);
                if ($error !== '') {
                    $errors[$field] = $error;
                } elseif (!self::same($field, $value, $effective)) {
                    $plan[$field] = $value;
                }
            }
            if ($errors) {
                throw new SAT_Claim_Correction_Error('Correct the highlighted fields.', 422, $errors);
            }

            $changed = [];
            foreach ($plan as $field => $value) {
                $c = $corrections[$field] ?? null;
                $current = $source[$field];
                $effective = $resolved['fields'][$field]['effective'];
                if (self::same($field, $value, $current)) {
                    // Equal to the imported value: no correction is needed.
                    $db->prepare('DELETE FROM sat_observation_corrections WHERE correction_id = ?')->execute([(int)$c['correction_id']]);
                    self::event($db, $observation_id, $entry, $field, 'reset', $effective, $current, $current, (string)$c['source_value'], (string)$c['reason_code'], $user);
                } elseif (is_array($c)) {
                    $db->prepare(
                        "UPDATE sat_observation_corrections
                         SET corrected_value = ?, source_value = ?, observed_source_value = ?, reason_code = ?, source_state = 'clear',
                             version = version + 1, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                         WHERE correction_id = ?"
                    )->execute([$value, $current, $current, $reason, $user ?: null, (int)$c['correction_id']]);
                    self::event($db, $observation_id, $entry, $field, 'value_changed', $effective, $value, $current, $current, $reason, $user);
                } else {
                    $db->prepare(
                        "INSERT INTO sat_observation_corrections
                            (observation_id, field_name, corrected_value, source_value, observed_source_value, reason_code, source_state, created_by, updated_by)
                         VALUES (?, ?, ?, ?, ?, ?, 'clear', ?, ?)"
                    )->execute([$observation_id, $field, $value, $current, $current, $reason, $user ?: null, $user ?: null]);
                    self::event($db, $observation_id, $entry, $field, 'created', $effective, $value, $current, $current, $reason, $user);
                }
                $changed[$field] = true;
            }
            return array_keys($changed);
        });
    }

    /** "Use source": remove one field's active correction. The imported payload is not touched. */
    public static function reset_field(PDO $db, int $observation_id, string $token, string $field, int $user): array
    {
        self::require_field($field);
        return self::transaction($db, static function () use ($db, $observation_id, $token, $field, $user): array {
            [$row, $source, $corrections] = self::lock_claim($db, $observation_id, $token);
            $c = $corrections[$field] ?? null;
            if (!is_array($c)) {
                throw new SAT_Claim_Correction_Error(self::FIELDS[$field] . ' has no active correction.', 409, [], 'stale');
            }
            $db->prepare('DELETE FROM sat_observation_corrections WHERE correction_id = ?')->execute([(int)$c['correction_id']]);
            self::event($db, $observation_id, (int)($row['gf_entry_id'] ?? 0), $field, 'reset', (string)$c['corrected_value'], $source[$field], $source[$field], (string)$c['source_value'], (string)$c['reason_code'], $user);
            return [$field];
        });
    }

    /** "Keep SAT correction" after a source conflict: the current imported value becomes the captured one. */
    public static function keep_field(PDO $db, int $observation_id, string $token, string $field, int $user): array
    {
        self::require_field($field);
        return self::transaction($db, static function () use ($db, $observation_id, $token, $field, $user): array {
            [$row, $source, $corrections] = self::lock_claim($db, $observation_id, $token);
            $c = $corrections[$field] ?? null;
            if (!is_array($c) || self::state_for($field, (string)$c['source_value'], $source[$field], (string)$c['corrected_value']) !== self::STATE_CONFLICT) {
                throw new SAT_Claim_Correction_Error(self::FIELDS[$field] . ' has no source conflict to review.', 409, [], 'stale');
            }
            $db->prepare(
                "UPDATE sat_observation_corrections
                 SET source_value = ?, observed_source_value = ?, source_state = 'clear', version = version + 1, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE correction_id = ?"
            )->execute([$source[$field], $source[$field], $user ?: null, (int)$c['correction_id']]);
            self::event($db, $observation_id, (int)($row['gf_entry_id'] ?? 0), $field, 'conflict_kept', (string)$c['corrected_value'], (string)$c['corrected_value'], $source[$field], (string)$c['source_value'], (string)$c['reason_code'], $user);
            return [$field];
        });
    }

    private static function require_field(string $field): void
    {
        if (!isset(self::FIELDS[$field])) {
            throw new SAT_Claim_Correction_Error('Only Expense Date, Payee, Description and Amount can be corrected.', 400);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Ajax                                                                */
    /* ------------------------------------------------------------------ */

    private function guard(string $nonce): void
    {
        if (!is_user_logged_in() || !current_user_can(SAT_CAP_REVIEW)) {
            throw new SAT_Claim_Correction_Error('You do not have permission to correct expense claims.', 403, [], 'forbidden');
        }
        if (!check_ajax_referer($nonce, 'nonce', false)) {
            throw new SAT_Claim_Correction_Error('Your session has expired. Refresh the page and try again.', 403, [], 'forbidden');
        }
    }

    private static function posted_id(): int
    {
        $id = $_POST['observation_id'] ?? '';
        if (!is_scalar($id) || preg_match('/^[1-9]\d{0,18}$/D', (string)$id) !== 1) {
            throw new SAT_Claim_Correction_Error('Expense claim not found. Refresh the page.', 404, [], 'not_found');
        }
        return (int)$id;
    }

    private static function posted_string(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? (string)wp_unslash($value) : '';
    }

    private function fail(Throwable $e): void
    {
        if ($e instanceof SAT_Claim_Correction_Error) {
            wp_send_json_error(['message' => $e->getMessage(), 'kind' => $e->kind(), 'errors' => $e->field_errors()], $e->status());
        }
        wp_send_json_error(['message' => 'The correction could not be saved. Nothing was changed.', 'kind' => 'error', 'errors' => []], 500);
    }

    /** Claim as shown on Expense Claims (effective values, markers), or a not-found refusal. */
    private static function find_claim(int $observation_id): array
    {
        foreach (SAT_Expense_Report::instance()->claims() as $claim) {
            if ($claim['observation_id'] === $observation_id) {
                return $claim;
            }
        }
        throw new SAT_Claim_Correction_Error('Expense claim not found. Refresh the page.', 404, [], 'not_found');
    }

    private static function user_name(int $id): string
    {
        if ($id < 1) {
            return 'SAT import';
        }
        $user = function_exists('get_userdata') ? get_userdata($id) : false;
        return is_object($user) && (string)($user->display_name ?? '') !== '' ? (string)$user->display_name : 'User #' . $id;
    }

    /** Modal contents. Only the four business values, the entry number and audit facts are exposed. */
    public static function modal_data(array $claim, array $events): array
    {
        $fields = [];
        foreach ($claim['corrections']['fields'] as $field => $f) {
            $fields[] = [
                'field' => $field,
                'label' => $f['label'],
                'source' => $f['source'],
                'effective' => $f['effective'],
                'active' => $f['active'],
                'state' => $f['state'],
                'captured_source' => $f['captured_source'],
                'reason' => $f['active'] ? (self::REASONS[$f['reason']] ?? $f['reason']) : '',
                'updated_by' => $f['active'] ? self::user_name($f['updated_by'] ?: $f['created_by']) : '',
                'updated_at' => $f['updated_at'],
            ];
        }
        $history = [];
        foreach ($events as $e) {
            $history[] = [
                'when' => (string)$e['created_at'],
                'actor' => self::user_name((int)($e['created_by'] ?? 0)),
                'field' => self::FIELDS[(string)$e['field_name']] ?? (string)$e['field_name'],
                'type' => self::EVENTS[(string)$e['event_type']] ?? (string)$e['event_type'],
                'old' => $e['old_effective_value'],
                'new' => $e['new_effective_value'],
                'source' => $e['source_value'],
                'reason' => self::REASONS[(string)$e['reason_code']] ?? '',
            ];
        }
        return [
            'observation_id' => $claim['observation_id'],
            'entry_id' => $claim['entry_id'],
            'token' => $claim['corrections']['token'],
            'reason' => self::DEFAULT_REASON,
            'fields' => $fields,
            'corrected' => $claim['corrected'],
            'review' => $claim['correction_review'],
            'matched' => $claim['matched'],
            'amount_mismatch' => $claim['amount_mismatch'],
            'events' => $history,
        ];
    }

    public function ajax_load(): void
    {
        try {
            $this->guard(self::NONCE_READ);
            $claim = self::find_claim(self::posted_id());
            $response = ['claim' => self::modal_data($claim, self::events(SAT_DB::pdo(), $claim['observation_id']))];
        } catch (Throwable $e) {
            $this->fail($e);
            return;
        }
        wp_send_json_success($response);
    }

    public function ajax_save(): void
    {
        try {
            $this->guard(self::NONCE_WRITE);
            $id = self::posted_id();
            $posted = $_POST['values'] ?? [];
            if (!is_array($posted)) {
                throw new SAT_Claim_Correction_Error('Nothing to save.', 400);
            }
            $values = [];
            foreach ($posted as $field => $value) {
                $values[(string)$field] = is_string($value) ? (string)wp_unslash($value) : $value;
            }
            $changed = self::save(SAT_DB::pdo(), $id, self::posted_string('token'), $values, self::posted_string('reason'), (int)get_current_user_id());
            $response = $this->response($id, $changed ? 'Correction saved. Reports and new Wave previews use the corrected values.' : 'No changes to save.');
        } catch (Throwable $e) {
            $this->fail($e);
            return;
        }
        wp_send_json_success($response);
    }

    public function ajax_field(): void
    {
        try {
            $this->guard(self::NONCE_WRITE);
            $id = self::posted_id();
            $field = self::posted_string('field');
            $operation = self::posted_string('operation');
            if ($operation === 'reset') {
                self::reset_field(SAT_DB::pdo(), $id, self::posted_string('token'), $field, (int)get_current_user_id());
                $message = self::FIELDS[$field] . ' now uses the imported value.';
            } elseif ($operation === 'keep') {
                self::keep_field(SAT_DB::pdo(), $id, self::posted_string('token'), $field, (int)get_current_user_id());
                $message = 'SAT correction of ' . self::FIELDS[$field] . ' kept.';
            } else {
                throw new SAT_Claim_Correction_Error('Unknown correction action.', 400);
            }
            $response = $this->response($id, $message);
        } catch (Throwable $e) {
            $this->fail($e);
            return;
        }
        wp_send_json_success($response);
    }

    /**
     * Fresh server-authoritative state after a change: modal data, the re-rendered row, whether it
     * still belongs to the current filters, where it now sorts, and the view's summary.
     */
    private function response(int $id, string $message): array
    {
        self::forget();
        $report = SAT_Expense_Report::instance();
        $all = $report->claims();
        parse_str(ltrim(self::posted_string('view'), '?'), $query);
        $f = SAT_Expense_Report::filters((array)$query, (string)current_time('Y-m'));
        $rows = SAT_Expense_Report::select($all, $f);
        $claim = null;
        foreach ($all as $c) {
            if ($c['observation_id'] === $id) {
                $claim = $c;
            }
        }
        if ($claim === null) {
            throw new SAT_Claim_Correction_Error('Expense claim not found. Refresh the page.', 404, [], 'not_found');
        }
        $position = null;
        foreach ($rows as $index => $c) {
            if ($c['observation_id'] === $id) {
                $position = $index;
            }
        }
        $summary = SAT_Expense_Report::summary($rows);
        return [
            'message' => $message,
            'claim' => self::modal_data($claim, self::events(SAT_DB::pdo(), $id)),
            'row' => [
                'html' => SAT_Inbox::instance()->claim_row_html($claim, true, false),
                'in_view' => $position !== null,
                'next_id' => $position !== null && isset($rows[$position + 1]) ? $rows[$position + 1]['observation_id'] : 0,
            ],
            'summary' => [
                'claims' => $summary['claims'],
                'total' => '$' . number_format($summary['total_cents'] / 100, 2),
                'receipts' => $summary['receipts'],
                'receipt_warning' => $f['preset'] === SAT_Expense_Report::PRESET ? SAT_Expense_Report::receipt_warning($rows) : '',
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Modal template                                                      */
    /* ------------------------------------------------------------------ */

    /** Empty, accessible dialog filled by assets/claim-corrections.js from server data. */
    public static function render_modal(): void
    {
        echo '<div class="modal sat-correction-modal" id="sat-correction-modal" hidden>';
        echo '<div class="backdrop" data-correction-cancel></div>';
        echo '<div class="panel" role="dialog" aria-modal="true" aria-labelledby="sat-correction-title" aria-describedby="sat-correction-help" tabindex="-1">';
        echo '<form class="sat-correction-form" novalidate>';
        echo '<header><h3 id="sat-correction-title">Correct expense claim <span data-correction-entry></span></h3>';
        echo '<button type="button" data-correction-cancel>Close</button></header>';
        echo '<p id="sat-correction-help" class="help">Corrected values are SAT\'s values for Expense Claims, the CG Funds report and new Wave previews. The Gravity Forms entry and imported values are not changed.</p>';
        echo '<div class="msg error" data-correction-error role="alert" hidden></div>';
        echo '<p class="sat-correction-loading" data-correction-loading>Loading…</p>';
        echo '<div data-correction-body hidden>';
        echo '<div class="msg warning" data-correction-review hidden>The imported value of a corrected field changed. Review each highlighted field: keep the SAT correction, use the current imported value, or enter and save a new value.</div>';
        echo '<div class="msg warning" data-correction-mismatch hidden>The effective amount differs from the matched bank or card transaction. The match and its categories are unchanged; review them.</div>';
        foreach (self::FIELDS as $field => $label) {
            $id = 'sat-correction-' . str_replace('_', '-', $field);
            echo '<div class="sat-correction-field" data-correction-field="' . esc_attr($field) . '">';
            echo '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
            $described = esc_attr($id . '-meta ' . $id . '-error');
            if ($field === 'description') {
                echo '<textarea id="' . esc_attr($id) . '" name="values[description]" rows="3" maxlength="' . self::DESCRIPTION_MAX . '" aria-describedby="' . $described . '"></textarea>';
            } elseif ($field === 'transaction_date') {
                echo '<input id="' . esc_attr($id) . '" name="values[transaction_date]" type="date" aria-describedby="' . $described . '">';
            } elseif ($field === 'amount') {
                echo '<input id="' . esc_attr($id) . '" name="values[amount]" type="text" inputmode="decimal" autocomplete="off" aria-describedby="' . $described . '">';
            } else {
                echo '<input id="' . esc_attr($id) . '" name="values[payee]" type="text" maxlength="' . self::PAYEE_MAX . '" autocomplete="off" aria-describedby="' . $described . '">';
            }
            echo '<div class="sat-correction-meta" id="' . esc_attr($id) . '-meta">';
            echo '<span>Imported: <span data-correction-source></span></span> ';
            echo '<span class="pill" data-correction-badge hidden></span> ';
            echo '<span data-correction-audit></span>';
            echo '</div>';
            echo '<div class="sat-correction-conflict" data-correction-conflict hidden>';
            echo '<p><strong>Correction review needed.</strong> The imported value changed after this correction was made.</p>';
            echo '<dl><div><dt>Imported when corrected</dt><dd data-correction-captured></dd></div>';
            echo '<div><dt>Current imported value</dt><dd data-correction-current></dd></div>';
            echo '<div><dt>SAT correction</dt><dd data-correction-corrected></dd></div></dl>';
            echo '<p class="sat-correction-actions"><button type="button" data-correction-op="keep">Keep SAT correction</button> ';
            echo '<button type="button" data-correction-op="reset">Use current source value</button> ';
            echo '<span class="sat-help">or edit the value above and save.</span></p></div>';
            echo '<p class="sat-correction-actions"><button type="button" data-correction-op="reset" data-correction-use-source hidden>Use source</button></p>';
            if ($field === 'amount') {
                echo '<p class="msg warning" data-correction-amount-warning hidden>Changing Amount changes report totals. It does not change matches, categories or frozen Wave previews, which may then disagree with the claim.</p>';
            }
            if ($field === 'transaction_date') {
                echo '<p class="msg warning" data-correction-month-warning hidden></p>';
            }
            echo '<p class="field-error" id="' . esc_attr($id) . '-error" data-correction-field-error hidden></p>';
            echo '</div>';
        }
        echo '<div class="sat-correction-field"><label for="sat-correction-reason">Reason</label>';
        echo '<select id="sat-correction-reason" name="reason" aria-describedby="sat-correction-reason-error">';
        foreach (self::REASONS as $code => $label) {
            echo '<option value="' . esc_attr($code) . '"' . ($code === self::DEFAULT_REASON ? ' selected' : '') . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="field-error" id="sat-correction-reason-error" data-correction-field-error hidden></p></div>';
        echo '<details class="sat-correction-history"><summary>Correction history</summary><div data-correction-history></div></details>';
        echo '</div>';
        echo '<footer><button type="button" data-correction-cancel>Cancel</button>';
        echo '<button type="submit" class="primary" data-correction-save>Save correction</button></footer>';
        echo '</form></div></div>';
    }
}
