<?php
/**
 * Expense claim corrections: resolver, validation, save/reset/keep through the real Ajax handlers,
 * capability and nonce checks, optimistic concurrency, backfill reconciliation through the real
 * SAT_Expense_Form::import_entry(), Expense Claims rendering and server-authoritative row/summary
 * updates, cleanup removal and the schema files. Uses WordPress stubs and a stateful in-memory fake
 * PDO; never loads WordPress, touches a database or calls Wave. Real-engine locking is not proven here.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('SAT_CAP_REVIEW', 'sat_review');
define('SAT_DIR', dirname(__DIR__) . '/wordpress/scout-accounting-toolkit');
define('SAT_URL', 'https://example.test/wp-content/plugins/scout-accounting-toolkit/');
define('SAT_VERSION', 'test');

/* ------------------------------------------------------------------ */
/* WordPress and SAT stubs                                             */
/* ------------------------------------------------------------------ */

final class JsonResponse extends RuntimeException
{
    public function __construct(public bool $success, public array $data, public int $status)
    {
        parent::__construct('json');
    }
}

final class WP_Error
{
    public function __construct(private string $code = '', private string $message = '') {}
    public function get_error_message(): string { return $this->message; }
}

$GLOBALS['wp_user'] = ['logged_in' => true, 'caps' => [SAT_CAP_REVIEW => true], 'id' => 7];
$GLOBALS['wp_menus'] = [];
$GLOBALS['wp_shortcodes'] = [];
$GLOBALS['wp_assets'] = [];
$GLOBALS['wp_localized'] = [];

function add_action(...$args): void {}
function add_filter(...$args): void {}
function add_shortcode(string $tag, $callback): void { $GLOBALS['wp_shortcodes'][$tag] = $callback; }
function add_menu_page(...$args): void { $GLOBALS['wp_menus'][] = ['top', $args]; }
function add_submenu_page(...$args): void { $GLOBALS['wp_menus'][] = ['child', $args]; }
function wp_enqueue_style(string $handle, ...$args): void { $GLOBALS['wp_assets'][] = ['style', $handle]; }
function wp_enqueue_script(string $handle, ...$args): void { $GLOBALS['wp_assets'][] = ['script', $handle]; }
function wp_localize_script(string $handle, string $name, array $data): void { $GLOBALS['wp_localized'][$name] = $data; }
function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
function apply_filters(string $hook, $value, ...$args) { return $value; }
function is_user_logged_in(): bool { return $GLOBALS['wp_user']['logged_in']; }
function is_admin(): bool { return (bool)($GLOBALS['wp_admin'] ?? false); }
function current_user_can(string $cap): bool { return !empty($GLOBALS['wp_user']['caps'][$cap]); }
function get_current_user_id(): int { return $GLOBALS['wp_user']['id']; }
function get_userdata(int $id) { return $id === 7 ? (object)['display_name' => 'Treasurer Test'] : false; }
function check_ajax_referer(string $action, $arg = false, bool $die = true)
{
    return ($_POST['nonce'] ?? '') === 'nonce-' . $action ? 1 : false;
}
function wp_send_json_success($data = null, int $status = 200): void { throw new JsonResponse(true, (array)$data, $status); }
function wp_send_json_error($data = null, int $status = 400): void { throw new JsonResponse(false, (array)$data, $status); }
function wp_json_encode($value, int $flags = 0) { return json_encode($value, $flags); }
function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
function wp_unslash($value) { return $value; }
function wp_validate_redirect(string $location, string $fallback = ''): string { return $location; }
function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function wp_login_url(string $redirect = ''): string { return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode($redirect); }
function wp_die($message = '', $title = '', $args = []): void { throw new RuntimeException((string)$message); }
function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string)$text, ENT_QUOTES); }
function esc_url($url) { return htmlspecialchars((string)$url, ENT_QUOTES); }
function esc_url_raw($url) { return (string)$url; }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string)$key)); }
function sanitize_text_field($text) { return trim((string)$text); }
function current_time(string $type) { return $type === 'Y-m' ? '2026-10' : date('Y-m-d H:i:s'); }
function get_date_from_gmt(string $gmt, string $format): string { return (new DateTimeImmutable($gmt, new DateTimeZone('UTC')))->format($format); }
function number_format_i18n(int $number): string { return number_format($number); }
function rgar($array, $key, $default = null) { return is_array($array) && isset($array[$key]) ? $array[$key] : $default; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function remove_query_arg($keys, $url = '') { return 'https://example.test/inbox/?sat_view=claims'; }
function add_query_arg($args, $url = '', $base = '') { $GLOBALS['wp_query_args'][] = [$args, $url, $base]; return 'https://example.test/inbox/?sat_view=claims'; }
function selected($a, $b, bool $echo = true): string { return (string)$a === (string)$b ? ' selected="selected"' : ''; }
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">'; }

final class SAT_Settings
{
    public static array $configured_banks = ['ofx_visa' => ['label' => 'Group Visa', 'type' => 'credit_card', 'wave' => 'Group Visa', 'acctid' => 'V']];

    public static function get(string $section): array
    {
        return match ($section) {
            'form' => ['form_id' => 9, 'name' => '1', 'email' => '3', 'amount' => '9', 'date' => '8', 'category' => '5', 'payee' => '4', 'description' => '21', 'payment' => '6', 'receipt' => '7', 'external_id' => '17'],
            'inbox' => ['section_map' => []],
            'wave' => ['discovered_accounts' => [['name' => 'Group activities', 'type' => 'Expenses']]],
            default => [],
        };
    }
    public static function banks(): array { return self::$configured_banks; }
    public static function canonical_category(string $category): string { return $category; }
}

final class FakeMissingTable extends PDOException
{
    public function __construct()
    {
        parent::__construct("Table 'sat_observation_corrections' doesn't exist");
        $this->code = '42S02';
    }
}

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
        return $mode === PDO::FETCH_COLUMN ? array_map(static fn ($row) => reset($row), $rows) : $rows;
    }
    public function fetchColumn($column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : array_values($row)[$column];
    }
}

/** Stateful fake of the exact claim, correction and event queries, with transactions and uniqueness. */
final class FakePDO extends PDO
{
    public array $t = ['obs' => [], 'matches' => [], 'corrections' => [], 'events' => [], 'allocations' => []];
    public array $log = [];
    public array $summary_rows = [];
    public bool $missing = false;
    public ?Closure $before = null;
    private ?array $snapshot = null;
    private int $next = 1;

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

    private function in_ids(array $p): array { return array_map('intval', $p); }

    public function run(string $sql, array $p): array
    {
        $s = trim((string)preg_replace('/\s+/', ' ', $sql));
        $this->log[] = [$s, $this->inTransaction()];
        if ($this->before !== null) {
            ($this->before)($s);
        }
        if ($this->missing && str_contains($s, 'sat_observation_correction')) {
            throw new FakeMissingTable();
        }
        $now = date('Y-m-d H:i:s');

        if ($s === 'SELECT source_system, COUNT(*) n, MIN(transaction_date) oldest, MAX(transaction_date) newest FROM inbox_observations GROUP BY source_system ORDER BY source_system') {
            return $this->summary_rows;
        }
        if (str_starts_with($s, 'SELECT o.*, CASE WHEN EXISTS')) {
            $rows = array_filter($this->t['obs'], static fn ($o) => $o['source_system'] === 'gravity_forms');
            usort($rows, static fn ($a, $b) => strcmp($b['transaction_date'], $a['transaction_date']) ?: $b['observation_id'] <=> $a['observation_id']);
            return array_map(function ($o) {
                $o['is_matched'] = array_filter($this->t['matches'], static fn ($m) => in_array($o['observation_id'], $m, true)) ? 1 : 0;
                return $o;
            }, $rows);
        }
        $ordered = function (array $rows): array {
            usort($rows, static fn ($a, $b) => [(int)$a['observation_id'], $a['field_name']] <=> [(int)$b['observation_id'], $b['field_name']]);
            return $rows;
        };
        if (preg_match('/^SELECT correction_id, .* FROM sat_observation_corrections ORDER BY observation_id, field_name$/', $s)) {
            return $ordered(array_values($this->t['corrections']));
        }
        if (preg_match('/^SELECT correction_id, .* FROM sat_observation_corrections WHERE observation_id IN \([?,]+\) ORDER BY observation_id, field_name( FOR UPDATE)?$/', $s)) {
            $ids = $this->in_ids($p);
            return $ordered(array_values(array_filter($this->t['corrections'], static fn ($c) => in_array((int)$c['observation_id'], $ids, true))));
        }
        if (str_starts_with($s, 'SELECT c.observation_id AS claim_id')) {
            $out = [];
            foreach ($this->t['matches'] as [$left, $right]) {
                foreach ([[$left, $right], [$right, $left]] as [$claim, $bank]) {
                    if (($this->t['obs'][$claim]['source_system'] ?? '') === 'gravity_forms' && ($this->t['obs'][$bank]['source_system'] ?? 'gravity_forms') !== 'gravity_forms') {
                        $out[] = ['claim_id' => $claim, 'bank_id' => $bank, 'bank_amount' => $this->t['obs'][$bank]['amount']];
                    }
                }
            }
            return $out;
        }
        if (str_starts_with($s, "SELECT observation_id, source_system, transaction_date, amount, description, gf_entry_id, payload_json FROM inbox_observations WHERE observation_id = ? AND source_system = 'gravity_forms' FOR UPDATE")) {
            $o = $this->t['obs'][(int)$p[0]] ?? null;
            return $o && $o['source_system'] === 'gravity_forms' ? [$o] : [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_observation_corrections')) {
            foreach ($this->t['corrections'] as $c) {
                if ((int)$c['observation_id'] === (int)$p[0] && $c['field_name'] === $p[1]) {
                    throw new FakeDuplicate();
                }
            }
            $id = $this->next++;
            $this->t['corrections'][$id] = [
                'correction_id' => $id, 'observation_id' => (int)$p[0], 'field_name' => $p[1], 'corrected_value' => $p[2],
                'source_value' => $p[3], 'observed_source_value' => $p[4], 'reason_code' => $p[5], 'source_state' => 'clear',
                'version' => 1, 'created_by' => $p[6], 'created_at' => $now, 'updated_by' => $p[7], 'updated_at' => $now,
            ];
            return [];
        }
        if (preg_match('/^UPDATE sat_observation_corrections SET (.+) WHERE correction_id = \?$/', $s, $m)) {
            $id = (int)array_pop($p);
            if (!isset($this->t['corrections'][$id])) {
                return [];
            }
            foreach (explode(', ', $m[1]) as $assignment) {
                [$column, $value] = explode(' = ', $assignment, 2);
                $this->t['corrections'][$id][$column] = match (true) {
                    $value === '?' => array_shift($p),
                    $value === 'version + 1' => $this->t['corrections'][$id]['version'] + 1,
                    $value === 'CURRENT_TIMESTAMP' => $now,
                    default => trim($value, "'"),
                };
            }
            return [];
        }
        if ($s === 'DELETE FROM sat_observation_corrections WHERE correction_id = ?') {
            unset($this->t['corrections'][(int)$p[0]]);
            return [];
        }
        if (str_starts_with($s, 'DELETE FROM sat_observation_corrections WHERE observation_id IN (')) {
            $ids = $this->in_ids($p);
            $this->t['corrections'] = array_filter($this->t['corrections'], static fn ($c) => !in_array((int)$c['observation_id'], $ids, true));
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO sat_observation_correction_events')) {
            $this->t['events'][] = ['event_id' => count($this->t['events']) + 1] + array_combine(
                ['observation_id', 'gf_entry_id', 'field_name', 'event_type', 'old_effective_value', 'new_effective_value', 'source_value', 'captured_source_value', 'reason_code', 'created_by'],
                $p
            ) + ['created_at' => $now];
            return [];
        }
        if (str_starts_with($s, 'SELECT event_id, field_name, event_type')) {
            return array_reverse(array_values(array_filter($this->t['events'], static fn ($e) => (int)$e['observation_id'] === (int)$p[0])));
        }
        if (str_starts_with($s, 'SELECT observation_id, gf_entry_id FROM inbox_observations WHERE observation_id IN (')) {
            $ids = $this->in_ids($p);
            return array_values(array_filter($this->t['obs'], static fn ($o) => in_array($o['observation_id'], $ids, true)));
        }
        if (str_starts_with($s, 'INSERT INTO inbox_observations')) {
            [$key, $date, $amount, $text, $entry, $payload] = $p;
            foreach ($this->t['obs'] as $id => $o) {
                if ($o['source_key'] === $key) {
                    $this->t['obs'][$id] = array_merge($o, ['transaction_date' => $date, 'amount' => $amount, 'description' => $text, 'gf_entry_id' => $entry, 'payload_json' => $payload]);
                    return [];
                }
            }
            $id = 200 + count($this->t['obs']) + 1;
            $this->t['obs'][$id] = ['observation_id' => $id, 'source_system' => 'gravity_forms', 'source_key' => $key, 'transaction_date' => $date, 'amount' => $amount, 'direction' => 'expense', 'description' => $text, 'gf_entry_id' => $entry, 'wave_transaction_id' => null, 'payload_json' => $payload];
            return [];
        }
        if ($s === "SELECT observation_id FROM inbox_observations WHERE source_system = 'gravity_forms' AND source_key = ?") {
            foreach ($this->t['obs'] as $o) {
                if ($o['source_key'] === $p[0]) {
                    return [['observation_id' => $o['observation_id']]];
                }
            }
            return [];
        }
        if (str_starts_with($s, 'INSERT INTO inbox_allocations')) {
            $this->t['allocations'][(int)$p[0]] = ['bank_observation_id' => (int)$p[0], 'export_account' => $p[1], 'amount' => $p[2]];
            return [];
        }
        throw new LogicException('Fake PDO does not handle: ' . $s);
    }
}

final class SAT_DB
{
    public static ?PDO $pdo = null;
    public static function pdo(): PDO { return self::$pdo; }
}

require_once SAT_DIR . '/includes/class-sat-expense-form.php';
require_once SAT_DIR . '/includes/class-sat-claim-corrections.php';
require_once SAT_DIR . '/includes/class-sat-expense-report.php';
require_once SAT_DIR . '/includes/class-sat-inbox.php';
require_once SAT_DIR . '/includes/class-sat-admin.php';
require_once SAT_DIR . '/includes/class-sat-tools.php';

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

$GLOBALS['checks'] = 0;
function expect(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
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

/** Call an Ajax handler; returns [success, data, status]. */
function ajax(string $method, array $post): array
{
    $_POST = $post;
    try {
        SAT_Claim_Corrections::instance()->{$method}();
    } catch (JsonResponse $response) {
        return [$response->success, $response->data, $response->status];
    }
    throw new RuntimeException('Handler sent no response.');
}

function load_claim(int $id): array
{
    [$ok, $data] = ajax('ajax_load', ['observation_id' => (string)$id, 'nonce' => 'nonce-sat_claim_correction_read']);
    expect($ok, 'Modal data loads for #' . $id . ': ' . json_encode($data));
    return $data['claim'];
}

function save_claim(int $id, array $values, string $reason = '', ?string $token = null, string $view = '?sat_view=claims'): array
{
    return ajax('ajax_save', [
        'observation_id' => (string)$id, 'nonce' => 'nonce-sat_claim_correction_write',
        'token' => $token ?? load_claim($id)['token'], 'reason' => $reason, 'values' => $values, 'view' => $view,
    ]);
}

function field_op(int $id, string $field, string $operation, ?string $token = null, string $view = '?sat_view=claims'): array
{
    return ajax('ajax_field', [
        'observation_id' => (string)$id, 'nonce' => 'nonce-sat_claim_correction_write',
        'token' => $token ?? load_claim($id)['token'], 'field' => $field, 'operation' => $operation, 'view' => $view,
    ]);
}

function field_of(array $claim, string $field): array
{
    foreach ($claim['fields'] as $f) {
        if ($f['field'] === $field) {
            return $f;
        }
    }
    throw new RuntimeException('No field ' . $field);
}

function active(FakePDO $db, int $id): array
{
    $out = [];
    foreach ($db->t['corrections'] as $c) {
        if ((int)$c['observation_id'] === $id) {
            $out[$c['field_name']] = $c;
        }
    }
    ksort($out);
    return $out;
}

function events(FakePDO $db, int $id): array
{
    return array_values(array_map(static fn ($e) => $e['field_name'] . ':' . $e['event_type'], array_filter($db->t['events'], static fn ($e) => (int)$e['observation_id'] === $id)));
}

function last_event(FakePDO $db, int $id): string
{
    $all = events($db, $id);
    return $all ? $all[count($all) - 1] : '';
}

function entry(int $id, string $date, string $amount, string $payee, string $description, string $method = 'Credit card'): array
{
    return [
        'id' => $id, 'date_created' => '2026-09-20 12:00:00', '1' => 'Pat Example', '3' => 'pat@example.test',
        '9' => $amount, '8' => $date, '5' => 'Group activities', '4' => $payee, '21' => $description, '6' => $method,
        '7' => json_encode(['https://example.test/wp-content/uploads/gravity_forms/9-x/2026/09/r' . $id . '.pdf']), '17' => 'REQ-' . $id,
    ];
}

/* ------------------------------------------------------------------ */
/* Value rules and resolver                                            */
/* ------------------------------------------------------------------ */

foreach ([
    ['transaction_date', '2026-09-30', '2026-09-30'],
    ['transaction_date', '2024-02-29', '2024-02-29'],
    ['payee', '  ESSO  ', 'ESSO'],
    ['payee', 'eSSo Station', 'eSSo Station'],
    ['payee', str_repeat('é', 100), str_repeat('é', 100)],
    ['description', "  Fuel, CAMP trailer — (2 trips)!\r\nSecond line ", "Fuel, CAMP trailer — (2 trips)!\nSecond line"],
    ['description', 'Two  spaces kept', 'Two  spaces kept'],
    ['amount', '28.9', '28.90'],
    ['amount', '$12', '12.00'],
    ['amount', '0.01', '0.01'],
    ['amount', '99999.99', '99999.99'],
    ['amount', '99999999.99', '99999999.99'],
    ['amount', '007.50', '7.50'],
] as [$field, $raw, $expected]) {
    same([$expected, ''], SAT_Claim_Corrections::validate_value($field, $raw), 'Valid ' . $field . ' ' . json_encode($raw));
}
foreach ([
    ['transaction_date', '2026-02-30'], ['transaction_date', '2026-9-1'], ['transaction_date', ' 2026-09-01'],
    ['transaction_date', '01/09/2026'], ['transaction_date', '2026-09-01T00:00'], ['transaction_date', ''],
    ['payee', ''], ['payee', '   '], ['payee', str_repeat('x', 101)], ['payee', "Two\nlines"], ['payee', "Tab\tbed"],
    ['description', ''], ['description', " \n "], ['description', str_repeat('x', 201)], ['description', "Bell\x07"],
    ['amount', '0'], ['amount', '0.00'], ['amount', '-5.00'], ['amount', '5.001'], ['amount', 'abc'], ['amount', '1e3'],
    ['amount', 'NaN'], ['amount', 'INF'], ['amount', '100000000.00'], ['amount', '999999999.99'], ['amount', '1,234.56'], ['amount', '12.'], ['amount', ''],
    ['payee', ['array']], ['claimant', 'Someone'], ['payee', "\xC3\x28"],
] as [$field, $raw]) {
    $result = SAT_Claim_Corrections::validate_value($field, $raw);
    expect($result[0] === '' && $result[1] !== '', 'Rejected ' . $field . ' ' . json_encode($raw));
}
same('0.30', SAT_Claim_Corrections::decimal_amount('0.3'), 'Decimal text, not binary floating point.');
same(1999, SAT_Claim_Corrections::amount_cents('19.99'), 'Cents from decimal text.');
same(30, SAT_Claim_Corrections::amount_cents('0.30'), '0.1 + 0.2 style drift cannot occur.');
expect(SAT_Claim_Corrections::same('amount', '19.9', '19.90') && !SAT_Claim_Corrections::same('amount', '19.90', '19.91'), 'Amounts compare by cents.');
same('', SAT_Claim_Corrections::decimal_amount('1.005'), 'Excess stored precision is not silently rounded.');
same('25.00', SAT_Claim_Corrections::decimal_amount('-25.00'), 'Stored claim amounts are positive.');

$source = ['transaction_date' => '2026-09-14', 'payee' => 'Easily', 'description' => 'Fuel', 'amount' => '61.25'];
$none = SAT_Claim_Corrections::resolve($source, []);
same($source, SAT_Claim_Corrections::effective($none), 'No correction: effective values are the imported values.');
expect(!$none['corrected'] && !$none['review'], 'No correction, no markers.');
$c = static fn (string $field, string $value, string $captured, int $id = 1) => [
    'correction_id' => $id, 'field_name' => $field, 'corrected_value' => $value, 'source_value' => $captured,
    'observed_source_value' => $captured, 'reason_code' => 'other', 'source_state' => 'clear', 'version' => 1,
];
foreach (['transaction_date' => '2026-09-13', 'payee' => 'ESSO', 'description' => 'Fuel for trailer', 'amount' => '16.25'] as $field => $value) {
    $resolved = SAT_Claim_Corrections::resolve($source, [$field => $c($field, $value, $source[$field])]);
    $effective = SAT_Claim_Corrections::effective($resolved);
    same($value, $effective[$field], 'Corrected ' . $field . ' is effective.');
    same(array_diff_key($source, [$field => 1]), array_diff_key($effective, [$field => 1]), 'Other fields stay at their imported values when only ' . $field . ' is corrected.');
    same($source[$field], $resolved['fields'][$field]['source'], 'The imported ' . $field . ' remains available.');
}
$conflict = SAT_Claim_Corrections::resolve(array_merge($source, ['payee' => 'Esso Ltd']), ['payee' => $c('payee', 'ESSO', 'Easily')]);
expect($conflict['review'] && $conflict['fields']['payee']['effective'] === 'ESSO' && $conflict['fields']['payee']['state'] === 'conflict', 'A third imported value keeps the correction effective and needs review.');
same('agrees', SAT_Claim_Corrections::resolve(array_merge($source, ['payee' => 'ESSO']), ['payee' => $c('payee', 'ESSO', 'Easily')])['fields']['payee']['state'], 'Imported value equal to the correction agrees.');
same('agrees', SAT_Claim_Corrections::state_for('amount', '61.25', '16.3', '16.30'), 'Amount agreement is decimal-safe.');
expect(SAT_Claim_Corrections::token($source, []) !== SAT_Claim_Corrections::token(array_merge($source, ['payee' => 'X']), []), 'The token changes with the imported values.');
expect(SAT_Claim_Corrections::token($source, ['payee' => $c('payee', 'A', 'Easily', 1)]) !== SAT_Claim_Corrections::token($source, ['payee' => $c('payee', 'A', 'Easily', 2)]), 'The token changes when a correction is recreated.');

/* ------------------------------------------------------------------ */
/* Fixture: claims imported through the real importer                  */
/* ------------------------------------------------------------------ */

$db = new FakePDO();
SAT_DB::$pdo = $db;
$form = SAT_Expense_Form::instance();
$ids = [];
foreach ([
    entry(9620, '2026-09-10', '42.50', 'Grocer', 'Snacks'),
    entry(9622, '2026-09-12', '18.00', 'Hardwre Shop', 'Rope'),
    entry(9606, '2026-09-14', '61.25', 'Easily', 'Fuel'),
    entry(9579, '2026-09-20', '30.00', 'Costco - camp food', ''),
] as $e) {
    $ids[$e['id']] = $form->import_entry($e, ['id' => 9]);
    expect(is_int($ids[$e['id']]), 'Fixture import of entry ' . $e['id']);
}
[$A, $B, $C, $D] = [$ids[9620], $ids[9622], $ids[9606], $ids[9579]];
$db->t['obs'][301] = ['observation_id' => 301, 'source_system' => 'ofx_visa', 'source_key' => 'ofx-301', 'transaction_date' => '2026-09-15', 'amount' => '-61.25', 'description' => 'ESSO', 'gf_entry_id' => null, 'payload_json' => '{}'];
$db->t['matches'][] = [$C, 301];
$payloads = array_column(array_filter($db->t['obs'], static fn ($o) => $o['source_system'] === 'gravity_forms'), 'payload_json', 'observation_id');
$matches_before = $db->t['matches'];
$allocations_before = $db->t['allocations'];
SAT_Claim_Corrections::forget();

/* ------------------------------------------------------------------ */
/* Authorization, nonces and request safety                            */
/* ------------------------------------------------------------------ */

$GLOBALS['wp_user'] = ['logged_in' => true, 'caps' => [], 'id' => 8];
[$ok, $data, $status] = ajax('ajax_load', ['observation_id' => (string)$A, 'nonce' => 'nonce-sat_claim_correction_read']);
expect(!$ok && $status === 403, 'Users without the review capability cannot read correction data.');
[$ok, , $status] = ajax('ajax_save', ['observation_id' => (string)$A, 'nonce' => 'nonce-sat_claim_correction_write', 'token' => 'x', 'values' => ['payee' => 'Hack']]);
expect(!$ok && $status === 403 && !active($db, $A), 'Users without the review capability cannot save.');
$GLOBALS['wp_user'] = ['logged_in' => false, 'caps' => [SAT_CAP_REVIEW => true], 'id' => 0];
[$ok, , $status] = ajax('ajax_load', ['observation_id' => (string)$A, 'nonce' => 'nonce-sat_claim_correction_read']);
expect(!$ok && $status === 403, 'Logged-out requests are refused.');
$GLOBALS['wp_user'] = ['logged_in' => true, 'caps' => [SAT_CAP_REVIEW => true], 'id' => 7];
[$ok, , $status] = ajax('ajax_load', ['observation_id' => (string)$A, 'nonce' => 'wrong']);
expect(!$ok && $status === 403, 'An invalid read nonce is refused.');
$token = load_claim($A)['token'];
[$ok, , $status] = ajax('ajax_save', ['observation_id' => (string)$A, 'nonce' => 'nonce-sat_claim_correction_read', 'token' => $token, 'values' => ['payee' => 'X']]);
expect(!$ok && $status === 403 && !active($db, $A), 'The read nonce cannot authorize a save.');
[$ok, , $status] = ajax('ajax_field', ['observation_id' => (string)$A, 'nonce' => 'bad', 'token' => $token, 'field' => 'payee', 'operation' => 'reset']);
expect(!$ok && $status === 403, 'An invalid write nonce is refused for field actions.');
foreach (['0', '-1', 'abc', '301', '99999'] as $bad_id) {
    [$ok, , $status] = ajax('ajax_load', ['observation_id' => $bad_id, 'nonce' => 'nonce-sat_claim_correction_read']);
    expect(!$ok && $status === 404, 'Only existing expense claims can be opened: ' . $bad_id);
}
[$ok, $data, $status] = save_claim($A, ['claimant' => 'Someone else']);
expect(!$ok && $status === 400 && !active($db, $A), 'Request-supplied field names outside the allowlist are refused.');
[$ok, $data, $status] = save_claim($A, ['payee' => 'Grocer Two'], 'autocorrect');
expect(!$ok && $status === 422 && isset($data['errors']['reason']) && !active($db, $A), 'Reason codes outside the allowlist are refused.');
[$ok, , $status] = field_op($A, 'claimant', 'reset');
expect(!$ok && $status === 400, 'Field actions accept only the four fields.');
[$ok, , $status] = field_op($A, 'payee', 'delete');
expect(!$ok && $status === 400, 'Unknown field actions are refused.');

$modal = load_claim($C);
$json = json_encode($modal);
foreach (['gravity_forms:', 'payload', 'https://', 'receipt', 'pat@example.test', 'REQ-', 'source_key', 'ofx-301'] as $secret) {
    expect(!str_contains($json, $secret), 'Modal data exposes no ' . $secret);
}
same(['transaction_date', 'payee', 'description', 'amount'], array_column($modal['fields'], 'field'), 'The modal edits exactly four fields.');
same(9606, $modal['entry_id'], 'The modal shows the Gravity Forms entry ID.');
same('other', $modal['reason'], 'The reason defaults to Other.');

/* ------------------------------------------------------------------ */
/* Save, update and reset                                              */
/* ------------------------------------------------------------------ */

[$ok, $data] = save_claim($A, ['transaction_date' => '2026-09-08', 'payee' => 'Grocer', 'description' => 'Snacks', 'amount' => '42.50']);
expect($ok, 'Date correction saves: ' . json_encode($data));
same(['transaction_date'], array_keys(active($db, $A)), 'Only the changed field is corrected.');
same('other', active($db, $A)['transaction_date']['reason_code'], 'An omitted reason is stored as Other.');
same('2026-09-10', active($db, $A)['transaction_date']['source_value'], 'The imported value is captured with the correction.');
same(['transaction_date:created'], events($db, $A), 'Creation is audited.');
$event = $db->t['events'][0];
same(['2026-09-10', '2026-09-08', '2026-09-10', 'other', 7, 9620], [$event['old_effective_value'], $event['new_effective_value'], $event['source_value'], $event['reason_code'], $event['created_by'], $event['gf_entry_id']], 'The event records old and new effective values, source, reason, actor and entry.');
expect(str_contains($data['row']['html'], '<td>2026-09-08</td>') && str_contains($data['row']['html'], 'Corrected'), 'The returned row shows the corrected date and marker.');
$response_json = json_encode($data);
foreach (['https://', 'http:', 'uploads', 'gravity_forms', '.pdf', 'Receipt 1', 'href=', 'payload', 'pat@example.test', 'REQ-'] as $secret) {
    expect(!str_contains($response_json, $secret), 'Save responses expose no ' . $secret);
}
expect(str_contains($data['row']['html'], '<td data-claim-receipts></td>'), 'The returned row leaves the receipt cell for the page to keep.');
expect($data['claim']['corrected'] && field_of($data['claim'], 'transaction_date')['active'], 'The returned modal data shows the active correction.');
same('Treasurer Test', field_of($data['claim'], 'transaction_date')['updated_by'], 'Correction actor is shown by name.');

[$ok] = save_claim($B, ['payee' => 'Hardware SHOP'], 'data_entry');
expect($ok && active($db, $B)['payee']['corrected_value'] === 'Hardware SHOP', 'Payee correction keeps the entered capitalization.');
[$ok] = save_claim($C, ['payee' => 'ESSO'], 'ocr_extraction');
expect($ok, 'Easily -> ESSO payee correction.');
[$ok] = save_claim($D, ['payee' => 'Costco', 'description' => 'Camp food, (week 2)'], 'missing_source');
expect($ok && active($db, $D)['description']['corrected_value'] === 'Camp food, (week 2)' && active($db, $D)['payee']['corrected_value'] === 'Costco', 'A legacy combined value is split into independent payee and description.');
same('Costco - camp food', field_of(load_claim($D), 'payee')['source'], 'The combined imported value is unchanged.');
same('', field_of(load_claim($D), 'description')['source'], 'The missing imported description stays blank in the source.');
[$ok] = save_claim($B, ['description' => 'Rope and TARPS!']);
expect($ok && active($db, $B)['description']['corrected_value'] === 'Rope and TARPS!', 'Description correction keeps capitalization and punctuation.');
same('Hardware SHOP', active($db, $B)['payee']['corrected_value'], 'The payee is not copied into the description or changed by it.');

[$ok, $data] = save_claim($C, ['amount' => '16.25'], 'data_entry');
expect($ok && active($db, $C)['amount']['corrected_value'] === '16.25', 'Amount correction is stored as canonical decimal text.');
same('61.25', $db->t['obs'][$C]['amount'], 'The imported amount is unchanged.');
expect($data['claim']['amount_mismatch'] && str_contains($data['row']['html'], 'Amount differs from match'), 'A corrected amount on a matched claim is flagged.');
same($matches_before, $db->t['matches'], 'The match is not altered.');
same($allocations_before, $db->t['allocations'], 'Allocations are not altered.');
same('-61.25', $db->t['obs'][301]['amount'], 'The bank transaction is not altered.');
[$ok, $data] = save_claim($C, ['amount' => '16.250']);
expect(!$ok && isset($data['errors']['amount']), 'Over-precision amounts are refused.');
foreach (['0.00', '-16.25', 'sixteen', '100000000.00'] as $bad) {
    [$ok, $data] = save_claim($C, ['amount' => $bad]);
    expect(!$ok && $data['errors']['amount'] !== '' && active($db, $C)['amount']['corrected_value'] === '16.25', 'Refused amount ' . $bad);
}
[$ok, $data] = save_claim($A, ['transaction_date' => '2026-02-30', 'payee' => '   ', 'description' => str_repeat('d', 201)]);
expect(!$ok, 'Invalid values are refused together.');
same(['transaction_date', 'payee', 'description'], array_keys($data['errors']), 'Each invalid field gets its own message.');
same(['transaction_date'], array_keys(active($db, $A)), 'A refused save changes nothing.');
[$ok, $data] = save_claim($A, ['payee' => str_repeat('p', 101)]);
expect(!$ok && isset($data['errors']['payee']), 'Overlong payee is refused.');
[$ok, $data] = save_claim($A, ['description' => '']);
expect(!$ok && str_contains($data['errors']['description'], 'requires'), 'Blank description is refused because the expense form requires one.');

$before = count($db->t['events']);
[$ok, $data] = save_claim($A, ['transaction_date' => '2026-09-08', 'payee' => 'Grocer', 'description' => 'Snacks', 'amount' => '42.5']);
expect($ok && $data['message'] === 'No changes to save.' && count($db->t['events']) === $before, 'Unchanged values (including 42.5 = 42.50) create no event.');
[$ok] = save_claim($A, ['transaction_date' => '2026-09-09']);
same(['transaction_date:created', 'transaction_date:value_changed'], events($db, $A), 'Changing a corrected value is audited.');
same(2, (int)active($db, $A)['transaction_date']['version'], 'The correction version increases.');
same(['amount' => 'data_entry', 'payee' => 'ocr_extraction'], array_map(static fn ($r) => $r['reason_code'], active($db, $C)), 'Reasons are stored per field: a save applies its reason only to the fields it changed.');
same(['payee:created', 'amount:created'], events($db, $C), 'Each change is audited with its own reason.');
same(['ocr_extraction', 'data_entry'], array_column(array_values(array_filter($db->t['events'], static fn ($e) => (int)$e['observation_id'] === $C)), 'reason_code'), 'Event reasons follow the field.');
[$ok] = save_claim($C, ['payee' => 'ESSO Canada'], 'missing_source');
same(['amount' => 'data_entry', 'payee' => 'missing_source'], array_map(static fn ($r) => $r['reason_code'], active($db, $C)), 'Updating a corrected value updates its reason; other fields keep theirs.');
[$ok] = save_claim($C, ['payee' => 'ESSO'], 'ocr_extraction');
same('other', load_claim($C)['reason'], 'The reason control defaults to Other for every save.');
$before = count($db->t['events']);
[$ok, $data] = save_claim($C, [], 'missing_source');
expect($ok && $data['message'] === 'No changes to save.' && count($db->t['events']) === $before, 'A reason with no changed field changes nothing.');

$stale = load_claim($B)['token'];
[$ok] = save_claim($B, ['payee' => 'Hardware Shop Ltd']);
[$ok, $data, $status] = save_claim($B, ['payee' => 'Someone Else'], '', $stale);
expect(!$ok && $status === 409 && $data['kind'] === 'stale', 'A save from a stale modal is refused.');
same('Hardware Shop Ltd', active($db, $B)['payee']['corrected_value'], 'The stale save overwrote nothing.');
[$ok, , $status] = field_op($B, 'payee', 'reset', $stale);
expect(!$ok && $status === 409 && isset(active($db, $B)['payee']), 'A stale reset is refused.');

// Per-field "Use source".
$events_before = count($db->t['events']);
[$ok, $data] = field_op($D, 'payee', 'reset');
expect($ok && !isset(active($db, $D)['payee']) && isset(active($db, $D)['description']), 'Use source resets the payee only.');
same('Costco - camp food', field_of($data['claim'], 'payee')['effective'], 'After reset the imported payee is effective again.');
$reset_event = end($db->t['events']);
same(['reset', 'Costco', 'Costco - camp food'], [$reset_event['event_type'], $reset_event['old_effective_value'], $reset_event['new_effective_value']], 'The reset is audited.');
same($events_before + 1, count($db->t['events']), 'One event per reset.');
[$ok] = field_op($D, 'description', 'reset');
expect($ok && !active($db, $D), 'Description reset leaves no active correction (both reset).');
[$ok] = field_op($A, 'transaction_date', 'reset');
expect($ok && !active($db, $A), 'Date reset.');
[$ok] = field_op($C, 'amount', 'reset');
expect($ok && !isset(active($db, $C)['amount']) && isset(active($db, $C)['payee']), 'Amount reset leaves the payee correction.');
expect(!load_claim($C)['amount_mismatch'], 'Amount reset clears the match inconsistency.');
[$ok, , $status] = field_op($A, 'payee', 'reset');
expect(!$ok && $status === 409, 'Resetting a field without a correction is refused.');
[$ok] = save_claim($B, ['description' => 'Rope']);
expect($ok && !isset(active($db, $B)['description']), 'Saving the imported value removes the correction.');
same('reset', end($db->t['events'])['event_type'], 'Saving the imported value is audited as a reset.');
same($payloads, array_column(array_filter($db->t['obs'], static fn ($o) => $o['source_system'] === 'gravity_forms'), 'payload_json', 'observation_id'), 'No correction action modified an imported payload.');

/* ------------------------------------------------------------------ */
/* Lock order: claim row lock, then a plain read of its corrections   */
/* ------------------------------------------------------------------ */

$db->log = [];
save_claim($A, ['payee' => 'Grocer Inc']);
$lock_claim = $read_corrections = null;
foreach ($db->log as $i => [$sql, $in_transaction]) {
    if ($lock_claim === null && str_contains($sql, 'FROM inbox_observations WHERE observation_id = ?') && str_ends_with($sql, 'FOR UPDATE')) {
        $lock_claim = $i;
        expect($in_transaction, 'The claim is locked inside the save transaction.');
    }
    if ($lock_claim !== null && $read_corrections === null && str_contains($sql, 'FROM sat_observation_corrections WHERE')) {
        $read_corrections = $i;
        expect($in_transaction, 'Corrections are read inside the save transaction.');
    }
}
expect($lock_claim !== null && $read_corrections !== null && $lock_claim < $read_corrections, 'Saves read corrections only after locking the claim row.');
expect(!array_filter($db->log, static fn ($q) => str_contains($q[0], 'sat_observation_corrections') && str_contains($q[0], 'FOR UPDATE')), 'Corrections are never read with FOR UPDATE (no InnoDB gap locks between first corrections of different claims).');

/* ------------------------------------------------------------------ */
/* Backfill: source unchanged, agreement, conflict and outcomes        */
/* ------------------------------------------------------------------ */

$db->log = [];
$before_events = count($db->t['events']);
$version = (int)active($db, $C)['payee']['version'];
expect(is_int($form->import_entry(entry(9606, '2026-09-14', '61.25', 'Easily', 'Fuel'), ['id' => 9])), 'Unchanged backfill succeeds.');
same(0, $form->last_review_flags, 'Unchanged source flags nothing.');
same('ESSO', active($db, $C)['payee']['corrected_value'], 'Backfill does not overwrite the correction.');
same([$before_events, $version], [count($db->t['events']), (int)active($db, $C)['payee']['version']], 'Unchanged source records no event.');
$upsert = $read = null;
foreach ($db->log as $i => [$sql, $in_transaction]) {
    if ($upsert === null && str_starts_with($sql, 'INSERT INTO inbox_observations')) {
        $upsert = $i;
    }
    if ($read === null && str_contains($sql, 'FROM sat_observation_corrections WHERE')) {
        $read = $i;
        expect($in_transaction, 'Reconciliation runs inside the import transaction.');
    }
}
expect($upsert !== null && $read !== null && $upsert < $read, 'Backfill writes (and so locks) the claim row before reading its corrections.');
expect(!array_filter($db->log, static fn ($q) => str_contains($q[0], 'sat_observation_corrections') && str_contains($q[0], 'FOR UPDATE')), 'Backfill never reads corrections with FOR UPDATE.');

// A modal opened before a backfill changes the source cannot save over it.
$before_backfill = load_claim($C)['token'];
$form->import_entry(entry(9606, '2026-09-14', '61.25', 'ESSO', 'Fuel'), ['id' => 9]);
same('agrees', active($db, $C)['payee']['source_state'], 'Source now equal to the correction is recorded as agreement.');
same('ESSO', load_claim($C)['fields'][1]['effective'], 'Effective payee is unchanged on agreement.');
same('payee:source_agrees', last_event($db, $C), 'Agreement is audited.');
$agree = load_claim($C);
expect(!$agree['review'] && $agree['corrected'], 'Agreement is not a conflict; the correction stays until reset.');
[$ok, , $status] = save_claim($C, ['payee' => 'Esso'], '', $before_backfill);
expect(!$ok && $status === 409, 'A modal opened before the backfill is stale.');

$form->import_entry(entry(9606, '2026-09-14', '61.25', 'Esso Petroleum', 'Fuel'), ['id' => 9]);
same(1, $form->last_review_flags, 'A third value is counted for the backfill summary.');
$conflict = load_claim($C);
$payee = field_of($conflict, 'payee');
expect($conflict['review'] && $payee['state'] === 'conflict', 'A third imported value needs review.');
same(['ESSO', 'Esso Petroleum', 'Easily'], [$payee['effective'], $payee['source'], $payee['captured_source']], 'The modal has all three values; the correction stays effective.');
same('payee:source_conflict', last_event($db, $C), 'The conflict is audited.');
$conflict_event = end($db->t['events']);
same(['Esso Petroleum', 'Easily', 'ESSO'], [$conflict_event['source_value'], $conflict_event['captured_source_value'], $conflict_event['new_effective_value']], 'The conflict event keeps all three values.');
$claims = [];
foreach (SAT_Expense_Report::instance()->claims() as $claim) {
    $claims[$claim['observation_id']] = $claim;
}
expect($claims[$C]['vendor'] === 'ESSO' && $claims[$C]['correction_review'], 'Expense Claims keeps the correction and shows review needed.');
expect(str_contains(SAT_Inbox::instance()->claim_row_html($claims[$C]), 'Correction review needed'), 'The row shows Correction review needed.');
$stored = json_decode($db->t['obs'][$C]['payload_json'], true);
same('Esso Petroleum', $stored['payee'], 'The imported payload stays source-authoritative.');

[$ok, , $status] = field_op($A, 'payee', 'keep');
expect(!$ok && $status === 409, 'Keep is offered only for a conflict.');
[$ok, $data] = field_op($C, 'payee', 'keep');
expect($ok && !$data['claim']['review'], 'Keep SAT correction clears the review state.');
same(['ESSO', 'Esso Petroleum', 'clear'], [active($db, $C)['payee']['corrected_value'], active($db, $C)['payee']['source_value'], active($db, $C)['payee']['source_state']], 'Keeping captures the reviewed imported value.');
same('conflict_kept', end($db->t['events'])['event_type'], 'Keeping is audited.');

$form->import_entry(entry(9606, '2026-09-14', '61.25', 'Esso Canada', 'Fuel'), ['id' => 9]);
[$ok, $data] = field_op($C, 'payee', 'reset');
expect($ok && !isset(active($db, $C)['payee']) && field_of($data['claim'], 'payee')['effective'] === 'Esso Canada', 'Use current source value resets only that field.');

save_claim($C, ['payee' => 'ESSO', 'description' => 'Fuel for trailer']);
$form->import_entry(entry(9606, '2026-09-14', '61.25', 'Esso Canada', 'Gasoline'), ['id' => 9]);
same(['description' => 'conflict', 'payee' => 'clear'], array_map(static fn ($r) => $r['source_state'], active($db, $C)), 'Conflicts are detected per field.');
[$ok] = save_claim($C, ['description' => 'Fuel for CAMP trailer']);
same(['Fuel for CAMP trailer', 'Gasoline', 'clear'], [active($db, $C)['description']['corrected_value'], active($db, $C)['description']['source_value'], active($db, $C)['description']['source_state']], 'Edit and save from a conflict captures the new imported value.');
expect(!load_claim($C)['review'], 'Editing resolves the conflict.');

// Source returning to the captured value is recorded; amount conflicts compare decimals.
save_claim($A, ['amount' => '40.00']);
$form->import_entry(entry(9620, '2026-09-10', '42.5', 'Grocer', 'Snacks'), ['id' => 9]);
same('clear', active($db, $A)['amount']['source_state'], '42.5 equals the captured 42.50: no conflict.');
$form->import_entry(entry(9620, '2026-09-10', '40.00', 'Grocer', 'Snacks'), ['id' => 9]);
same('agrees', active($db, $A)['amount']['source_state'], 'Amount agreement.');
$form->import_entry(entry(9620, '2026-09-10', '42.50', 'Grocer', 'Snacks'), ['id' => 9]);
same(['clear', 'amount:source_restored'], [active($db, $A)['amount']['source_state'], last_event($db, $A)], 'Return to the captured value is audited.');

// Backfill without the correction tables (migration not applied) still imports; nothing to reconcile.
$db->missing = true;
SAT_Claim_Corrections::forget();
expect(is_int($form->import_entry(entry(9700, '2026-09-25', '5.00', 'New', 'Claim'), ['id' => 9])), 'Imports continue before the correction tables exist.');
expect(!SAT_Claim_Corrections::available(), 'Corrections report as unavailable without their tables.');
[$ok, $data, $status] = save_claim($A, ['payee' => 'X'], '', 'any');
expect(!$ok && $status === 503 && str_contains($data['message'], '003-claim-corrections.sql'), 'Saving without the tables explains the missing migration.');
$db->missing = false;
SAT_Claim_Corrections::forget();

/* ------------------------------------------------------------------ */
/* Server-authoritative row, filter and summary updates                */
/* ------------------------------------------------------------------ */

$view = '?sat_view=claims&claim_preset=cgaming&claim_month=2026-09';
$september = static function (): array {
    $all = SAT_Expense_Report::instance()->claims();
    return SAT_Expense_Report::select($all, SAT_Expense_Report::filters(['claim_preset' => 'cgaming', 'claim_month' => '2026-09'], '2026-10'));
};
$rows_before = $september();
$summary_before = SAT_Expense_Report::summary($rows_before);
[$ok, $data] = save_claim($B, ['transaction_date' => '2026-10-01'], '', null, $view);
expect($ok && $data['row']['in_view'] === false, 'A date moved out of the selected month leaves the view.');
same($summary_before['claims'] - 1, $data['summary']['claims'], 'The included count is recalculated.');
same('$' . number_format(($summary_before['total_cents'] - 1800) / 100, 2), $data['summary']['total'], 'The total is recalculated.');
same($summary_before['receipts'] - 1, $data['summary']['receipts'], 'The receipt count is recalculated.');
[$ok, $data] = field_op($B, 'transaction_date', 'reset', null, $view);
expect($ok && $data['row']['in_view'] === true, 'Resetting the date brings it back into the month.');
[$ok, $data] = save_claim($A, ['amount' => '1.00'], '', null, $view . '&claim_sort=amount&claim_order=desc');
$order = array_column(SAT_Expense_Report::select(SAT_Expense_Report::instance()->claims(), SAT_Expense_Report::filters(['claim_preset' => 'cgaming', 'claim_month' => '2026-09', 'claim_sort' => 'amount', 'claim_order' => 'desc'], '2026-10')), 'observation_id');
$position = array_search($A, $order, true);
same($order[$position + 1] ?? 0, $data['row']['next_id'], 'The response says where the row now sorts.');
same(count($order), $data['summary']['claims'], 'Amount changes keep the count and recalculate the total.');
expect(str_contains($data['row']['html'], '$1.00'), 'The row shows the corrected amount.');
[$ok, $data] = save_claim($D, ['payee' => 'Aardvark Supplies'], '', null, $view . '&claim_sort=vendor&claim_order=asc');
$vendor_order = array_column(SAT_Expense_Report::select(SAT_Expense_Report::instance()->claims(), SAT_Expense_Report::filters(['claim_preset' => 'cgaming', 'claim_month' => '2026-09', 'claim_sort' => 'vendor', 'claim_order' => 'asc'], '2026-10')), 'observation_id');
same($D, $vendor_order[0], 'A payee correction moves the claim in vendor order.');
same($vendor_order[1], $data['row']['next_id'], 'The row is placed before the next claim in vendor order.');

/* ------------------------------------------------------------------ */
/* Expense Claims page                                                 */
/* ------------------------------------------------------------------ */

$_GET = ['sat_view' => 'claims', 'claim_preset' => 'cgaming', 'claim_month' => '2026-09'];
ob_start();
call_private(SAT_Inbox::instance(), 'renderClaims');
$page = (string)ob_get_clean();
expect(substr_count($page, 'class="claim-edit"') === count($september()), 'Every visible claim has an Edit button.');
expect(str_contains($page, '<td data-claim-receipts><a class="sat-receipt-link" href="https://example.test/wp-content/uploads/gravity_forms/'), 'The page itself still links receipts.');
expect(str_contains($page, 'aria-label="View receipt 1"') && str_contains($page, 'aria-hidden="true" focusable="false"'), 'Receipt links expose an accessible name and a decorative SVG.');
expect(str_contains($page, 'data-claim-month="2026-09"') && str_contains($page, 'data-claim-month-label="September 2026"'), 'The table carries the current month for the move warning.');
expect(str_contains($page, 'id="sat-correction-modal"') && str_contains($page, 'role="dialog"') && str_contains($page, 'aria-modal="true"'), 'The page has the accessible dialog.');
expect(substr_count($page, '<span class="pill corrected">Corrected</span>') >= 1, 'Corrected marker on the page.');
expect(preg_match('/data-claim-id="\d+"[^>]*>Edit/', $page) === 1 && !preg_match('/="[^"]*(Grocer|ESSO|Esso|Costco|Aardvark|Hardw)/', $page), 'Claim data is not placed in HTML attributes.');
foreach (['data-claim-summary="claims"', 'data-claim-summary="total"', 'data-claim-summary="receipts"', 'data-claim-receipt-warning', 'data-claim-rows'] as $hook) {
    expect(str_contains($page, $hook), 'Page hook for live updates: ' . $hook);
}
$multi_receipt_claim = $september()[0];
$multi_receipt_claim['receipt_urls'] = [
    'https://example.test/wp-content/uploads/gravity_forms/receipt-one.pdf',
    'https://example.test/wp-content/uploads/gravity_forms/receipt-two.pdf',
];
$single_receipt_claim = $multi_receipt_claim;
$single_receipt_claim['receipt_urls'] = [$multi_receipt_claim['receipt_urls'][0]];
$single_receipt_row = SAT_Inbox::instance()->claim_row_html($single_receipt_claim);
expect(substr_count($single_receipt_row, 'class="sat-receipt-link"') === 1 && !str_contains($single_receipt_row, 'sat-receipts-toggle'), 'One receipt keeps the compact single-icon link.');
expect(str_contains($single_receipt_row, 'aria-label="View receipt 1"') && str_contains($single_receipt_row, 'target="_blank" rel="noopener noreferrer"'), 'The single receipt keeps its accessible name and link behavior.');
$multi_receipt_row = SAT_Inbox::instance()->claim_row_html($multi_receipt_claim);
expect(substr_count($multi_receipt_row, 'aria-label="View receipt 1"') === 1 && substr_count($multi_receipt_row, 'aria-label="View receipt 2"') === 1, 'Multiple receipts receive distinct accessible names.');
expect(substr_count($multi_receipt_row, 'class="sat-receipts-toggle"') === 1 && str_contains($multi_receipt_row, 'aria-label="Show 2 receipts" aria-expanded="false"'), 'Two receipts render as one collapsed, accessible control with the correct count.');
expect(str_contains($multi_receipt_row, 'class="sat-receipts-list" hidden') && substr_count($multi_receipt_row, 'class="sat-receipt-link"') === 2, 'Individual links are present but hidden while the group is collapsed.');
expect(!str_contains($multi_receipt_row, '>Receipt 1<') && !str_contains($multi_receipt_row, '>Receipt 2<'), 'Receipt links show icons instead of visible Receipt N text.');
expect(substr_count($multi_receipt_row, 'target="_blank" rel="noopener noreferrer"') === 2, 'Receipt link target and rel behavior remain unchanged.');
expect(str_contains($multi_receipt_row, 'href="https://example.test/wp-content/uploads/gravity_forms/receipt-one.pdf"') && str_contains($multi_receipt_row, 'href="https://example.test/wp-content/uploads/gravity_forms/receipt-two.pdf"'), 'Receipt link destinations remain unchanged.');
$multi_receipt_claim['receipt_urls'][] = 'https://example.test/wp-content/uploads/gravity_forms/receipt-three.pdf';
$three_receipt_row = SAT_Inbox::instance()->claim_row_html($multi_receipt_claim);
expect(str_contains($three_receipt_row, 'aria-label="Show 3 receipts"') && substr_count($three_receipt_row, 'class="sat-receipt-link"') === 3, 'Three receipts render with the matching count and all links.');
$summary_receipt_links = call_private(SAT_Inbox::instance(), 'receiptLinks', ['receipt_urls' => $multi_receipt_claim['receipt_urls']]);
expect(str_contains($summary_receipt_links, 'aria-label="Show 3 receipts"') && substr_count($summary_receipt_links, 'class="sat-receipt-link"') === 3, 'Matched-evidence summaries reuse the multiple-receipt control.');
$inbox_css = file_get_contents(SAT_DIR . '/assets/inbox.css');
expect(is_string($inbox_css) && preg_match('/\\.sat-inbox \\.sat-receipt-icon\\s*\\{[^}]*max-width:\\s*(\\d+(?:\\.\\d+)?)px/s', $inbox_css, $icon_width) === 1 && (float)$icon_width[1] < 45, 'Receipt icon CSS maximum width remains below 45px.');
expect(is_string($inbox_css) && str_contains($inbox_css, '.sat-receipts-toggle {') && str_contains($inbox_css, 'width: 42px;') && str_contains($inbox_css, '.sat-receipts-list[hidden] { display: none; }'), 'The multi-receipt control remains compact and collapsed links are hidden.');

/* Admin navigation, renderer and narrowly scoped assets. */
SAT_Admin::instance()->menu();
$menu = $GLOBALS['wp_menus'];
expect(count($menu) === 4 && $menu[0][0] === 'top' && $menu[0][1][1] === 'Scout Accounting' && $menu[0][1][2] === SAT_CAP_REVIEW, 'Scout Accounting is a top-level menu using the SAT review capability.');
$menu_icon = $menu[0][1][5] ?? '';
$icon_svg = str_starts_with($menu_icon, 'data:image/svg+xml;base64,') ? base64_decode(substr($menu_icon, strlen('data:image/svg+xml;base64,')), true) : false;
expect(is_string($icon_svg) && str_contains($icon_svg, 'width="20" height="20" viewBox="0 0 24 24"')
    && substr_count($icon_svg, '<path fill="black"') === 1 && !preg_match('/<(image|text|style)\b/', $icon_svg),
    'Scout Accounting uses a self-contained 20px monochrome SVG data URI with a filled path that WordPress can recolour.');
expect($menu[1][1][2] === 'Inbox' && $menu[1][1][3] === SAT_CAP_REVIEW && $menu[1][1][4] === 'sat-inbox', 'Inbox is a child page using the SAT review capability.');
expect($menu[2][1][2] === 'Tools' && $menu[2][1][3] === 'manage_options' && $menu[2][1][4] === 'sat-tools', 'Tools remains available with its existing administrator capability.');
expect($menu[3][1][2] === 'Settings' && $menu[3][1][3] === 'manage_options' && $menu[3][1][4] === 'sat-settings', 'Settings remains available with its existing authorization.');
expect($menu[1][1][5][0] === SAT_Inbox::instance() && $menu[1][1][5][1] === 'admin_page'
    && $menu[2][1][5][0] === SAT_Inbox::instance() && $menu[2][1][5][1] === 'admin_tools_page', 'Inbox and Tools menu pages use the shared Inbox renderer.');
$GLOBALS['wp_admin'] = true;
$_GET['page'] = 'sat-tools';
$GLOBALS['wp_query_args'] = [];
ob_start();
call_private(SAT_Inbox::instance(), 'tabs', 'tools');
ob_end_clean();
expect(in_array(['page', 'sat-inbox', 'https://example.test/inbox/?sat_view=claims'], $GLOBALS['wp_query_args'], true), 'Tools child-page tabs route back to the admin Inbox instead of retaining the forced Tools page.');
$GLOBALS['wp_admin'] = false;
unset($_GET['page']);
$GLOBALS['wp_query_args'] = [];
ob_start();
call_private(SAT_Inbox::instance(), 'tabs', 'transactions');
$frontend_tabs = (string)ob_get_clean();
$GLOBALS['wp_admin'] = true;
ob_start();
call_private(SAT_Inbox::instance(), 'tabs', 'transactions');
$admin_tabs = (string)ob_get_clean();
$tab_labels = static function (string $html): array {
    preg_match_all('/>(Transactions|Expense claims|Wave export|Transfers to Wave|Tools)<\/a>/', $html, $matches);
    return $matches[1];
};
expect($tab_labels($frontend_tabs) === ['Transactions', 'Expense claims', 'Wave export', 'Transfers to Wave'], 'The legacy shortcode Inbox uses exactly the four remaining tabs.');
expect($tab_labels($admin_tabs) === ['Transactions', 'Expense claims', 'Wave export', 'Transfers to Wave'], 'Tools is absent from the WordPress admin Inbox tab bar.');
$GLOBALS['wp_admin'] = false;
$db->summary_rows = [
    ['source_system' => 'configured_feed_alpha', 'n' => 2, 'oldest' => '2026-01-03', 'newest' => '2026-02-08'],
    ['source_system' => 'interac_email', 'n' => 1, 'oldest' => '2026-01-07', 'newest' => '2026-01-07'],
    ['source_system' => 'ofx_unconfigured', 'n' => 1, 'oldest' => '2026-01-09', 'newest' => '2026-01-09'],
];
SAT_Settings::$configured_banks = [
    'configured_feed_alpha' => ['label' => 'Synthetic account name', 'type' => 'bank', 'wave' => '', 'acctid' => 'ACCOUNT-1'],
];
ob_start();
SAT_Tools::instance()->render_summary();
$summary = (string)ob_get_clean();
expect(str_contains($summary, '<th>Oldest transaction</th><th>Latest transaction</th><th>Next OFX from</th>'), 'Summary date columns use the clarified labels and include Next OFX from.');
expect(str_contains($summary, '<td>Synthetic account name</td><td>2</td><td>2026-01-03</td><td>2026-02-08</td><td>2026-02-08</td>'), 'Configured OFX source displays MIN, MAX and the same latest date as its next OFX start.');
expect(str_contains($summary, '<td>Interac e-Transfers</td><td>1</td><td>2026-01-07</td><td>2026-01-07</td><td>—</td>'), 'Non-OFX evidence source displays an em dash for Next OFX from.');
expect(str_contains($summary, '<td>ofx_unconfigured</td><td>1</td><td>2026-01-09</td><td>2026-01-09</td><td>—</td>'), 'OFX-looking source text alone does not classify a source as configured for OFX.');
expect(str_contains($summary, '<strong>OFX catch-up:</strong> Start the next OFX download on the “Next OFX from” date, inclusive.') && str_contains($summary, 'Previously imported transactions are refreshed rather than duplicated.'), 'Summary explains inclusive OFX catch-up and refresh behavior.');
$tools_source = file_get_contents(SAT_DIR . '/includes/class-sat-tools.php');
expect(is_string($tools_source) && str_contains($tools_source, "\$source . ':' . \$st['account_id'] . ':' . \$t['fitid']"), 'OFX source keys remain source, account ID and FITID.');
expect(is_string($tools_source) && str_contains($tools_source, 'ON DUPLICATE KEY UPDATE') && str_contains($tools_source, 'transaction_date = VALUES(transaction_date)'), 'OFX imports continue to upsert and refresh previously imported transactions.');
$db->summary_rows = [];
SAT_Settings::$configured_banks = ['ofx_visa' => ['label' => 'Group Visa', 'type' => 'credit_card', 'wave' => 'Group Visa', 'acctid' => 'V']];
$shortcode = $GLOBALS['wp_shortcodes']['sat_inbox'] ?? null;
expect(is_array($shortcode) && $shortcode[0] === SAT_Inbox::instance() && $shortcode[1] === 'render', 'The legacy [sat_inbox] shortcode remains registered to the shared renderer.');
$_GET['sat_view'] = 'claims';
ob_start();
SAT_Inbox::instance()->admin_page();
$admin_page = (string)ob_get_clean();
expect(str_contains($admin_page, '<div class="sat-inbox">') && str_contains($admin_page, '<table class="claims"'), 'The admin Inbox renders through the existing Inbox application.');
$GLOBALS['wp_user']['caps'][SAT_CAP_REVIEW] = false;
ob_start();
SAT_Inbox::instance()->admin_page();
$unauthorized_page = (string)ob_get_clean();
expect(str_contains($unauthorized_page, 'You do not have permission to review transactions.'), 'Users without the SAT review capability cannot use the admin Inbox.');
$settings_page = SAT_Admin::instance();
ob_start();
$settings_page->page();
$unauthorized_settings = (string)ob_get_clean();
expect($unauthorized_settings === '', 'Users without the existing Settings capability cannot access Settings.');
$GLOBALS['wp_user']['caps'][SAT_CAP_REVIEW] = true;
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=sat-inbox&sat_view=claims';
$GLOBALS['wp_assets'] = [];
$GLOBALS['wp_localized'] = [];
SAT_Inbox::instance()->admin_assets('toplevel_page_sat-inbox');
$claim_assets = array_column($GLOBALS['wp_assets'], 1);
expect(in_array('sat-inbox', $claim_assets, true) && in_array('sat-claim-corrections', $claim_assets, true), 'Inbox and claim-correction assets load on the admin claims view.');
expect(str_contains($GLOBALS['wp_localized']['satInbox']['loginUrl'] ?? '', 'wp-login.php?redirect_to='), 'Inbox AJAX receives a standard WordPress login URL.');
expect(str_contains(rawurldecode($GLOBALS['wp_localized']['satInbox']['loginUrl'] ?? ''), 'admin.php?page=sat-inbox&sat_view=claims'), 'The WordPress login URL preserves the current Inbox view.');
expect(str_contains($GLOBALS['wp_localized']['satClaimCorrections']['loginUrl'] ?? '', 'wp-login.php?redirect_to='), 'Claim corrections receive the same WordPress login flow.');
$GLOBALS['wp_assets'] = [];
SAT_Inbox::instance()->assets();
expect(in_array('sat-inbox', array_column($GLOBALS['wp_assets'], 1), true) && in_array('sat-claim-corrections', array_column($GLOBALS['wp_assets'], 1), true), 'Existing shortcode frontend assets remain enqueued for authorized users.');
$GLOBALS['wp_assets'] = [];
$_GET['sat_view'] = 'transactions';
SAT_Inbox::instance()->admin_assets('toplevel_page_sat-inbox');
expect(!in_array('sat-claim-corrections', array_column($GLOBALS['wp_assets'], 1), true), 'Claim-correction assets are not loaded outside the claims view.');
$GLOBALS['wp_assets'] = [];
SAT_Inbox::instance()->admin_assets('scout-accounting_page_sat-tools');
expect(array_column($GLOBALS['wp_assets'], 1) === ['sat-inbox'], 'The Tools child loads only its required Inbox stylesheet.');
$GLOBALS['wp_assets'] = [];
SAT_Inbox::instance()->admin_assets('dashboard');
expect($GLOBALS['wp_assets'] === [], 'Inbox assets are not loaded on unrelated admin pages.');
$GLOBALS['wp_user']['caps'][SAT_CAP_REVIEW] = true;
$db->missing = true;
SAT_Claim_Corrections::forget();
ob_start();
call_private(SAT_Inbox::instance(), 'renderClaims');
$page_missing = (string)ob_get_clean();
expect(str_contains($page_missing, '003-claim-corrections.sql') && !str_contains($page_missing, 'id="sat-correction-modal"') && str_contains($page_missing, 'disabled>Edit'), 'Without the tables the page explains the migration and disables Edit.');
$db->missing = false;
SAT_Claim_Corrections::forget();

// Every hook the script uses is rendered by PHP, and the browser harness uses the real dialog markup.
ob_start();
SAT_Claim_Corrections::render_modal();
$modal_html = (string)ob_get_clean();
$script = (string)file_get_contents(SAT_DIR . '/assets/claim-corrections.js');
preg_match_all('/data-correction-[a-z-]+/', $script, $hooks);
foreach (array_unique($hooks[0]) as $hook) {
    expect(str_contains($modal_html, $hook), 'The dialog renders ' . $hook);
}
preg_match_all('/data-claim-[a-z-]+/', $script, $claim_hooks);
foreach (array_unique($claim_hooks[0]) as $hook) {
    expect(str_contains($page, $hook), 'The page renders ' . $hook);
}
$harness = (string)file_get_contents(__DIR__ . '/test_claim_corrections.html');
expect(preg_match('/<!-- BEGIN render_modal -->\n(.*)\n<!-- END render_modal -->/s', $harness, $m) === 1 && $m[1] === $modal_html, 'The browser harness embeds the current render_modal() markup.');
foreach (['correction_id', 'payload', 'source_key'] as $absent) {
    expect(!str_contains($modal_html, $absent), 'Dialog template exposes no ' . $absent);
}

/* ------------------------------------------------------------------ */
/* Cleanup and reset                                                   */
/* ------------------------------------------------------------------ */

$db->beginTransaction();
$removed = SAT_Claim_Corrections::remove_for_observations($db, [$C, 301], 7);
$db->commit();
expect($removed === 2 && !active($db, $C), 'Cleanup removes the active corrections of deleted claims.');
same(['observation_deleted', 'observation_deleted'], array_slice(array_column(array_filter($db->t['events'], static fn ($e) => (int)$e['observation_id'] === $C), 'event_type'), -2), 'Each removal is recorded.');
expect(count(array_filter($db->t['events'], static fn ($e) => (int)$e['observation_id'] === $C)) > 2, 'Earlier correction history is kept.');
$rejected = false;
try {
    SAT_Claim_Corrections::remove_for_observations($db, [$A], 7);
} catch (LogicException $e) {
    $rejected = true;
}
expect($rejected && active($db, $A), 'Removal outside a transaction is refused.');
$rejected = false;
try {
    SAT_Claim_Corrections::reconcile($db, $A, [], 0, 0);
} catch (LogicException $e) {
    $rejected = true;
}
expect($rejected, 'Reconciliation outside the import transaction is refused.');

/* ------------------------------------------------------------------ */
/* Schema                                                              */
/* ------------------------------------------------------------------ */

$install = (string)file_get_contents(dirname(__DIR__) . '/database/migrations/001-install.sql');
$delta = (string)file_get_contents(dirname(__DIR__) . '/database/migrations/003-claim-corrections.sql');
$table = static function (string $sql, string $name): string {
    return preg_match('/CREATE TABLE IF NOT EXISTS ' . $name . ' \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $m) === 1 ? $m[1] : '';
};
foreach (['sat_observation_corrections', 'sat_observation_correction_events'] as $name) {
    expect($table($install, $name) !== '', 'Fresh install creates ' . $name);
    same($table($install, $name), $table($delta, $name), 'The 003 delta creates the same ' . $name . ' as a fresh install.');
}
expect(str_contains($table($install, 'sat_observation_corrections'), 'UNIQUE KEY uq_sat_correction_field (observation_id, field_name)'), 'One active correction per claim and field.');
expect(str_contains($table($install, 'sat_observation_corrections'), "CHECK (field_name IN ('transaction_date', 'payee', 'description', 'amount'))"), 'The database restricts field names too.');
expect(str_contains($table($install, 'sat_observation_correction_events'), 'KEY idx_sat_correction_event_observation (observation_id, event_id)'), 'Events are indexed by claim.');
$statements = array_filter(array_map('trim', explode(';', (string)preg_replace('/^--.*$/m', '', $delta))));
same(2, count($statements), 'The delta has exactly two statements.');
foreach ($statements as $statement) {
    expect(str_starts_with($statement, 'CREATE TABLE IF NOT EXISTS sat_observation_correction'), 'The delta only creates the correction tables (idempotent, no data changes).');
}
foreach (['INSERT', 'UPDATE ', 'DELETE', 'ALTER', 'DROP', 'payload_json', 'inbox_observations'] as $forbidden) {
    expect(!str_contains(preg_replace('/^--.*$/m', '', $delta), $forbidden), 'The delta does not touch claim data: ' . $forbidden);
}
$db_class = (string)file_get_contents(SAT_DIR . '/includes/class-sat-db.php');
expect(str_contains($db_class, "'sat_observation_corrections'") && str_contains($db_class, "'sat_observation_correction_events'"), 'Missing correction tables raise the existing admin notice.');

echo 'SAT claim correction tests: OK (' . $GLOBALS['checks'] . " assertions)\n";
