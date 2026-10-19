<?php
/**
 * Behavioural checks for ordinary Wave API previews: Send all, the preview action bar and
 * Ready for Wave staging. Runs the real SAT_Wave_Export and SAT_Transfer_OFX classes against
 * WordPress stubs, an in-memory fake PDO and a fake Wave client. Never calls Wave or a database.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('SAT_CAP_REVIEW', 'sat_review');

/* ------------------------------------------------------------------ */
/* WordPress and SAT stubs                                             */
/* ------------------------------------------------------------------ */

final class WpDie extends RuntimeException {}
final class NonceFailure extends RuntimeException {}
final class RedirectSignal extends RuntimeException {}

final class WP_Error
{
    public function __construct(private string $code = '', private string $message = '') {}
    public function get_error_message(): string { return $this->message; }
}

$GLOBALS['wp_user'] = ['logged_in' => true, 'caps' => [SAT_CAP_REVIEW => true]];
$GLOBALS['redirects'] = [];

function add_action(...$args): void {}
function is_user_logged_in(): bool { return $GLOBALS['wp_user']['logged_in']; }
function current_user_can(string $cap): bool { return !empty($GLOBALS['wp_user']['caps'][$cap]); }
function wp_die($message = '', $title = '', $args = []): void { throw new WpDie((string)$message); }
function check_admin_referer(string $action): int
{
    if (($_POST['_wpnonce'] ?? '') !== 'nonce-' . $action) {
        throw new NonceFailure('Nonce check failed for ' . $action);
    }
    return 1;
}
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">'; }
function wp_json_encode($value, int $flags = 0) { return json_encode($value, $flags); }
function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
function get_current_user_id(): int { return 7; }
function current_time(string $type) { return date('Y-m-d H:i:s'); }
function esc_url_raw($url) { return (string)$url; }
function esc_url($url) { return htmlspecialchars((string)$url, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string)$text, ENT_QUOTES); }
function wp_unslash($value) { return $value; }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_get_referer() { return 'https://example.test/inbox/'; }
function remove_query_arg($keys, $url = '') { return 'https://example.test/inbox/'; }
function sanitize_text_field($text) { return trim((string)$text); }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string)$key)); }
function add_query_arg($args, $url = '', $base = '')
{
    if (is_string($args)) {
        $args = [$args => $url];
        $url = $base;
    }
    $parts = [];
    foreach ($args as $key => $value) {
        $parts[] = $key . '=' . $value;
    }
    return $url . (str_contains((string)$url, '?') ? '&' : '?') . implode('&', $parts);
}
function wp_safe_redirect(string $url): void
{
    $GLOBALS['redirects'][] = $url;
    throw new RedirectSignal($url);
}

final class SAT_Settings
{
    public static array $wave = [];
    public static array $banks = [];
    public static array $accounts = [];
    public static string $token = 'test-token';

    public static function get(string $section): array
    {
        return match ($section) {
            'wave' => self::$wave,
            'accounts' => self::$accounts,
            default => [],
        };
    }
    public static function banks(): array { return self::$banks; }
    public static function wave_token(): string { return self::$token; }
}

final class SAT_DB
{
    public static ?PDO $pdo = null;
    public static function pdo(): PDO { return self::$pdo; }
}

/** Fake Wave client: records every create request; fails the external IDs listed in $fail. */
final class SAT_Wave
{
    private static ?self $instance = null;
    public array $calls = [];
    public array $fail = [];
    public ?Closure $on_create = null;
    public static function instance(): self { return self::$instance ??= new self(); }
    public function create_money_transaction(array $input)
    {
        $this->calls[] = $input;
        if ($this->on_create !== null) {
            ($this->on_create)();
        }
        if (isset($this->fail[$input['externalId']])) {
            return new WP_Error('wave', $this->fail[$input['externalId']]);
        }
        return ['id' => 'WAVE-' . count($this->calls)];
    }
}

/* ------------------------------------------------------------------ */
/* In-memory fake PDO for the exact queries used by the export path    */
/* ------------------------------------------------------------------ */

final class FakeDuplicate extends PDOException
{
    public function __construct()
    {
        parent::__construct('Duplicate entry');
        $this->code = '23000';
    }
}

final class FakeStatement extends PDOStatement
{
    private array $rows = [];
    public function __construct(private FakePDO $db, private string $sql) {}
    public function execute($params = null): bool
    {
        $this->rows = array_values($this->db->run($this->sql, array_values((array)$params)));
        return true;
    }
    public function fetch($mode = PDO::FETCH_DEFAULT, $orientation = PDO::FETCH_ORI_NEXT, $offset = 0): mixed
    {
        return $this->rows ? array_shift($this->rows) : false;
    }
    public function fetchAll($mode = PDO::FETCH_DEFAULT, ...$args): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $mode === PDO::FETCH_COLUMN ? array_map(static fn($row) => reset($row), $rows) : $rows;
    }
    public function fetchColumn($column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : array_values($row)[$column];
    }
}

final class FakePDO extends PDO
{
    public array $t = [
        'observations' => [], 'allocations' => [], 'matches' => [], 'reviews' => [], 'reservations' => [],
        'batches' => [], 'items' => [], 'sources' => [], 'lines' => [], 'attempts' => [],
        'transfer_batches' => [], 'transfer_matches' => [], 'transfer_legs' => [], 'transfer_files' => [],
        'corrections' => [], 'correction_events' => [],
    ];
    private ?array $snapshot = null;
    private int $last_id = 0;
    private int $next_id = 1000;
    public array $unhandled = [];
    public array $queries = [];
    public ?string $fail_sql = null;
    public int $fail_after = 1;

    public function __construct() {}
    public function prepare($query, $options = []): PDOStatement|false { return new FakeStatement($this, $query); }
    public function query($query, $fetchMode = null, ...$args): PDOStatement|false
    {
        $statement = new FakeStatement($this, $query);
        $statement->execute();
        return $statement;
    }
    public function beginTransaction(): bool
    {
        if ($this->snapshot !== null) {
            throw new PDOException('There is already an active transaction');
        }
        $this->snapshot = $this->t;
        return true;
    }
    public function commit(): bool { $this->snapshot = null; return true; }
    public function rollBack(): bool { $this->t = $this->snapshot; $this->snapshot = null; return true; }
    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function lastInsertId($name = null): string|false { return (string)$this->last_id; }

    private function insert(string $table, array $row, string $key): void
    {
        $id = $this->next_id++;
        $row[$key] = $id;
        $this->t[$table][$id] = $row;
        $this->last_id = $id;
    }

    private function item_exists(int $item_id): bool { return isset($this->t['items'][$item_id]); }

    public function run(string $sql, array $p): array
    {
        $s = trim((string)preg_replace('/\s+/', ' ', $sql));
        $this->queries[] = [$s, $this->inTransaction()];
        if ($this->fail_sql !== null && str_starts_with($s, $this->fail_sql) && --$this->fail_after === 0) {
            throw new PDOException('Injected persistence failure');
        }
        $now = time();
        $stale = static fn($time) => $time !== null && $time < $now - 900;
        $by = static function (array $rows, string $field, $value): array {
            return array_values(array_filter($rows, static fn($row) => (string)$row[$field] === (string)$value));
        };

        // Claim corrections (resolver cache, cleanup removal, frozen-preview change notice).
        if (preg_match('/^SELECT correction_id, .* FROM sat_observation_corrections ORDER BY observation_id, field_name$/', $s)) {
            return array_values($this->t['corrections']);
        }
        if (preg_match('/^SELECT correction_id, .* FROM sat_observation_corrections WHERE observation_id IN \([?, ]+\) ORDER BY observation_id, field_name( FOR UPDATE)?$/', $s)) {
            return array_values(array_filter($this->t['corrections'], static fn($c) => in_array((int)$c['observation_id'], array_map('intval', $p), true)));
        }
        if (str_starts_with($s, 'SELECT observation_id, gf_entry_id FROM inbox_observations WHERE observation_id IN (')) {
            return array_values(array_map(static fn($o) => ['observation_id' => $o['observation_id'], 'gf_entry_id' => $o['gf_entry_id']], array_filter($this->t['observations'], static fn($o) => in_array((int)$o['observation_id'], array_map('intval', $p), true))));
        }
        if (str_starts_with($s, 'INSERT INTO sat_observation_correction_events')) {
            $this->t['correction_events'][] = array_combine(['observation_id', 'gf_entry_id', 'field_name', 'event_type', 'old_effective_value', 'new_effective_value', 'source_value', 'captured_source_value', 'reason_code', 'created_by'], $p) + ['created_at' => date('Y-m-d H:i:s')];
            return [];
        }
        if (str_starts_with($s, 'DELETE FROM sat_observation_corrections WHERE observation_id IN (')) {
            $this->t['corrections'] = array_filter($this->t['corrections'], static fn($c) => !in_array((int)$c['observation_id'], array_map('intval', $p), true));
            return [];
        }
        if (str_starts_with($s, 'SELECT DISTINCT s.item_id FROM sat_wave_export_sources s JOIN sat_wave_export_items i ON i.item_id = s.item_id JOIN inbox_matches m')) {
            $found = [];
            foreach ($this->t['sources'] as $source) {
                $item = $this->t['items'][$source['item_id']] ?? null;
                if (!$item || (int)$item['batch_id'] !== (int)$p[0] || !in_array($item['item_status'], ['pending', 'failed'], true)) {
                    continue;
                }
                foreach ($this->t['matches'] as $m) {
                    if (!in_array($source['observation_id'], [$m['left_observation_id'], $m['right_observation_id']], true)) {
                        continue;
                    }
                    $other = $m['left_observation_id'] === $source['observation_id'] ? $m['right_observation_id'] : $m['left_observation_id'];
                    foreach ($this->t['correction_events'] as $e) {
                        if ((int)$e['observation_id'] === (int)$other && in_array($e['field_name'], ['payee', 'description'], true)
                            && in_array($e['event_type'], ['created', 'value_changed', 'reset'], true) && $e['created_at'] >= $p[1]) {
                            $found[$source['item_id']] = ['item_id' => $source['item_id']];
                        }
                    }
                }
            }
            return array_values($found);
        }

        // Transfer lifecycle persistence.
        if (str_starts_with($s, 'SELECT match_id, left_observation_id, right_observation_id FROM inbox_matches WHERE match_id = ?')) {
            return $by($this->t['matches'], 'match_id', $p[0]);
        }
        if (str_starts_with($s, 'SELECT o.*, r.review_status FROM inbox_observations o')) {
            $rows = [];
            foreach ($p as $id) {
                if (isset($this->t['observations'][$id])) {
                    $rows[] = ['review_status' => $this->t['reviews'][$id]['review_status'] ?? null] + $this->t['observations'][$id];
                }
            }
            return $rows;
        }
        if (str_starts_with($s, 'SELECT COUNT(*) FROM inbox_matches WHERE left_observation_id = ?')) {
            return [['n' => count(array_filter($this->t['matches'], static fn($m) => $m['left_observation_id'] === $p[0] || $m['right_observation_id'] === $p[1]))]];
        }
        if ($s === 'SELECT observation_id FROM sat_transfer_ofx_reservations WHERE observation_id IN (?, ?)') {
            return array_values(array_filter($this->t['reservations'], static fn($r) => in_array($r['observation_id'], $p, true)));
        }
        if ($s === 'SELECT observation_id FROM sat_wave_export_sources WHERE observation_id IN (?, ?)') {
            return array_values(array_filter($this->t['sources'], static fn($r) => in_array($r['observation_id'], $p, true)));
        }
        if (str_starts_with($s, 'INSERT INTO sat_transfer_ofx_batches')) {
            $this->insert('transfer_batches', ['batch_status' => 'generated', 'created_by' => $p[0]], 'batch_id');
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_transfer_ofx_batch_matches')) {
            $this->insert('transfer_matches', array_combine(['batch_id', 'match_id', 'source_observation_id', 'destination_observation_id', 'source_label', 'destination_label', 'amount', 'currency', 'source_date', 'destination_date', 'source_description', 'destination_description'], $p), 'batch_match_id');
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_transfer_ofx_legs')) {
            $this->insert('transfer_legs', array_combine(['batch_match_id', 'observation_id', 'source_system', 'source_key', 'account_id', 'account_label', 'account_type', 'bank_id', 'branch_id', 'bank_account_type', 'currency', 'transaction_date', 'amount', 'fitid', 'name', 'memo'], $p), 'batch_leg_id');
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_transfer_ofx_reservations')) {
            if (isset($this->t['reservations'][$p[0]])) {
                throw new FakeDuplicate();
            }
            $this->t['reservations'][$p[0]] = ['observation_id' => $p[0], 'batch_id' => $p[1]];
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_transfer_ofx_batch_files')) {
            $this->insert('transfer_files', array_combine(['batch_id', 'account_id', 'source_system', 'filename', 'transaction_count'], $p), 'batch_file_id');
            return [];
        }
        if ($s === 'UPDATE sat_transfer_ofx_batches SET generated_at = CURRENT_TIMESTAMP WHERE batch_id = ?') {
            $this->t['transfer_batches'][$p[0]]['generated_at'] = $now;
            return [];
        }
        if ($s === 'SELECT * FROM sat_transfer_ofx_batches WHERE batch_id = ? FOR UPDATE') {
            return isset($this->t['transfer_batches'][$p[0]]) ? [$this->t['transfer_batches'][$p[0]]] : [];
        }
        if ($s === 'SELECT batch_status FROM sat_transfer_ofx_batches WHERE batch_id = ? FOR UPDATE') {
            return isset($this->t['transfer_batches'][$p[0]]) ? [['batch_status' => $this->t['transfer_batches'][$p[0]]['batch_status']]] : [];
        }
        if (str_starts_with($s, 'SELECT l.observation_id FROM sat_transfer_ofx_legs l')) {
            $matches = array_column($by($this->t['transfer_matches'], 'batch_id', $p[0]), 'batch_match_id');
            $rows = array_filter($this->t['transfer_legs'], static fn($l) => in_array($l['batch_match_id'], $matches, true));
            usort($rows, static fn($a, $b) => $a['observation_id'] <=> $b['observation_id']);
            return array_map(static fn($r) => ['observation_id' => $r['observation_id']], $rows);
        }
        if ($s === 'SELECT COUNT(*) FROM sat_transfer_ofx_batch_matches WHERE batch_id = ?') {
            return [['n' => count($by($this->t['transfer_matches'], 'batch_id', $p[0]))]];
        }
        if ($s === 'SELECT batch_id FROM sat_transfer_ofx_reservations WHERE observation_id = ? FOR UPDATE') {
            return isset($this->t['reservations'][$p[0]]) ? [['batch_id' => $this->t['reservations'][$p[0]]['batch_id']]] : [];
        }
        if ($s === 'SELECT review_status FROM inbox_reviews WHERE bank_observation_id = ? FOR UPDATE') {
            return isset($this->t['reviews'][$p[0]]) ? [['review_status' => $this->t['reviews'][$p[0]]['review_status']]] : [];
        }
        if (str_starts_with($s, 'INSERT INTO inbox_reviews (bank_observation_id, review_status, export_route')) {
            $this->t['reviews'][$p[0]] = ['bank_observation_id' => $p[0], 'review_status' => 'exported', 'export_route' => 'transfer_ofx', 'transfer_ofx_batch_id' => $p[1], 'reviewed_by' => $p[2], 'exported_at' => $now] + ($this->t['reviews'][$p[0]] ?? []);
            return [];
        }
        foreach (['confirmed', 'cancelled'] as $status) {
            if (str_starts_with($s, "UPDATE sat_transfer_ofx_batches SET batch_status = '$status'")) {
                $this->t['transfer_batches'][$p[1]] = ['batch_status' => $status, $status . '_by' => $p[0], $status . '_at' => $now] + $this->t['transfer_batches'][$p[1]];
                return [];
            }
        }
        if ($s === 'DELETE FROM sat_transfer_ofx_reservations WHERE batch_id = ?') {
            $this->t['reservations'] = array_filter($this->t['reservations'], static fn($r) => $r['batch_id'] !== $p[0]);
            return [];
        }

        // Cleanup current reads, including matched evidence.
        if (str_starts_with($s, 'SELECT left_observation_id, right_observation_id FROM inbox_matches WHERE')) {
            return array_values(array_filter($this->t['matches'], static fn($m) => in_array($m['left_observation_id'], $p, true) || in_array($m['right_observation_id'], $p, true)));
        }
        if (str_ends_with($s, 'LIMIT 1 FOR UPDATE')) {
            foreach ($p as $id) {
                if ((str_starts_with($s, 'SELECT observation_id FROM sat_wave_export_sources') && $by($this->t['sources'], 'observation_id', $id))
                    || (str_starts_with($s, 'SELECT observation_id FROM sat_transfer_ofx_reservations') && isset($this->t['reservations'][$id]))
                    || (str_starts_with($s, 'SELECT bank_observation_id FROM inbox_reviews') && ($this->t['reviews'][$id]['review_status'] ?? '') === 'exported')
                    || (str_starts_with($s, 'SELECT observation_id FROM inbox_observations') && !empty($this->t['observations'][$id]['wave_transaction_id']))) {
                    return [['observation_id' => $id]];
                }
            }
            return [];
        }
        if (str_starts_with($s, 'DELETE FROM inbox_')) {
            if (str_starts_with($s, 'DELETE FROM inbox_matches')) {
                $this->t['matches'] = array_filter($this->t['matches'], static fn($r) => !in_array($r['left_observation_id'], $p, true) && !in_array($r['right_observation_id'], $p, true));
            } else {
                foreach (['allocations' => 'bank_observation_id', 'reviews' => 'bank_observation_id', 'observations' => 'observation_id'] as $table => $key) {
                    if (str_starts_with($s, 'DELETE FROM inbox_' . $table . ' ')) {
                        $this->t[$table] = array_filter($this->t[$table], static fn($r) => !in_array($r[$key], $p, true));
                    }
                }
            }
            return [];
        }

        // Inbox reads.
        if (str_starts_with($s, 'SELECT * FROM inbox_observations WHERE observation_id = ? AND source_system IN')) {
            $row = $this->t['observations'][(int)$p[0]] ?? null;
            return $row && in_array($row['source_system'], array_slice($p, 1), true) ? [$row] : [];
        }
        if (str_starts_with($s, 'SELECT * FROM inbox_allocations WHERE bank_observation_id = ?')) {
            $rows = $by($this->t['allocations'], 'bank_observation_id', $p[0]);
            usort($rows, static fn($a, $b) => [$a['line_number'], $a['allocation_id']] <=> [$b['line_number'], $b['allocation_id']]);
            return $rows;
        }
        if ($s === 'SELECT * FROM inbox_reviews WHERE bank_observation_id = ?') {
            return isset($this->t['reviews'][(int)$p[0]]) ? [$this->t['reviews'][(int)$p[0]]] : [];
        }
        if (str_starts_with($s, 'SELECT o.* FROM inbox_matches m JOIN inbox_observations o')) {
            $id = (int)$p[0];
            $rows = [];
            foreach ($this->t['matches'] as $match) {
                if ((int)$match['left_observation_id'] === $id || (int)$match['right_observation_id'] === $id) {
                    $other = (int)$match['left_observation_id'] === $id ? $match['right_observation_id'] : $match['left_observation_id'];
                    $rows[] = $this->t['observations'][(int)$other];
                }
            }
            usort($rows, static fn($a, $b) => [$a['transaction_date'], $a['observation_id']] <=> [$b['transaction_date'], $b['observation_id']]);
            return $rows;
        }
        if (str_starts_with($s, 'SELECT observation_id FROM inbox_observations WHERE observation_id IN (')) {
            $ids = array_map('intval', $p);
            sort($ids);
            return array_map(static fn($id) => ['observation_id' => $id], array_values(array_filter($ids, fn($id) => isset($this->t['observations'][$id]))));
        }

        // Transfer OFX reads (real SAT_Transfer_OFX).
        if ($s === 'SELECT 1 FROM sat_transfer_ofx_reservations WHERE observation_id = ?') {
            return isset($this->t['reservations'][(int)$p[0]]) ? [['1' => 1]] : [];
        }
        if (str_starts_with($s, 'SELECT m.match_id, m.left_observation_id, m.right_observation_id FROM inbox_matches m JOIN inbox_observations lo')) {
            $n = count(SAT_Settings::$banks);
            $sources = array_slice($p, 0, $n);
            $only = count($p) > 2 * $n ? (int)$p[2 * $n] : null;
            $rows = [];
            foreach ($this->t['matches'] as $match) {
                $left = $this->t['observations'][(int)$match['left_observation_id']] ?? null;
                $right = $this->t['observations'][(int)$match['right_observation_id']] ?? null;
                if (!$left || !$right || !in_array($left['source_system'], $sources, true) || !in_array($right['source_system'], $sources, true)) {
                    continue;
                }
                if ($only !== null && (int)$match['left_observation_id'] !== $only && (int)$match['right_observation_id'] !== $only) {
                    continue;
                }
                $rows[] = $match;
            }
            return $rows;
        }

        // Staging: source rows count only when their item exists (inner join).
        if ($s === 'SELECT s.observation_id, i.batch_id FROM sat_wave_export_sources s JOIN sat_wave_export_items i ON i.item_id = s.item_id') {
            $rows = [];
            foreach ($this->t['sources'] as $source) {
                if ($this->item_exists((int)$source['item_id'])) {
                    $rows[] = ['observation_id' => $source['observation_id'], 'batch_id' => $this->t['items'][(int)$source['item_id']]['batch_id']];
                }
            }
            return $rows;
        }
        if ($s === 'SELECT i.batch_id FROM sat_wave_export_sources s JOIN sat_wave_export_items i ON i.item_id = s.item_id WHERE s.observation_id = ? LIMIT 1') {
            foreach ($this->t['sources'] as $source) {
                if ((int)$source['observation_id'] === (int)$p[0] && $this->item_exists((int)$source['item_id'])) {
                    return [['batch_id' => $this->t['items'][(int)$source['item_id']]['batch_id']]];
                }
            }
            return [];
        }

        // Batch creation.
        if (str_starts_with($s, 'INSERT INTO sat_wave_export_batches')) {
            $this->insert('batches', ['batch_uuid' => $p[0], 'batch_status' => 'preview', 'item_count' => $p[1], 'exported_count' => 0, 'failed_count' => 0, 'created_by' => $p[2], 'created_at' => date('Y-m-d H:i:s'), 'completed_at' => null], 'batch_id');
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_wave_export_items')) {
            $fields = ['batch_id', 'sort_order', 'item_kind', 'source_observation_ids', 'source_label', 'wave_business_id', 'wave_business_name', 'transaction_date', 'anchor_source', 'anchor_account_id', 'anchor_account_name', 'anchor_direction', 'amount', 'description', 'item_note', 'external_id'];
            $row = array_combine($fields, $p) + ['item_status' => 'pending', 'validation_errors' => null, 'wave_transaction_id' => null, 'last_error' => null, 'attempt_count' => 0, 'attempted_at' => null, 'exported_at' => null];
            $this->insert('items', $row, 'item_id');
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_wave_export_sources')) {
            foreach ($this->t['sources'] as $source) {
                if ((int)$source['observation_id'] === (int)$p[1]) {
                    throw new FakeDuplicate();
                }
            }
            $this->insert('sources', array_combine(['item_id', 'observation_id', 'source_system', 'source_key', 'transaction_date', 'amount', 'description'], $p), 'source_id');
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_wave_export_lines')) {
            $this->insert('lines', array_combine(['item_id', 'line_number', 'source_allocation_id', 'account_id', 'account_name', 'amount', 'balance_direction', 'line_note'], $p), 'line_id');
            return [];
        }

        // Preview reads.
        if ($s === 'SELECT * FROM sat_wave_export_batches WHERE batch_id = ?'
            || $s === 'SELECT * FROM sat_wave_export_batches WHERE batch_id = ? FOR UPDATE') {
            return isset($this->t['batches'][(int)$p[0]]) ? [$this->t['batches'][(int)$p[0]]] : [];
        }
        if ($s === 'SELECT * FROM sat_wave_export_items WHERE batch_id = ? ORDER BY sort_order, item_id') {
            $rows = $by($this->t['items'], 'batch_id', $p[0]);
            usort($rows, static fn($a, $b) => [(int)$a['sort_order'], $a['item_id']] <=> [(int)$b['sort_order'], $b['item_id']]);
            return $rows;
        }
        if ($s === 'SELECT * FROM sat_wave_export_items WHERE item_id = ?') {
            return isset($this->t['items'][(int)$p[0]]) ? [$this->t['items'][(int)$p[0]]] : [];
        }
        if ($s === 'SELECT * FROM sat_wave_export_items WHERE item_id = ? AND batch_id = ? FOR UPDATE') {
            $row = $this->t['items'][(int)$p[0]] ?? null;
            return $row && (int)$row['batch_id'] === (int)$p[1] ? [$row] : [];
        }
        if (str_starts_with($s, 'SELECT * FROM sat_wave_export_lines WHERE item_id = ?')) {
            $rows = $by($this->t['lines'], 'item_id', $p[0]);
            usort($rows, static fn($a, $b) => [$a['line_number'], $a['line_id']] <=> [$b['line_number'], $b['line_id']]);
            return $rows;
        }
        if (str_starts_with($s, 'SELECT * FROM sat_wave_export_sources WHERE item_id = ?')) {
            $rows = $by($this->t['sources'], 'item_id', $p[0]);
            usort($rows, static fn($a, $b) => $a['observation_id'] <=> $b['observation_id']);
            return $rows;
        }
        if (str_starts_with($s, 'SELECT * FROM sat_wave_export_attempts WHERE item_id = ?')) {
            $rows = $by($this->t['attempts'], 'item_id', $p[0]);
            usort($rows, static fn($a, $b) => $b['attempt_number'] <=> $a['attempt_number']);
            return $rows;
        }

        // Per-item export state changes.
        if ($s === 'UPDATE sat_wave_export_items SET validation_errors = ? WHERE item_id = ?') {
            $this->t['items'][(int)$p[1]]['validation_errors'] = $p[0];
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_wave_export_attempts')) {
            foreach ($this->t['attempts'] as $attempt) {
                if ((int)$attempt['item_id'] === (int)$p[0] && (int)$attempt['attempt_number'] === (int)$p[1]) {
                    throw new FakeDuplicate();
                }
            }
            $this->insert('attempts', ['item_id' => $p[0], 'attempt_number' => $p[1], 'attempt_status' => 'started', 'external_id' => $p[2], 'started_by' => $p[3], 'started_at' => $now, 'wave_transaction_id' => null, 'error_message' => null, 'finished_at' => null], 'attempt_id');
            return [];
        }
        if (str_starts_with($s, "UPDATE sat_wave_export_items SET item_status = 'exporting'")) {
            $this->t['items'][(int)$p[1]] = ['item_status' => 'exporting', 'last_error' => null, 'attempt_count' => (int)$p[0], 'attempted_at' => $now] + $this->t['items'][(int)$p[1]];
            return [];
        }
        if (str_starts_with($s, "UPDATE sat_wave_export_items SET item_status = 'exported'")) {
            $this->t['items'][(int)$p[1]] = ['item_status' => 'exported', 'wave_transaction_id' => $p[0], 'last_error' => null, 'exported_at' => $now] + $this->t['items'][(int)$p[1]];
            return [];
        }
        if ($s === "UPDATE sat_wave_export_items SET item_status = 'failed', last_error = ? WHERE item_id = ?") {
            $this->t['items'][(int)$p[1]] = ['item_status' => 'failed', 'last_error' => $p[0]] + $this->t['items'][(int)$p[1]];
            return [];
        }
        if (str_starts_with($s, "UPDATE sat_wave_export_attempts SET attempt_status = 'exported'")) {
            $this->t['attempts'][(int)$p[1]] = ['attempt_status' => 'exported', 'wave_transaction_id' => $p[0], 'finished_at' => $now] + $this->t['attempts'][(int)$p[1]];
            return [];
        }
        if (str_starts_with($s, "UPDATE sat_wave_export_attempts SET attempt_status = 'failed'")) {
            $this->t['attempts'][(int)$p[1]] = ['attempt_status' => 'failed', 'error_message' => $p[0], 'finished_at' => $now] + $this->t['attempts'][(int)$p[1]];
            return [];
        }
        if (str_starts_with($s, "INSERT INTO inbox_reviews (bank_observation_id, review_status, final_description")) {
            $this->t['reviews'][(int)$p[0]] = ['bank_observation_id' => (int)$p[0], 'review_status' => 'exported', 'final_description' => $p[1], 'wave_transaction_id' => $p[2], 'wave_external_id' => $p[3], 'last_export_error' => null] + ($this->t['reviews'][(int)$p[0]] ?? []);
            return [];
        }
        if (str_starts_with($s, "INSERT INTO inbox_reviews (bank_observation_id, review_status, wave_external_id, last_export_error")) {
            $existing = $this->t['reviews'][(int)$p[0]] ?? ['bank_observation_id' => (int)$p[0], 'review_status' => 'ready'];
            $this->t['reviews'][(int)$p[0]] = ['wave_external_id' => $p[1], 'last_export_error' => $p[2]] + $existing;
            return [];
        }
        if ($s === 'UPDATE inbox_observations SET wave_transaction_id = ? WHERE observation_id = ?') {
            $this->t['observations'][(int)$p[1]]['wave_transaction_id'] = $p[0];
            return [];
        }

        // Batch status and stale recovery.
        if (str_starts_with($s, 'SELECT COUNT(*) total,')) {
            $rows = $by($this->t['items'], 'batch_id', $p[0]);
            $count = static fn($status) => count(array_filter($rows, static fn($row) => $row['item_status'] === $status));
            return [['total' => count($rows), 'exported' => $count('exported'), 'failed' => $count('failed'), 'exporting' => $count('exporting')]];
        }
        if (str_starts_with($s, 'UPDATE sat_wave_export_batches SET batch_status = ?')) {
            $this->t['batches'][(int)$p[5]] = ['batch_status' => $p[0], 'item_count' => $p[1], 'exported_count' => $p[2], 'failed_count' => $p[3], 'completed_at' => $p[4]] + $this->t['batches'][(int)$p[5]];
            return [];
        }
        if (str_starts_with($s, "SELECT DISTINCT batch_id FROM sat_wave_export_items WHERE item_status = 'exporting'")) {
            $ids = [];
            foreach ($this->t['items'] as $item) {
                if ($item['item_status'] === 'exporting' && $stale($item['attempted_at'])) {
                    $ids[(int)$item['batch_id']] = ['batch_id' => (int)$item['batch_id']];
                }
            }
            return array_values($ids);
        }
        if (str_starts_with($s, "UPDATE sat_wave_export_items SET item_status = 'failed', last_error = ? WHERE item_status = 'exporting'")) {
            foreach ($this->t['items'] as $id => $item) {
                if ($item['item_status'] === 'exporting' && $stale($item['attempted_at'])) {
                    $this->t['items'][$id] = ['item_status' => 'failed', 'last_error' => $p[0]] + $item;
                }
            }
            return [];
        }
        if (str_starts_with($s, "UPDATE sat_wave_export_attempts SET attempt_status = 'interrupted'")) {
            foreach ($this->t['attempts'] as $id => $attempt) {
                if ($attempt['attempt_status'] === 'started' && $stale($attempt['started_at'])) {
                    $this->t['attempts'][$id] = ['attempt_status' => 'interrupted', 'error_message' => $p[0], 'finished_at' => $now] + $attempt;
                }
            }
            return [];
        }

        // Save and revalidate.
        if ($s === 'UPDATE sat_wave_export_items SET description = ?, item_note = ? WHERE item_id = ?') {
            $this->t['items'][(int)$p[2]] = ['description' => $p[0], 'item_note' => $p[1]] + $this->t['items'][(int)$p[2]];
            return [];
        }
        if ($s === 'UPDATE sat_wave_export_items SET anchor_account_id = ?, anchor_account_name = ? WHERE item_id = ?') {
            $this->t['items'][(int)$p[2]] = ['anchor_account_id' => $p[0], 'anchor_account_name' => $p[1]] + $this->t['items'][(int)$p[2]];
            return [];
        }
        if (preg_match('/^UPDATE sat_wave_export_lines SET (line_note|account_id|balance_direction) = \? WHERE line_id = \?$/', $s, $m)) {
            $this->t['lines'][(int)$p[1]][$m[1]] = $p[0];
            return [];
        }

        // Discard.
        if (str_starts_with($s, 'SELECT (SELECT COUNT(*) FROM sat_wave_export_items WHERE batch_id = ?')) {
            $items = $by($this->t['items'], 'batch_id', $p[0]);
            $ids = array_map(static fn($item) => (int)$item['item_id'], $items);
            return [[
                'sent' => count(array_filter($items, static fn($item) => in_array($item['item_status'], ['exported', 'exporting'], true))),
                'attempts' => count(array_filter($this->t['attempts'], static fn($attempt) => in_array((int)$attempt['item_id'], $ids, true))),
            ]];
        }
        if (preg_match('/^DELETE FROM sat_wave_export_(lines|sources) WHERE item_id IN \(SELECT item_id FROM sat_wave_export_items WHERE batch_id = \?\)$/', $s, $m)) {
            $ids = array_map(static fn($item) => (int)$item['item_id'], $by($this->t['items'], 'batch_id', $p[0]));
            $this->t[$m[1]] = array_filter($this->t[$m[1]], static fn($row) => !in_array((int)$row['item_id'], $ids, true));
            return [];
        }
        if ($s === 'DELETE FROM sat_wave_export_items WHERE batch_id = ?') {
            $this->t['items'] = array_filter($this->t['items'], static fn($row) => (int)$row['batch_id'] !== (int)$p[0]);
            return [];
        }
        if ($s === 'DELETE FROM sat_wave_export_batches WHERE batch_id = ?') {
            unset($this->t['batches'][(int)$p[0]]);
            return [];
        }

        $this->unhandled[] = $s;
        throw new LogicException('Fake PDO does not handle: ' . $s);
    }
}

require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-ofx-writer.php';
require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-ofx-parser.php';
require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-transfer-ofx.php';
require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-claim-corrections.php';
require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-wave-export.php';

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
}

function same($expected, $actual, string $message): void
{
    expect($expected === $actual, $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function call_private(object $object, string $method, ...$args)
{
    return (function () use ($method, $args) {
        return $this->{$method}(...$args);
    })->call($object);
}

/** Invoke an admin-post handler and return [type, message] from its single redirect. */
function post_action(callable $handler, array $post): array
{
    $_POST = $post;
    $GLOBALS['redirects'] = [];
    try {
        $handler();
    } catch (RedirectSignal) {
    }
    expect(count($GLOBALS['redirects']) >= 1, 'Handler must redirect.');
    parse_str((string)parse_url($GLOBALS['redirects'][0], PHP_URL_QUERY), $query);
    return [$query['sat_notice'] ?? '', rawurldecode($query['sat_message'] ?? '')];
}

function external_id_for(array $keys): string
{
    sort($keys, SORT_STRING);
    return 'SAT-' . strtoupper(substr(hash('sha256', 'BIZ|' . implode('|', $keys)), 0, 48));
}

/* ------------------------------------------------------------------ */
/* Fixture                                                             */
/* ------------------------------------------------------------------ */

SAT_Settings::$banks = [
    'ofx_operating' => ['label' => 'Operating', 'type' => 'bank', 'wave' => 'Operating Account', 'acctid' => 'A-OP'],
    'ofx_visa' => ['label' => 'Group Visa', 'type' => 'credit_card', 'wave' => 'Group Visa', 'acctid' => 'A-VISA'],
    'ofx_fund' => ['label' => 'Camp Fund', 'type' => 'bank', 'wave' => 'Camp Fund', 'acctid' => 'A-FUND'],
];
SAT_Settings::$wave = [
    'business_id' => 'BIZ',
    'business_name' => 'Test Scouts',
    'discovered_accounts' => [
        ['id' => 'ACC-OP', 'name' => 'Operating Account', 'type_value' => 'ASSET', 'subtype_value' => 'CASH_AND_BANK'],
        ['id' => 'ACC-VISA', 'name' => 'Group Visa', 'type_value' => 'LIABILITY', 'subtype_value' => 'CREDIT_CARD'],
        ['id' => 'ACC-FUND', 'name' => 'Camp Fund', 'type_value' => 'ASSET', 'subtype_value' => 'CASH_AND_BANK'],
        ['id' => 'ACC-UNI', 'name' => 'Uniform Sales', 'type_value' => 'INCOME', 'subtype_value' => 'INCOME'],
        ['id' => 'ACC-FEES', 'name' => 'Program Fees', 'type_value' => 'INCOME', 'subtype_value' => 'INCOME'],
        ['id' => 'ACC-DON', 'name' => 'Donations', 'type_value' => 'INCOME', 'subtype_value' => 'INCOME'],
        ['id' => 'ACC-CAMP', 'name' => 'Camp Expenses', 'type_value' => 'EXPENSE', 'subtype_value' => 'OPERATING_EXPENSE'],
        ['id' => 'ACC-SUP', 'name' => 'Supplies', 'type_value' => 'EXPENSE', 'subtype_value' => 'OPERATING_EXPENSE'],
        ['id' => 'ACC-TRAIN', 'name' => 'Training and certification', 'type_value' => 'EXPENSE', 'subtype_value' => 'OPERATING_EXPENSE'],
        ['id' => 'ACC-DISC', 'name' => 'Sales Discounts', 'type_value' => 'INCOME', 'subtype_value' => 'DISCOUNTS'],
        ['id' => 'ACC-PREPAID', 'name' => 'Prepaid Camp Deposits', 'type_value' => 'ASSET', 'subtype_value' => 'PREPAID_EXPENSES'],
    ],
];

$db = new FakePDO();
SAT_DB::$pdo = $db;

function observation(FakePDO $db, int $id, string $source, string $amount, string $date, array $payload = [], ?string $gf = null): void
{
    $db->t['observations'][$id] = [
        'observation_id' => $id, 'source_system' => $source, 'source_key' => $source . '-' . $id,
        'transaction_date' => $date, 'amount' => $amount, 'description' => (string)($payload['memo'] ?? 'Evidence'),
        'gf_entry_id' => $gf, 'wave_transaction_id' => null, 'payload_json' => json_encode($payload),
    ];
}

function allocate(FakePDO $db, int $bank_id, array $lines): void
{
    static $next = 1;
    foreach ($lines as $index => [$account, $amount, $note]) {
        $db->t['allocations'][] = ['allocation_id' => $next++, 'bank_observation_id' => $bank_id, 'line_number' => $index + 1, 'export_account' => $account, 'amount' => $amount, 'allocation_note' => $note];
    }
}

function match_rows(FakePDO $db, int $left, int $right): void
{
    $db->t['matches'][] = ['match_id' => count($db->t['matches']) + 1, 'left_observation_id' => $left, 'right_observation_id' => $right];
}

// Ordinary income with one Interac email.
observation($db, 101, 'ofx_operating', '45.00', '2026-09-02', ['fitid' => 'F101', 'name' => 'INTERAC DEPOSIT', 'memo' => 'E-TRANSFER']);
observation($db, 901, 'interac_email', '45.00', '2026-09-02', ['payment_message' => 'Uniform payment for Josie', 'payer_name' => 'Pat Parent', 'section' => 'Cubs', 'reference_number' => 'R1']);
match_rows($db, 101, 901);
allocate($db, 101, [['Uniform Sales', '45.00', '']]);
// Expense reimbursement matched to a group of two claims: one transaction, grouped evidence.
observation($db, 102, 'ofx_operating', '-120.00', '2026-09-05', ['fitid' => 'F102', 'name' => 'E-TRANSFER SENT', 'memo' => 'Alex Leader']);
observation($db, 902, 'gravity_forms', '-80.00', '2026-09-01', ['description' => 'Camp food', 'payee' => 'Costco', 'submitter_name' => 'Alex Leader', 'external_id' => 'REQ-1'], '55');
observation($db, 903, 'gravity_forms', '-40.00', '2026-09-01', ['description' => 'Tarps', 'payee' => 'Canadian Tire', 'submitter_name' => 'Alex Leader', 'external_id' => 'REQ-2'], '56');
match_rows($db, 102, 902);
match_rows($db, 102, 903);
allocate($db, 102, [['Camp Expenses', '80.00', 'Claim #55'], ['Supplies', '40.00', 'Claim #56']]);
// Credit-card expense.
observation($db, 103, 'ofx_visa', '-30.00', '2026-09-06', ['fitid' => 'F103', 'name' => 'DOLLARAMA', 'memo' => 'DOLLARAMA #12']);
allocate($db, 103, [['Supplies', '30.00', '']]);
// Plain bank expense.
observation($db, 104, 'ofx_operating', '-60.00', '2026-09-07', ['fitid' => 'F104', 'name' => 'HALL RENTAL', 'memo' => 'Church hall']);
allocate($db, 104, [['Camp Expenses', '60.00', '']]);
// Split income.
observation($db, 105, 'ofx_operating', '200.00', '2026-09-08', ['fitid' => 'F105', 'name' => 'DEPOSIT', 'memo' => 'Branch deposit']);
allocate($db, 105, [['Program Fees', '150.00', 'Fall fees'], ['Donations', '50.00', '']]);
// Ready transactions that stay out of preview A.
foreach ([106 => '10.00', 110 => '11.00', 111 => '12.00', 112 => '13.00'] as $id => $amount) {
    observation($db, $id, 'ofx_operating', $amount, '2026-09-09', ['fitid' => 'F' . $id, 'name' => 'DEPOSIT ' . $id]);
    allocate($db, $id, [['Donations', $amount, '']]);
}
// Accepted payment-account transfer: hidden from Ready by the unchanged transfer rule.
observation($db, 107, 'ofx_operating', '-500.00', '2026-09-10', ['fitid' => 'F107', 'name' => 'TRANSFER']);
observation($db, 108, 'ofx_fund', '500.00', '2026-09-10', ['fitid' => 'F108', 'name' => 'TRANSFER']);
match_rows($db, 107, 108);
allocate($db, 107, [['Camp Fund', '500.00', '']]);
allocate($db, 108, [['Operating Account', '500.00', '']]);
// Ready transaction with a historical orphan source row whose preview item no longer exists.
observation($db, 109, 'ofx_operating', '9.00', '2026-09-11', ['fitid' => 'F109', 'name' => 'DEPOSIT 109']);
allocate($db, 109, [['Donations', '9.00', '']]);
$db->t['sources'][1] = ['source_id' => 1, 'item_id' => 999, 'observation_id' => 109, 'source_system' => 'ofx_operating', 'source_key' => 'ofx_operating-109', 'transaction_date' => '2026-09-11', 'amount' => '9.00', 'description' => ''];

$export = SAT_Wave_Export::instance();
$wave = SAT_Wave::instance();
$transfer = SAT_Transfer_OFX::instance();
$ready_candidates = [101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112];

/** Mirrors SAT_Inbox::renderTransactions() placement for rows whose review bucket is ready. */
function ready_view(SAT_Wave_Export $export, SAT_Transfer_OFX $transfer, array $ids): array
{
    $transfer_ids = $transfer->matched_transfer_observation_ids();
    $staged_ids = $export->staged_observation_ids();
    $shown = [];
    $count = 0;
    foreach ($ids as $id) {
        $placement = SAT_Transfer_OFX::inbox_placement('ready', isset($transfer_ids[$id]) || isset($staged_ids[$id]), 'ready');
        if ($placement['count'] === 'ready') {
            $count++;
        }
        if ($placement['show']) {
            $shown[] = $id;
        }
        $all = SAT_Transfer_OFX::inbox_placement('ready', isset($transfer_ids[$id]) || isset($staged_ids[$id]), 'all');
        expect($all['show'], 'Every row must remain visible in All, including staged #' . $id);
    }
    return ['shown' => $shown, 'count' => $count];
}

$inbox_source = (string)file_get_contents(dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-inbox.php');
expect(str_contains($inbox_source, '$staged_ids = SAT_Wave_Export::instance()->staged_observation_ids();'), 'Inbox must load staged preview observations.');
expect(str_contains($inbox_source, 'SAT_Transfer_OFX::inbox_placement($bucket, isset($transfer_ids[$id]) || isset($staged_ids[$id]), $f[\'status\']);'), 'Inbox listing and counts must hide staged observations through the shared Ready placement.');

/* ------------------------------------------------------------------ */
/* Pure Send all sequencing                                            */
/* ------------------------------------------------------------------ */

$sent = [];
$result = SAT_Wave_Export::run_sequence([
    ['item_id' => 1, 'sort_order' => 3, 'item_status' => 'pending'],
    ['item_id' => 2, 'sort_order' => 1, 'item_status' => 'pending'],
    ['item_id' => 3, 'sort_order' => 2, 'item_status' => 'exported'],
    ['item_id' => 4, 'sort_order' => 5, 'item_status' => 'pending'],
    ['item_id' => 5, 'sort_order' => 4, 'item_status' => 'failed'],
], function (array $item) use (&$sent): bool {
    $sent[] = $item['item_id'];
    if ($item['item_id'] === 5) {
        throw new RuntimeException('Wave said no.');
    }
    return true;
});
same([2, 1, 5], $sent, 'Send all must follow sort_order, skip exported items and stop at the first failure.');
same(['exported' => 2, 'skipped' => 1, 'failed' => 1, 'remaining' => 1, 'failed_item' => 4], array_intersect_key($result, array_flip(['exported', 'skipped', 'failed', 'remaining', 'failed_item'])), 'Sequence counts.');
[$type, $message] = SAT_Wave_Export::send_all_notice($result);
same('error', $type, 'A stopped run is reported as an error.');
expect(str_contains($message, '2 exported, 1 failed, 1 remaining') && str_contains($message, 'Stopped at item 4: Wave said no.') && str_contains($message, 'Later items were not attempted'), 'Notice reports exported, failed and remaining counts: ' . $message);
$result = SAT_Wave_Export::run_sequence([['item_id' => 9, 'sort_order' => 1, 'item_status' => 'pending']], static fn() => false);
same(['success', 'Nothing to send: every item in this preview is already exported.'], SAT_Wave_Export::send_all_notice($result), 'An item found already exported during the run is skipped, not counted as exported.');

/* ------------------------------------------------------------------ */
/* Ready for Wave staging                                              */
/* ------------------------------------------------------------------ */

$before = ready_view($export, $transfer, $ready_candidates);
same([101, 102, 103, 104, 105, 106, 109, 110, 111, 112], $before['shown'], 'Before previewing, transfers are hidden and an orphan source row does not hide #109.');
same(10, $before['count'], 'Ready count before previewing.');

$batch_a = call_private($export, 'create_batch', ['105', '101', '104', '103', '102']);
$items_a = call_private($export, 'items', $batch_a);
same(5, count($items_a), 'Preview A has one item per selected ordinary transaction.');
$item_for = [];
foreach ($items_a as $item) {
    $ids = json_decode($item['source_observation_ids'], true);
    same(1, count($ids), 'Ordinary items have one frozen source.');
    $item_for[$ids[0]] = $item;
    same(null, $item['validation_errors'], 'Preview item for #' . $ids[0] . ' must validate: ' . (string)$item['validation_errors']);
}

// Ordinary income, expense, split and grouped-evidence previews are unchanged.
same('transaction', $item_for[101]['item_kind'], 'Income kind.');
same('from Pat Parent - Uniform payment for Josie', $item_for[101]['description'], 'Interac income names the payer and the payment message.');
same('DEPOSIT', $item_for[101]['anchor_direction'], 'Bank income anchor direction.');
same(external_id_for(['ofx_operating:ofx_operating-101']), $item_for[101]['external_id'], 'Deterministic external ID.');
same('split', $item_for[102]['item_kind'], 'Two allocation lines make a split.');
same('Costco - Camp food; Canadian Tire - Tarps', $item_for[102]['description'], 'Grouped claims stay one item with joined purposes.');
expect(str_contains((string)$item_for[102]['item_note'], 'Claim #55') && str_contains((string)$item_for[102]['item_note'], 'Claim #56'), 'Grouped claim note keeps both claims.');
same('WITHDRAWAL', $item_for[102]['anchor_direction'], 'Bank expense anchor direction.');
same('DEPOSIT', $item_for[103]['anchor_direction'], 'Credit-card purchase anchor direction is unchanged.');
same('HALL RENTAL — Church hall', $item_for[104]['description'], 'Plain expense uses cleaned bank text.');
same('split', $item_for[105]['item_kind'], 'Split income kind.');
$split_lines = call_private($export, 'lines', (int)$item_for[105]['item_id']);
same(['Fall fees', 'Split 2/2 · Operating FITID F105 | SAT #105'], [$split_lines[0]['line_note'], $split_lines[1]['line_note']], 'Split line notes are unchanged.');

$staged = $export->staged_observation_ids();
foreach ([101, 102, 103, 104, 105] as $id) {
    same($batch_a, $staged[$id] ?? null, 'Observation #' . $id . ' is staged in preview A.');
}
expect(!isset($staged[109]), 'An orphan source row without an existing item does not stage #109.');
expect(!isset($staged[901]) && !isset($staged[902]), 'Evidence observations are not preview sources.');
$after = ready_view($export, $transfer, $ready_candidates);
same([106, 109, 110, 111, 112], $after['shown'], 'Staged observations disappear from the Ready listing.');
same(5, $after['count'], 'Staged observations disappear from the Ready count.');

// Defensive server-side guard for stale or crafted selections.
try {
    call_private($export, 'create_batch', ['101', '106']);
    expect(false, 'A staged observation must not enter another preview.');
} catch (RuntimeException $e) {
    expect(str_contains($e->getMessage(), 'Transaction #101 is already in Wave preview #' . $batch_a), 'Staged guard message: ' . $e->getMessage());
}
expect(!isset($export->staged_observation_ids()[106]), 'A rejected preview creates nothing.');

/* ------------------------------------------------------------------ */
/* Preview action bar and item headers                                 */
/* ------------------------------------------------------------------ */

function render_batch(SAT_Wave_Export $export, int $batch_id): string
{
    ob_start();
    call_private($export, 'render_batch', $batch_id);
    return (string)ob_get_clean();
}

$html = render_batch($export, $batch_a);
$bar = strpos($html, 'class="sat-wave-actionbar"');
$scroll = strpos($html, '<div class="scroll">');
$edit = strpos($html, 'id="sat-wave-preview-edit"');
expect($bar !== false && $scroll !== false && $bar < $edit && $edit < $scroll, 'The action bar must precede the horizontally scrolling preview.');
$bar_html = substr($html, $bar, $edit - $bar);
foreach (['Send all ready items to Wave', 'name="action" value="sat_wave_export_all"', 'value="nonce-sat_wave_export_all"', 'Download preview CSV', 'Download result CSV', 'Discard preview', '5 to send'] as $needle) {
    expect(str_contains($bar_html, $needle), 'Action bar contains ' . $needle);
}
expect(!str_contains($bar_html, 'disabled'), 'Send all is enabled when every unexported item is valid.');
foreach (['Send all ready items to Wave', 'Discard preview', 'Download preview CSV'] as $needle) {
    same(1, substr_count($html, $needle), 'No duplicated batch action: ' . $needle);
}
expect(!str_contains($html, '<th>Action</th>') && !str_contains($html, '<th>#</th>'), 'The far-right action column is gone.');
same(5, substr_count($html, 'class="primary sat-wave-export-one"'), 'Each pending item has one Export one button.');
$offset = 0;
while (($pos = strpos($html, 'sat-wave-export-one', $offset)) !== false) {
    $head = strrpos(substr($html, 0, $pos), '<tr class="');
    expect(substr($html, $head, strlen('<tr class="sat-wave-item-head"')) === '<tr class="sat-wave-item-head"', 'Export one sits in the item header row.');
    $offset = $pos + 1;
}
expect(preg_match('/sat-wave-item-head.*?class="pill sat-wave-status">PENDING<\/span><button type="submit" class="primary sat-wave-export-one"[^>]*>Export one<\/button><span class="sat-wave-item-summary"><strong>/s', $html) === 1, 'Export one sits between the status pill and description.');

/* ------------------------------------------------------------------ */
/* Discarding releases observations back to Ready                      */
/* ------------------------------------------------------------------ */

$batch_b = call_private($export, 'create_batch', ['106']);
same([109, 110, 111, 112], ready_view($export, $transfer, $ready_candidates)['shown'], 'Preview B stages #106.');
[$type, $message] = post_action([$export, 'action_discard'], ['_wpnonce' => 'nonce-sat_wave_export', 'batch_id' => (string)$batch_b]);
same('success', $type, 'Discarding an untouched preview succeeds: ' . $message);
same([106, 109, 110, 111, 112], ready_view($export, $transfer, $ready_candidates)['shown'], 'Discarded preview observations return to Ready.');

/* ------------------------------------------------------------------ */
/* Capability and dedicated nonce                                      */
/* ------------------------------------------------------------------ */

$send_all = static fn(int $batch_id, string $nonce = 'nonce-sat_wave_export_all') => post_action([$export, 'action_export_all'], ['_wpnonce' => $nonce, 'batch_id' => (string)$batch_id]);
$rejected = static function (callable $callback, string $class, string $message): void {
    try {
        $callback();
    } catch (Throwable $e) {
        expect($e instanceof $class, $message . ' (got ' . get_class($e) . ')');
        return;
    }
    expect(false, $message);
};
$rejected(fn() => $send_all($batch_a, 'nonce-sat_wave_export'), NonceFailure::class, 'Send all requires its dedicated nonce, not the shared preview nonce.');
$rejected(fn() => post_action([$export, 'action_export_one'], ['_wpnonce' => 'nonce-sat_wave_export_all', 'batch_id' => (string)$batch_a, 'item_id' => (string)$item_for[101]['item_id']]), NonceFailure::class, 'The Send all nonce does not authorize Export one.');
$GLOBALS['wp_user']['caps'] = [];
$rejected(fn() => $send_all($batch_a), WpDie::class, 'Send all requires the Wave export capability.');
$GLOBALS['wp_user'] = ['logged_in' => false, 'caps' => [SAT_CAP_REVIEW => true]];
$rejected(fn() => $send_all($batch_a), WpDie::class, 'Send all requires a logged-in user.');
$GLOBALS['wp_user'] = ['logged_in' => true, 'caps' => [SAT_CAP_REVIEW => true]];
same([], $wave->calls, 'Rejected requests never reach Wave.');

/* ------------------------------------------------------------------ */
/* Send all: order, skip, stop on failure, resume                      */
/* ------------------------------------------------------------------ */

// Make preview order differ from item_id order: 103, 101, 105, 102, 104.
foreach ([103 => 1, 101 => 2, 105 => 3, 102 => 4, 104 => 5] as $id => $order) {
    $db->t['items'][(int)$item_for[$id]['item_id']]['sort_order'] = $order;
}
$external = static fn(int $id) => $item_for[$id]['external_id'];

// Export one still works and marks #101 exported before Send all.
[$type, $message] = post_action([$export, 'action_export_one'], ['_wpnonce' => 'nonce-sat_wave_export', 'batch_id' => (string)$batch_a, 'item_id' => (string)$item_for[101]['item_id']]);
same(['success', 'One transaction exported to Wave.'], [$type, $message], 'Export one is preserved.');
same([$external(101)], array_column($wave->calls, 'externalId'), 'Export one makes exactly one Wave call.');

$wave->fail = [$external(105) => 'Wave is unavailable.'];
$wave->calls = [];
[$type, $message] = $send_all($batch_a);
same([$external(103), $external(105)], array_column($wave->calls, 'externalId'), 'First Send all exports in sort order, skips exported #101 and stops at failing #105.');
same('error', $type, 'Stopped Send all reports an error.');
expect(str_contains($message, 'Send all: 1 exported, 1 failed, 2 remaining.') && str_contains($message, '1 already exported and skipped.') && str_contains($message, 'Stopped at item 3: Wave export failed.') && str_contains($message, 'Wave is unavailable.'), 'Failure notice: ' . $message);
$state = static fn(int $id) => $db->t['items'][(int)$item_for[$id]['item_id']];
same(['exported', 'exported', 'failed', 'pending', 'pending'], [$state(103)['item_status'], $state(101)['item_status'], $state(105)['item_status'], $state(102)['item_status'], $state(104)['item_status']], 'Item states after a stopped run.');
same([0, 0], [(int)$state(102)['attempt_count'], (int)$state(104)['attempt_count']], 'Items after the failure were never attempted.');
same('Wave is unavailable.', $state(105)['last_error'], 'record_failure stores the error.');
same('Wave is unavailable.', $db->t['reviews'][105]['last_export_error'], 'record_failure annotates the review.');
same('ready', $db->t['reviews'][105]['review_status'], 'A failed item keeps its Ready review status.');
same('partial', $db->t['batches'][$batch_a]['batch_status'], 'Batch status uses the existing partial rule.');
same(['exported', 'failed'], array_values(array_map(static fn($attempt) => $attempt['attempt_status'], array_filter($db->t['attempts'], static fn($attempt) => in_array((int)$attempt['item_id'], [(int)$item_for[103]['item_id'], (int)$item_for[105]['item_id']], true)))), 'Attempts are recorded for each Wave call.');
same([106, 109, 110, 111, 112], ready_view($export, $transfer, $ready_candidates)['shown'], 'A failed preview item stays out of Ready for Wave.');
try {
    call_private($export, 'create_batch', ['105']);
    expect(false, 'A failed preview item must be retried from its existing preview.');
} catch (RuntimeException $e) {
    expect(str_contains($e->getMessage(), 'already in Wave preview #' . $batch_a), 'Failed item guard: ' . $e->getMessage());
}

$html = render_batch($export, $batch_a);
expect(str_contains($html, '>Retry one</button>') && !str_contains($html, 'Discard preview') && str_contains($html, '3 to send') && str_contains($html, 'Failed items are retried with the same external ID'), 'Partially sent preview shows Retry one, no Discard, and a retry warning on Send all.');

// Retry resumes with the failed item and never resends completed items.
$wave->fail = [];
$wave->calls = [];
[$type, $message] = $send_all($batch_a);
same([$external(105), $external(102), $external(104)], array_column($wave->calls, 'externalId'), 'Retry resumes with remaining items in order.');
same(['success', 'Send all: 3 exported, 0 failed, 0 remaining. 2 already exported and skipped. All items in this preview are now exported.'], [$type, $message], 'Resume notice.');
same('completed', $db->t['batches'][$batch_a]['batch_status'], 'Batch completes.');
same(2, (int)$state(105)['attempt_count'], 'The failed item keeps its external ID and records a second attempt.');
foreach ([101, 102, 103, 104, 105] as $id) {
    same('exported', $db->t['reviews'][$id]['review_status'] ?? null, 'Source #' . $id . ' is marked exported.');
}

// Grouped matched evidence was sent as one transaction with both claim lines.
$grouped = array_values(array_filter($wave->calls, static fn($call) => $call['externalId'] === $external(102)));
same(1, count($grouped), 'Grouped evidence is one Wave transaction.');
same('Costco - Camp food; Canadian Tire - Tarps', $grouped[0]['description'], 'Grouped description.');
same([['accountId' => 'ACC-CAMP', 'amount' => 80.0, 'balance' => 'INCREASE'], ['accountId' => 'ACC-SUP', 'amount' => 40.0, 'balance' => 'INCREASE']], $grouped[0]['lineItems'], 'Grouped expense split lines increase their expense accounts (debits) against the bank withdrawal (credit).');
same(['accountId' => 'ACC-OP', 'amount' => 120.0, 'direction' => 'WITHDRAWAL'], $grouped[0]['anchor'], 'Grouped anchor.');
$split = array_values(array_filter($wave->calls, static fn($call) => $call['externalId'] === $external(105)))[0];
same([['accountId' => 'ACC-FEES', 'amount' => 150.0, 'balance' => 'INCREASE'], ['accountId' => 'ACC-DON', 'amount' => 50.0, 'balance' => 'INCREASE']], $split['lineItems'], 'Split income lines are unchanged.');

$wave->calls = [];
same(['success', 'Nothing to send: every item in this preview is already exported.'], $send_all($batch_a), 'Send all on a completed preview sends nothing.');
same([], $wave->calls, 'Completed items are never resent.');
expect(!str_contains(render_batch($export, $batch_a), 'Send all ready items to Wave'), 'A completed preview has no Send all action.');

/* ------------------------------------------------------------------ */
/* Claim corrections in new and frozen previews                        */
/* ------------------------------------------------------------------ */

$before_corrections = $db->t;
$correction = static function (FakePDO $db, int $observation, string $field, string $value, string $source): void {
    static $next = 1;
    $db->t['corrections'][$next] = [
        'correction_id' => $next, 'observation_id' => $observation, 'field_name' => $field, 'corrected_value' => $value,
        'source_value' => $source, 'observed_source_value' => $source, 'reason_code' => 'ocr_extraction', 'source_state' => 'clear',
        'version' => 1, 'created_by' => 7, 'created_at' => '2026-10-01 10:00:00', 'updated_by' => 7, 'updated_at' => '2026-10-01 10:00:00',
    ];
    $next++;
    SAT_Claim_Corrections::forget();
};
observation($db, 130, 'ofx_visa', '-25.00', '2026-09-20', ['fitid' => 'F130', 'name' => 'ESSO 1234', 'memo' => 'ESSO']);
observation($db, 930, 'gravity_forms', '25.00', '2026-09-19', ['description' => 'Gas for camp', 'payee' => 'Easily', 'submitter_name' => 'Alex Leader'], '606');
match_rows($db, 130, 930);
allocate($db, 130, [['Supplies', '25.00', 'Claim #606']]);
$correction($db, 930, 'payee', 'ESSO', 'Easily');
$correction($db, 930, 'description', 'Fuel, CAMP trailer', 'Gas for camp');
$correction($db, 930, 'amount', '26.00', '25.00');
$correction($db, 930, 'transaction_date', '2026-09-18', '2026-09-19');
$matches_before = $db->t['matches'];
$allocations_before = $db->t['allocations'];
$batch_corrected = call_private($export, 'create_batch', ['130']);
$corrected_item = array_values(call_private($export, 'items', $batch_corrected))[0];
same('ESSO - Fuel, CAMP trailer', $corrected_item['description'], 'A new preview uses the effective payee and description.');
same('-25.00', $db->t['observations'][130]['amount'], 'The bank transaction amount is untouched by the claim amount correction.');
same('2026-09-20', $corrected_item['transaction_date'], 'The Wave transaction date stays the bank date; the claim date is evidence only.');
same('25.00', $corrected_item['amount'], 'The Wave amount stays the bank amount; a claim amount correction never rebalances it.');
same($matches_before, $db->t['matches'], 'Creating a preview with corrected claims leaves matches unchanged.');
same($allocations_before, $db->t['allocations'], 'Creating a preview with corrected claims leaves allocations unchanged.');
expect(!str_contains(render_batch($export, $batch_corrected), 'Claim payee or description corrections were saved after this preview'), 'A preview created after the correction has no stale-claim notice.');

// A later correction never rewrites the frozen preview; it is flagged for discard/recreate.
$db->t['corrections'][1]['corrected_value'] = 'Esso Station';
$db->t['correction_events'][] = ['observation_id' => 930, 'gf_entry_id' => 606, 'field_name' => 'payee', 'event_type' => 'value_changed', 'created_at' => date('Y-m-d H:i:s', time() + 5)];
SAT_Claim_Corrections::forget();
$db->t['batches'][$batch_corrected]['created_at'] = date('Y-m-d H:i:s');
$html = render_batch($export, $batch_corrected);
expect(str_contains($html, 'Claim payee or description corrections were saved after this preview was created (items #1)'), 'An unexported preview built from since-corrected claim data says so.');
expect(str_contains($html, 'CLAIM CORRECTED'), 'The affected item is marked.');
same('ESSO - Fuel, CAMP trailer', array_values(call_private($export, 'items', $batch_corrected))[0]['description'], 'The frozen description is not silently changed.');
// Completed export history is never flagged or rewritten.
$completed_items = call_private($export, 'items', $batch_a);
$correction($db, 902, 'payee', 'Costco Wholesale', 'Costco');
$db->t['correction_events'][] = ['observation_id' => 902, 'gf_entry_id' => 55, 'field_name' => 'payee', 'event_type' => 'created', 'created_at' => date('Y-m-d H:i:s', time() + 5)];
expect(!str_contains(render_batch($export, $batch_a), 'CLAIM CORRECTED'), 'Completed previews are not flagged.');
same($completed_items, call_private($export, 'items', $batch_a), 'Completed export history is unchanged by later corrections.');
$db->t = $before_corrections;
SAT_Claim_Corrections::forget();

/* ------------------------------------------------------------------ */
/* Stale recovery, in-flight items and validation stop Send all        */
/* ------------------------------------------------------------------ */

$batch_c = call_private($export, 'create_batch', ['106', '110', '111']);
$items_c = call_private($export, 'items', $batch_c);
[$c1, $c2, $c3] = array_map(static fn($item) => (int)$item['item_id'], $items_c);
// #106 was abandoned mid-request 1 hour ago; #110 is being exported by another request right now.
$db->t['items'][$c1] = ['item_status' => 'exporting', 'attempt_count' => 1, 'attempted_at' => time() - 3600] + $db->t['items'][$c1];
$db->t['attempts'][5000] = ['attempt_id' => 5000, 'item_id' => $c1, 'attempt_number' => 1, 'attempt_status' => 'started', 'external_id' => $items_c[0]['external_id'], 'started_by' => 7, 'started_at' => time() - 3600, 'wave_transaction_id' => null, 'error_message' => null, 'finished_at' => null];
$db->t['items'][$c2] = ['item_status' => 'exporting', 'attempt_count' => 1, 'attempted_at' => time()] + $db->t['items'][$c2];
$wave->calls = [];
[$type, $message] = $send_all($batch_c);
same([$items_c[0]['external_id']], array_column($wave->calls, 'externalId'), 'Stale item is recovered and retried with the same external ID; the in-flight item stops the run.');
same('interrupted', $db->t['attempts'][5000]['attempt_status'], 'Stale attempt is marked interrupted.');
same(['exported', 'exporting', 'pending'], [$db->t['items'][$c1]['item_status'], $db->t['items'][$c2]['item_status'], $db->t['items'][$c3]['item_status']], 'In-flight item is untouched and later items are unattempted.');
expect(str_contains($message, '1 exported, 1 failed, 1 remaining') && str_contains($message, 'This item is already being exported.'), 'Uncertain item notice: ' . $message);

// A validation failure also stops the run without contacting Wave.
$db->t['items'][$c2]['item_status'] = 'failed';
SAT_Settings::$token = '';
$wave->calls = [];
[$type, $message] = $send_all($batch_c);
same([], $wave->calls, 'Validation failure makes no Wave call.');
expect($type === 'error' && str_contains($message, '0 exported, 1 failed, 1 remaining') && str_contains($message, 'SAT_WAVE_TOKEN is not configured.'), 'Validation stop notice: ' . $message);
same('pending', $db->t['items'][$c3]['item_status'], 'Later item still unattempted after a validation stop.');
SAT_Settings::$token = 'test-token';
$html = render_batch($export, $batch_c);
expect(preg_match('/class="primary sat-wave-export-all" disabled title="1 unexported item\(s\) have validation errors/', $html) === 1, 'Send all is disabled while an unexported item has stored validation errors.');

/* ------------------------------------------------------------------ */
/* Accounting direction matrix                                         */
/* ------------------------------------------------------------------ */

/**
 * Independent model of Wave's documented semantics ("Mutation: Create Money Transaction"):
 * DEPOSIT debits an ASSET anchor and credits a LIABILITY anchor, WITHDRAWAL the opposite; INCREASE
 * applies a line on its type's normal side (inverted for DISCOUNTS / DEPRECIATION_AND_AMORTIZATION).
 */
function wave_entries(array $input): array
{
    $accounts = [];
    foreach (SAT_Settings::$wave['discovered_accounts'] as $account) {
        $accounts[$account['id']] = $account;
    }
    $flip = static fn(string $side) => $side === 'DEBIT' ? 'CREDIT' : 'DEBIT';
    $anchor = $accounts[$input['anchor']['accountId']];
    $deposit = ['ASSET' => 'DEBIT', 'LIABILITY' => 'CREDIT'][$anchor['type_value']];
    $totals = ['DEBIT' => 0, 'CREDIT' => 0];
    $totals[$input['anchor']['direction'] === 'DEPOSIT' ? $deposit : $flip($deposit)] += (int)round($input['anchor']['amount'] * 100);
    foreach ($input['lineItems'] as $line) {
        $account = $accounts[$line['accountId']];
        $normal = ['ASSET' => 'DEBIT', 'EXPENSE' => 'DEBIT', 'INCOME' => 'CREDIT', 'LIABILITY' => 'CREDIT', 'EQUITY' => 'CREDIT'][$account['type_value']];
        if (in_array($account['subtype_value'], ['DISCOUNTS', 'DEPRECIATION_AND_AMORTIZATION'], true)) {
            $normal = $flip($normal);
        }
        $totals[$line['balance'] === 'INCREASE' ? $normal : $flip($normal)] += (int)round($line['amount'] * 100);
    }
    return $totals;
}

function expect_double_entry(array $input, string $label): void
{
    $totals = wave_entries($input);
    expect($totals['DEBIT'] > 0 && $totals['CREDIT'] > 0 && $totals['DEBIT'] === $totals['CREDIT'], $label . ' must have balanced debit and credit sides: ' . json_encode($totals));
}

$matrix = [
    // id => [source, amount, [[category, amount], ...], expected anchor direction, expected line balances, label]
    201 => ['ofx_operating', '25.00', [['Donations', '25.00']], 'DEPOSIT', ['INCREASE'], 'Bank deposit to income'],
    202 => ['ofx_operating', '-10.00', [['Training and certification', '10.00']], 'WITHDRAWAL', ['INCREASE'], 'Bank withdrawal to expense (acceptance case)'],
    203 => ['ofx_visa', '-30.00', [['Supplies', '30.00']], 'DEPOSIT', ['INCREASE'], 'Credit-card purchase to expense'],
    204 => ['ofx_visa', '12.00', [['Supplies', '12.00']], 'WITHDRAWAL', ['DECREASE'], 'Credit-card refund reducing expense'],
    205 => ['ofx_operating', '-100.00', [['Camp Expenses', '70.00'], ['Prepaid Camp Deposits', '30.00']], 'WITHDRAWAL', ['INCREASE', 'INCREASE'], 'Bank withdrawal split to expense and asset'],
    206 => ['ofx_visa', '-50.00', [['Supplies', '20.00'], ['Training and certification', '30.00']], 'DEPOSIT', ['INCREASE', 'INCREASE'], 'Credit-card purchase split'],
    207 => ['ofx_operating', '60.00', [['Program Fees', '45.00'], ['Camp Expenses', '15.00']], 'DEPOSIT', ['INCREASE', 'DECREASE'], 'Bank deposit split to income and an expense refund'],
    208 => ['ofx_visa', '40.00', [['Supplies', '25.00'], ['Training and certification', '15.00']], 'WITHDRAWAL', ['DECREASE', 'DECREASE'], 'Credit-card refund split'],
    209 => ['ofx_operating', '18.00', [['Camp Expenses', '18.00']], 'DEPOSIT', ['DECREASE'], 'Bank deposit refunding an expense'],
    210 => ['ofx_operating', '-5.00', [['Sales Discounts', '5.00']], 'WITHDRAWAL', ['INCREASE'], 'Bank withdrawal to a contra-income discount account'],
];
foreach ($matrix as $id => [$source, $amount, $allocations]) {
    observation($db, $id, $source, $amount, '2026-10-01', ['fitid' => 'F' . $id, 'name' => 'MATRIX ' . $id]);
    allocate($db, $id, array_map(static fn($line) => [$line[0], $line[1], ''], $allocations));
}
$matrix_batch = call_private($export, 'create_batch', array_map('strval', array_keys($matrix)));
foreach (call_private($export, 'items', $matrix_batch) as $item) {
    $id = json_decode($item['source_observation_ids'], true)[0];
    [, , , $direction, $balances, $label] = $matrix[$id];
    $lines = call_private($export, 'lines', (int)$item['item_id']);
    same(null, $item['validation_errors'], $label . ' validates: ' . (string)$item['validation_errors']);
    same($direction, $item['anchor_direction'], $label . ' anchor direction.');
    same($balances, array_column($lines, 'balance_direction'), $label . ' line balances.');
    expect_double_entry(call_private($export, 'wave_input', $item, $lines), $label);
}

// The validator rejects one-sided payloads before any Wave call.
$account = static function (string $name): array {
    foreach (SAT_Settings::$wave['discovered_accounts'] as $account) {
        if ($account['name'] === $name) {
            return $account;
        }
    }
    throw new LogicException($name);
};
$one_sided = [
    'Bank withdrawal with an expense DECREASE' => [$account('Operating Account'), 'WITHDRAWAL', $account('Training and certification'), 'DECREASE', 'credits'],
    'Card purchase with an expense DECREASE' => [$account('Group Visa'), 'DEPOSIT', $account('Training and certification'), 'DECREASE', 'credits'],
    'Card refund with an expense INCREASE' => [$account('Group Visa'), 'WITHDRAWAL', $account('Training and certification'), 'INCREASE', 'debits'],
    'Bank deposit with an income DECREASE' => [$account('Operating Account'), 'DEPOSIT', $account('Donations'), 'DECREASE', 'debits'],
];
foreach ($one_sided as $label => [$anchor, $direction, $line_account, $balance, $all]) {
    $errors = SAT_Wave_Export::double_entry_errors($anchor, $direction, '10.00', [[1, $line_account, $balance, '10.00']]);
    expect(count($errors) === 1 && str_contains($errors[0], 'One-sided transaction') && str_contains($errors[0], 'All sides are ' . $all) && str_contains($errors[0], 'at least one debit and one credit'), $label . ': ' . json_encode($errors));
}
same([], SAT_Wave_Export::double_entry_errors($account('Operating Account'), 'WITHDRAWAL', '10.00', [[1, $account('Training and certification'), 'INCREASE', '10.00']]), 'The corrected withdrawal shape passes.');
same([], SAT_Wave_Export::double_entry_errors($account('Operating Account'), 'WITHDRAWAL', '10.00', [[1, $account('Training and certification'), 'DEBIT', '10.00']]), 'Explicit DEBIT lines are understood.');
$mixed = SAT_Wave_Export::double_entry_errors($account('Operating Account'), 'WITHDRAWAL', '10.00', [[1, $account('Supplies'), 'INCREASE', '6.00'], [2, $account('Supplies'), 'DECREASE', '4.00']]);
expect(count($mixed) === 1 && str_contains($mixed[0], 'Debits and credits do not balance'), 'Mixed lines that do not balance are rejected: ' . json_encode($mixed));
$unknown = SAT_Wave_Export::double_entry_errors($account('Operating Account'), 'WITHDRAWAL', '10.00', [[1, ['name' => 'Legacy', 'type_value' => ''], 'INCREASE', '10.00']]);
expect(count($unknown) === 1 && str_contains($unknown[0], 'Line 1 account type is unknown'), 'Unknown account types are reported, not guessed.');
same('', SAT_Wave_Export::balancing_line_balance($account('Operating Account'), 'WITHDRAWAL', ['type_value' => '']), 'No direction is guessed for an unknown account type.');

/* ------------------------------------------------------------------ */
/* Recovery of a preview frozen with the old one-sided direction       */
/* ------------------------------------------------------------------ */

observation($db, 301, 'ofx_operating', '-10.00', '2026-10-02', ['fitid' => 'F301', 'name' => 'COURSE FEE']);
allocate($db, 301, [['Training and certification', '10.00', '']]);
observation($db, 302, 'ofx_operating', '-20.00', '2026-10-03', ['fitid' => 'F302', 'name' => 'CAMPSITE']);
allocate($db, 302, [['Camp Expenses', '20.00', '']]);
$batch_18 = call_private($export, 'create_batch', ['301', '302']);
[$item_1, $item_2] = array_map(static fn($item) => (int)$item['item_id'], call_private($export, 'items', $batch_18));
$external_ids = [$db->t['items'][$item_1]['external_id'], $db->t['items'][$item_2]['external_id']];
// Reproduce the frozen pre-fix state: expense lines DECREASE, stored as Valid; item 1 rejected once by Wave.
foreach ($db->t['lines'] as $line_id => $line) {
    if (in_array((int)$line['item_id'], [$item_1, $item_2], true)) {
        $db->t['lines'][$line_id]['balance_direction'] = 'DECREASE';
    }
}
$wave_rejection = 'input.lineItems: Transaction must have at least one debit and credit line item.';
$db->t['items'][$item_1] = ['item_status' => 'failed', 'attempt_count' => 1, 'attempted_at' => time() - 600, 'last_error' => $wave_rejection, 'validation_errors' => null] + $db->t['items'][$item_1];
$db->t['attempts'][7000] = ['attempt_id' => 7000, 'item_id' => $item_1, 'attempt_number' => 1, 'attempt_status' => 'failed', 'external_id' => $external_ids[0], 'started_by' => 7, 'started_at' => time() - 600, 'wave_transaction_id' => null, 'error_message' => $wave_rejection, 'finished_at' => time() - 600];
call_private($export, 'update_batch_status', $batch_18);

$html = render_batch($export, $batch_18);
same(0, substr_count($html, '<span class="sat-wave-valid">Valid</span>'), 'Frozen one-sided items are not displayed as Valid.');
same(2, substr_count($html, 'One-sided transaction'), 'Both frozen items explain the imbalance.');
expect(preg_match('/class="primary sat-wave-export-all" disabled/', $html) === 1, 'Send all is disabled for frozen one-sided items.');
expect(preg_match('/class="primary sat-wave-export-one"[^>]* disabled[^>]*>Retry one/', $html) === 1 && preg_match('/class="primary sat-wave-export-one"[^>]* disabled[^>]*>Export one/', $html) === 1, 'Retry one and Export one are disabled for frozen one-sided items.');
expect(!str_contains($html, 'Discard preview'), 'An attempted preview still cannot be discarded.');

$wave->calls = [];
[$type, $message] = $send_all($batch_18);
same([], $wave->calls, 'A crafted Send all on the frozen preview never reaches Wave.');
expect($type === 'error' && str_contains($message, '0 exported, 1 failed, 1 remaining') && str_contains($message, 'One-sided transaction'), 'Send all stops at item 1 with the validation reason: ' . $message);
[$type, $message] = post_action([$export, 'action_export_one'], ['_wpnonce' => 'nonce-sat_wave_export', 'batch_id' => (string)$batch_18, 'item_id' => (string)$item_2]);
expect($type === 'error' && str_contains($message, 'One-sided transaction'), 'Export one on item 2 is refused before Wave.');
same([], $wave->calls, 'Still no Wave call.');
same([1, 0], [(int)$db->t['items'][$item_1]['attempt_count'], (int)$db->t['items'][$item_2]['attempt_count']], 'Refused validation records no new attempts.');

[$type, $message] = post_action([$export, 'action_save_preview'], [
    '_wpnonce' => 'nonce-sat_wave_export', 'batch_id' => (string)$batch_18,
    'description' => [$item_1 => 'Wilderness first aid course', $item_2 => $db->t['items'][$item_2]['description']],
    'item_note' => [$item_1 => (string)$db->t['items'][$item_1]['item_note'], $item_2 => (string)$db->t['items'][$item_2]['item_note']],
]);
same(['success', 'Descriptions and notes saved. Validation refreshed. Accounting direction recalculated on 2 line(s); review the line directions before exporting.'], [$type, $message], 'Save and revalidate recalculates and reports the directions.');
foreach ([$item_1, $item_2] as $item_id) {
    same(['INCREASE'], array_column(call_private($export, 'lines', $item_id), 'balance_direction'), 'Expense line now increases the expense.');
    same(null, $db->t['items'][$item_id]['validation_errors'], 'Recalculated item validates.');
}
same($external_ids, [$db->t['items'][$item_1]['external_id'], $db->t['items'][$item_2]['external_id']], 'External IDs are unchanged.');
same(['failed', $wave_rejection, 1], [$db->t['attempts'][7000]['attempt_status'], $db->t['attempts'][7000]['error_message'], (int)$db->t['items'][$item_1]['attempt_count']], 'The failed attempt history is preserved.');
same(['WITHDRAWAL', '10.00'], [$db->t['items'][$item_1]['anchor_direction'], (string)$db->t['items'][$item_1]['amount']], 'Anchor direction and amount are unchanged.');

[$type, $message] = $send_all($batch_18);
same($external_ids, array_column($wave->calls, 'externalId'), 'Send all now exports both items in order with their original external IDs.');
foreach ($wave->calls as $index => $call) {
    expect_double_entry($call, 'Recovered item ' . ($index + 1));
}
same(['success', 'Send all: 2 exported, 0 failed, 0 remaining. All items in this preview are now exported.'], [$type, $message], 'Recovery notice.');
same(2, (int)$db->t['items'][$item_1]['attempt_count'], 'Item 1 records its second attempt.');
same(2, count(array_filter($db->t['attempts'], static fn($attempt) => (int)$attempt['item_id'] === $item_1)), 'Item 1 keeps both attempts.');

/* ------------------------------------------------------------------ */
/* Interac income default description                                  */
/* ------------------------------------------------------------------ */

$interac_bank = ['observation_id' => 401, 'source_system' => 'ofx_operating', 'source_key' => 'ofx_operating-401', 'transaction_date' => '2026-10-04', 'amount' => '35.00', 'description' => 'E-TRANSFER', 'gf_entry_id' => null, 'payload_json' => json_encode(['fitid' => 'F401', 'name' => 'INTERAC DEPOSIT', 'memo' => 'E-TRANSFER'])];
$interac = static function (array $payload): array {
    return ['observation_id' => 951, 'source_system' => 'interac_email', 'source_key' => 'interac_email:C1A2B3', 'transaction_date' => '2026-10-04', 'amount' => '35.00', 'description' => '', 'gf_entry_id' => null, 'payload_json' => json_encode($payload)];
};
$describe = static fn(array $payload) => call_private($export, 'default_description', $interac_bank, [$interac($payload)]);
same('from PENG FEN LIU - Daniel Yuan', $describe(['payer_name' => 'PENG FEN LIU', 'payment_message' => 'Daniel Yuan']), 'Payer plus payment message.');
same('from PENG FEN LIU', $describe(['payer_name' => 'PENG FEN LIU', 'payment_message' => '']), 'Payer only.');
same('from PENG FEN LIU', $describe(['payer_name' => 'PENG FEN LIU']), 'Payer only, message key absent.');
same('Daniel Yuan', $describe(['payer_name' => '', 'payment_message' => 'Daniel Yuan']), 'Message only.');
same('from PENG FEN LIU - Daniel Yuan uniform', $describe(['payer_name' => 'PENG FEN LIU', 'payment_message' => 'Peng Fen Liu - Daniel Yuan uniform']), 'A message starting with the payer does not repeat it.');
same('from PENG FEN LIU', $describe(['payer_name' => 'PENG FEN LIU', 'payment_message' => 'peng fen liu']), 'A message equal to the payer collapses to the payer.');
same('from Ann - Annual camp fee', $describe(['payer_name' => 'Ann', 'payment_message' => 'Annual camp fee']), 'A payer that is only a prefix of a word is not stripped.');
same('from PENG FEN LIU - 刘一', $describe(['payer_name' => 'PENG FEN LIU', 'payment_message' => 'Peng Fen Liu: 刘一']), 'Stripping the payer keeps multibyte message text intact.');
same('from PENG FEN LIU - 学费一', $describe(['payer_name' => 'PENG FEN LIU', 'payment_message' => '学费一']), 'A multibyte message is unchanged.');
same('from PENG FEN LIU - Daniel Yuan', $describe(['payer_name' => '  PENG  FEN LIU ', 'payment_message' => ' Daniel   Yuan ']), 'Whitespace is normalized.');
same('INTERAC DEPOSIT — E-TRANSFER', $describe(['payer_name' => '', 'payment_message' => '']), 'Neither payer nor message falls back to the bank text.');
same(
    'Interac · from PENG FEN LIU · Beavers · ref C1A2B3 | Operating FITID F401 | SAT #401',
    call_private($export, 'default_note', $interac_bank, [$interac(['payer_name' => 'PENG FEN LIU', 'payment_message' => 'Daniel Yuan', 'section' => 'Beavers', 'reference_number' => 'C1A2B3'])]),
    'The note keeps payer, section, reference, FITID and SAT identifiers.'
);

/* ------------------------------------------------------------------ */
/* Discard/export ordering and batch locks                             */
/* ------------------------------------------------------------------ */

observation($db, 501, 'ofx_operating', '5.00', '2026-10-05', ['name' => 'LOCK TEST']);
allocate($db, 501, [['Donations', '5.00', '']]);
$lock_batch = call_private($export, 'create_batch', ['501']);
$lock_item = (int)call_private($export, 'items', $lock_batch)[0]['item_id'];
$wave->on_create = static function () use ($export, $lock_batch, $db): void {
    [$type, $message] = post_action([$export, 'action_discard'], ['_wpnonce' => 'nonce-sat_wave_export', 'batch_id' => $lock_batch]);
    same('error', $type, 'Discard must refuse an export awaiting its remote result.');
    expect(str_contains($message, 'audit record'), 'In-flight discard explains audit preservation.');
    expect(isset($db->t['items'][array_key_last($db->t['items'])]), 'In-flight item is retained.');
};
$db->queries = [];
call_private($export, 'export_item', $lock_batch, $lock_item);
$wave->on_create = null;
same('exported', $db->t['items'][$lock_item]['item_status'], 'Export completes after a refused discard.');
same(['SELECT * FROM sat_wave_export_batches WHERE batch_id = ? FOR UPDATE', true], $db->queries[0], 'Export locks the batch inside its transaction before reading the item.');

observation($db, 502, 'ofx_operating', '6.00', '2026-10-05', ['name' => 'DISCARD FIRST']);
allocate($db, 502, [['Donations', '6.00', '']]);
$discard_first = call_private($export, 'create_batch', ['502']);
$discard_item = (int)call_private($export, 'items', $discard_first)[0]['item_id'];
$db->queries = [];
post_action([$export, 'action_discard'], ['_wpnonce' => 'nonce-sat_wave_export', 'batch_id' => $discard_first]);
same(['SELECT * FROM sat_wave_export_batches WHERE batch_id = ? FOR UPDATE', true], $db->queries[0], 'Discard locks the batch before checking attempts or deleting.');
$before_calls = count($wave->calls);
$rejected(fn() => call_private($export, 'export_item', $discard_first, $discard_item), RuntimeException::class, 'Export after discard fails.');
same($before_calls, count($wave->calls), 'Discard-first export never reaches Wave.');

/* ------------------------------------------------------------------ */
/* Transfer create, confirm, cancel, retry and rollback                 */
/* ------------------------------------------------------------------ */

$ordinary_db = $db;
$db = new FakePDO();
SAT_DB::$pdo = $db;
$wave_calls_before_transfers = count($wave->calls);
foreach (SAT_Settings::$banks as $source => $bank) {
    SAT_Settings::$accounts[$bank['acctid']] = ['source_system' => $source];
}
foreach ([[601, 'ofx_operating', '-25.00'], [602, 'ofx_visa', '25.00']] as [$id, $source, $amount]) {
    $bank = SAT_Settings::$banks[$source];
    observation($db, $id, $source, $amount, '2026-10-05', [
        'account_id' => $bank['acctid'], 'statement_type' => $bank['type'],
        'currency' => 'CAD', 'bank_id' => '123', 'branch_id' => '456', 'account_type' => 'CHECKING',
        'memo' => 'Lifecycle test',
    ]);
}
match_rows($db, 601, 602);
$transfer_initial = $db->t;
$transfer_post = static fn(string $action, array $extra = []) => post_action([$transfer, $action], ['_wpnonce' => 'nonce-sat_transfer_ofx'] + $extra);
foreach (['action_create', 'action_confirm', 'action_cancel', 'action_download'] as $action) {
    $rejected(fn() => post_action([$transfer, $action], ['_wpnonce' => 'wrong']), NonceFailure::class, $action . ' requires its nonce.');
    $GLOBALS['wp_user']['caps'] = [];
    $rejected(fn() => $transfer_post($action), WpDie::class, $action . ' requires review capability.');
    $GLOBALS['wp_user'] = ['logged_in' => false, 'caps' => [SAT_CAP_REVIEW => true]];
    $rejected(fn() => $transfer_post($action), WpDie::class, $action . ' requires login.');
    $GLOBALS['wp_user'] = ['logged_in' => true, 'caps' => [SAT_CAP_REVIEW => true]];
}
same($transfer_initial, $db->t, 'Rejected transfer handlers never modify persistence.');
[$type] = $transfer_post('action_create', ['selected_matches' => ['1']]);
same('success', $type, 'Create handler generates a transfer batch.');
$transfer_batch = (int)array_key_first($db->t['transfer_batches']);
same(2, count($db->t['reservations']), 'Creation reserves both observations.');
same(2, count($db->t['transfer_legs']), 'Creation freezes both legs.');
same([1, 1], array_column($db->t['transfer_files'], 'transaction_count'), 'Creation records complete file counts.');
$frozen = $db->t;
same('error', $transfer_post('action_create', ['selected_matches' => ['1']])[0], 'A reserved pair cannot be batched twice.');
same($frozen, $db->t, 'Duplicate creation rolls back the new batch.');
same('error', $transfer_post('action_confirm', ['batch_id' => $transfer_batch])[0], 'Confirmation requires explicit imported/matched acknowledgement.');
same('error', $transfer_post('action_cancel', ['batch_id' => $transfer_batch])[0], 'Cancellation requires explicit nothing-in-Wave acknowledgement.');
same($frozen, $db->t, 'Missing acknowledgements preserve the generated batch.');

// Confirmation refuses lost ownership, changed review state and incomplete legs.
foreach (['reservation', 'excluded', 'exported', 'leg'] as $defect) {
    $db->t = $frozen;
    if ($defect === 'reservation') {
        $db->t['reservations'][602]['batch_id'] = 9999;
    } elseif ($defect === 'leg') {
        array_pop($db->t['transfer_legs']);
    } else {
        $db->t['reviews'][602] = ['bank_observation_id' => 602, 'review_status' => $defect];
    }
    $before = $db->t;
    same('error', $transfer_post('action_confirm', ['batch_id' => $transfer_batch, 'confirm_imported' => '1'])[0], 'Confirmation rejects ' . $defect . '.');
    same($before, $db->t, 'Rejected confirmation is atomic for ' . $defect . '.');
}
$db->t = $frozen;
$db->fail_sql = 'INSERT INTO inbox_reviews (bank_observation_id, review_status, export_route';
$db->fail_after = 2;
same('error', $transfer_post('action_confirm', ['batch_id' => $transfer_batch, 'confirm_imported' => '1'])[0], 'A failure on the second review write is reported.');
same($frozen, $db->t, 'Confirmation failure rolls back the first review write as well.');
$db->fail_sql = null;
same('success', $transfer_post('action_confirm', ['batch_id' => $transfer_batch, 'confirm_imported' => '1'])[0], 'Confirmation succeeds after retry.');
same('confirmed', $db->t['transfer_batches'][$transfer_batch]['batch_status'], 'Confirmed status is persisted.');
same(7, $db->t['transfer_batches'][$transfer_batch]['confirmed_by'], 'Confirmation actor is persisted.');
same(2, count($db->t['reservations']), 'Confirmed reservations remain for duplicate protection.');
foreach ([601, 602] as $id) {
    same(['exported', 'transfer_ofx', $transfer_batch], [$db->t['reviews'][$id]['review_status'], $db->t['reviews'][$id]['export_route'], $db->t['reviews'][$id]['transfer_ofx_batch_id']], 'Both reviews record the transfer route and batch.');
}
$confirmed = $db->t;
same('error', $transfer_post('action_confirm', ['batch_id' => $transfer_batch, 'confirm_imported' => '1'])[0], 'Repeat confirmation is refused.');
same('error', $transfer_post('action_cancel', ['batch_id' => $transfer_batch, 'cancel_safe' => '1'])[0], 'Confirmed batch cannot be cancelled.');
same($confirmed, $db->t, 'Confirmed audit state is unchanged by rejected transitions.');

$db->t = $frozen;
$db->fail_sql = 'DELETE FROM sat_transfer_ofx_reservations';
$db->fail_after = 1;
same('error', $transfer_post('action_cancel', ['batch_id' => $transfer_batch, 'cancel_safe' => '1'])[0], 'Cancellation persistence failure is reported.');
same($frozen, $db->t, 'Cancellation failure rolls back status and retains reservations.');
$db->fail_sql = null;
same('success', $transfer_post('action_cancel', ['batch_id' => $transfer_batch, 'cancel_safe' => '1'])[0], 'Cancellation succeeds after retry.');
same([], $db->t['reservations'], 'Cancellation releases reservations.');
same($frozen['transfer_legs'], $db->t['transfer_legs'], 'Cancellation retains frozen legs.');
same($frozen['transfer_matches'], $db->t['transfer_matches'], 'Cancellation retains match snapshots.');
same($frozen['transfer_files'], $db->t['transfer_files'], 'Cancellation retains file snapshots.');
same($frozen['reviews'], $db->t['reviews'], 'Cancellation does not mark reviews exported.');
same('cancelled', $db->t['transfer_batches'][$transfer_batch]['batch_status'], 'Cancellation status is persisted.');
same(7, $db->t['transfer_batches'][$transfer_batch]['cancelled_by'], 'Cancellation actor is persisted.');
same('error', $transfer_post('action_cancel', ['batch_id' => $transfer_batch, 'cancel_safe' => '1'])[0], 'Repeat cancellation is refused.');
same('error', $transfer_post('action_confirm', ['batch_id' => $transfer_batch, 'confirm_imported' => '1'])[0], 'Cancelled batch cannot be confirmed.');
same('success', $transfer_post('action_create', ['selected_matches' => ['1']])[0], 'Safely cancelled pair may be generated again.');
same(2, count($db->t['transfer_batches']), 'Regeneration preserves cancelled history.');
same(array_column($frozen['transfer_legs'], 'fitid'), array_slice(array_column($db->t['transfer_legs'], 'fitid'), 2), 'Regeneration keeps deterministic FITIDs.');

$db->t = $transfer_initial;
$db->fail_sql = 'INSERT INTO sat_transfer_ofx_reservations';
$db->fail_after = 2;
same('error', $transfer_post('action_create', ['selected_matches' => ['1']])[0], 'Failure reserving the second leg is reported.');
same($transfer_initial, $db->t, 'Failed creation leaves no batch, legs, files or reservations.');
$db->fail_sql = 'INSERT INTO sat_transfer_ofx_batch_files';
$db->fail_after = 1;
same('error', $transfer_post('action_create', ['selected_matches' => ['1']])[0], 'File-record failure after reserving both legs is reported.');
same($transfer_initial, $db->t, 'Late creation failure rolls back both reservations and every snapshot.');
$db->fail_sql = null;
same($wave_calls_before_transfers, count($wave->calls), 'Transfer lifecycle handlers never call Wave.');
same([], $db->unhandled, 'Every transfer lifecycle query was handled.');

/* ------------------------------------------------------------------ */
/* Cleanup/reset deletion preflight                                   */
/* ------------------------------------------------------------------ */

require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-tools.php';
$tools = SAT_Tools::instance();
$delete = static function (array $ids) use ($tools, $db): void {
    $db->beginTransaction();
    try {
        call_private($tools, 'delete_ids', $ids);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
};
foreach (['preview', 'reservation', 'review', 'wave_id', 'matched_evidence'] as $owner) {
    $db->t = $transfer_initial;
    $selected = [601];
    if ($owner === 'preview' || $owner === 'matched_evidence') {
        $db->t['sources'][1] = ['observation_id' => 601, 'item_id' => 123];
        if ($owner === 'matched_evidence') {
            $selected = [602];
        }
    } elseif ($owner === 'reservation') {
        $db->t['reservations'][601] = ['observation_id' => 601, 'batch_id' => 123];
    } elseif ($owner === 'review') {
        $db->t['reviews'][601] = ['bank_observation_id' => 601, 'review_status' => 'exported'];
    } else {
        $db->t['observations'][601]['wave_transaction_id'] = 'WAVE-ID';
    }
    $before = $db->t;
    $db->queries = [];
    try {
        $delete($selected);
        expect(false, 'Cleanup must refuse ' . $owner);
    } catch (RuntimeException $e) {
        expect(str_contains($e->getMessage(), 'Cleanup refused'), 'Cleanup reports the ownership reason.');
    }
    same($before, $db->t, 'Protected records remain intact for ' . $owner);
    expect(!array_filter($db->queries, static fn($q) => str_starts_with($q[0], 'DELETE')), 'Preflight refuses before issuing any delete.');
    expect(str_ends_with($db->queries[0][0], 'FOR UPDATE') && $db->queries[0][1], 'Cleanup locks selected records and matched partners in its transaction.');
}

// A protected record in a later chunk must stop the entire reset before any deletion.
$db->t = $transfer_initial;
for ($id = 10000; $id <= 10500; $id++) {
    observation($db, $id, 'ofx_operating', '1.00', '2026-10-01');
}
$db->t['reservations'][10500] = ['observation_id' => 10500, 'batch_id' => 123];
$before = $db->t;
$db->queries = [];
$rejected(fn() => $delete(range(10000, 10500)), RuntimeException::class, 'Multi-chunk reset refuses a protected final chunk.');
same($before, $db->t, 'A protected later chunk leaves all earlier chunks intact.');
expect(!array_filter($db->queries, static fn($q) => str_starts_with($q[0], 'DELETE')), 'All chunks are checked before deleting any.');

$db->t = $transfer_initial;
allocate($db, 601, [['Supplies', '25.00', '']]);
$db->t['reviews'][601] = ['bank_observation_id' => 601, 'review_status' => 'ready'];
$delete([601]);
expect(!isset($db->t['observations'][601]) && isset($db->t['observations'][602]), 'Unowned cleanup deletes only the selected observation.');
same([], $db->t['matches'], 'Unowned cleanup removes related matches.');
same([], $db->t['allocations'], 'Unowned cleanup removes selected allocations.');
same([], $db->t['reviews'], 'Unowned cleanup removes selected reviews.');

// Active claim corrections go with a deleted claim, each recorded; earlier history is kept.
$db->t = $transfer_initial;
observation($db, 650, 'gravity_forms', '12.00', '2026-10-01', ['payee' => 'Shop', 'description' => 'Rope'], '7650');
$correction($db, 650, 'payee', 'Shop Inc', 'Shop');
$correction($db, 650, 'amount', '13.00', '12.00');
$db->t['correction_events'][] = ['observation_id' => 650, 'gf_entry_id' => 7650, 'field_name' => 'payee', 'event_type' => 'created', 'created_at' => '2026-10-01 10:00:00'];
$delete([650]);
expect(!isset($db->t['observations'][650]), 'Cleanup deletes the corrected claim.');
same([], array_values(array_filter($db->t['corrections'], static fn($c) => (int)$c['observation_id'] === 650)), 'Its active corrections are removed with it, not orphaned.');
$deleted_events = array_values(array_filter($db->t['correction_events'], static fn($e) => (int)$e['observation_id'] === 650 && $e['event_type'] === 'observation_deleted'));
same(2, count($deleted_events), 'One observation_deleted event per removed correction.');
same([7650, 7, 'Shop Inc'], [$deleted_events[0]['gf_entry_id'], $deleted_events[0]['created_by'], $deleted_events[0]['old_effective_value']], 'Deletion events keep the entry, actor and removed value.');
same(1, count(array_filter($db->t['correction_events'], static fn($e) => (int)$e['observation_id'] === 650 && $e['event_type'] === 'created')), 'Earlier correction history is kept.');

// A refused cleanup leaves corrections and history untouched.
$db->t = $transfer_initial;
observation($db, 651, 'gravity_forms', '20.00', '2026-10-01', ['payee' => 'Shop'], '7651');
observation($db, 652, 'ofx_operating', '-20.00', '2026-10-02', ['fitid' => 'F652']);
match_rows($db, 651, 652);
$db->t['sources'][1] = ['observation_id' => 652, 'item_id' => 123];
$correction($db, 651, 'payee', 'Shop Inc', 'Shop');
$before = $db->t;
$rejected(fn() => $delete([651]), RuntimeException::class, 'Cleanup refuses a corrected claim matched to a previewed transaction.');
same($before, $db->t, 'Refused cleanup keeps corrections and their history.');
SAT_Claim_Corrections::forget();
same([], $db->unhandled, 'Every safety query was handled.');
SAT_DB::$pdo = $ordinary_db;
same([], $ordinary_db->unhandled, 'Every ordinary export query was handled.');
echo "SAT Wave export, transfer lifecycle, rollback and cleanup safety tests: OK\n";
