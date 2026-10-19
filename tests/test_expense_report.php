<?php
/**
 * Expense Claims sorting/filtering, the CG Funds monthly report (CSV + receipt ZIP) and the
 * conditional receipt-count rule. Runs the real SAT_Expense_Form and SAT_Expense_Report classes
 * against WordPress/Gravity Forms stubs, a fake PDO and synthetic receipt files in a private
 * temporary directory. Never loads WordPress, touches a database or calls Wave.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('SAT_CAP_REVIEW', 'sat_review');
define('SAT_DIR', dirname(__DIR__) . '/wordpress/scout-accounting-toolkit');
define('SAT_URL', 'https://example.test/wp-content/plugins/scout-accounting-toolkit/');
define('SAT_VERSION', 'test');

/* ------------------------------------------------------------------ */
/* WordPress, Gravity Forms and SAT stubs                              */
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
$GLOBALS['hooks'] = [];
$GLOBALS['scripts'] = [];
$GLOBALS['redirects'] = [];
$GLOBALS['temp_dir'] = '';
$GLOBALS['post'] = [];

function add_action(string $hook, $callback, int $priority = 10, int $args = 1): void { $GLOBALS['hooks'][] = $hook; }
function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): void { $GLOBALS['hooks'][] = $hook; }
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
function esc_url_raw($url) { return (string)$url; }
function esc_url($url) { return htmlspecialchars((string)$url, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string)$text, ENT_QUOTES); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($text) { return trim((string)$text); }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_get_referer() { return 'https://example.test/inbox/?sat_view=claims'; }
function remove_query_arg($keys, $url = '') { return 'https://example.test/inbox/?sat_view=claims'; }
function selected($a, $b, bool $echo = true): string { return (string)$a === (string)$b ? ' selected="selected"' : ''; }
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
function get_temp_dir(): string { return $GLOBALS['temp_dir'] . '/'; }
function nocache_headers(): void {}
function get_date_from_gmt(string $gmt, string $format): string
{
    return (new DateTimeImmutable($gmt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Toronto'))->format($format);
}
function rgar($array, $key, $default = null) { return is_array($array) && isset($array[$key]) ? $array[$key] : $default; }
function rgpost(string $name) { return $_POST[$name] ?? ''; }
function wp_enqueue_script(string $handle, string $src = '', array $deps = [], $ver = false, $footer = false): void { $GLOBALS['scripts'][$handle] = $src; }
function wp_localize_script(string $handle, string $name, array $data): void { $GLOBALS['scripts'][$name] = $data; }

final class SAT_Settings
{
    public static array $form = ['form_id' => 9, 'name' => '1', 'email' => '3', 'amount' => '9', 'date' => '8', 'category' => '5', 'payee' => '4', 'description' => '21', 'payment' => '6', 'receipt' => '7', 'external_id' => '17'];
    public static array $inbox = ['section_map' => ['Cubs' => 'Program Income - Cubs', 'Sea Venturers' => 'Program Income - Venturers', 'Scouts' => 'Program Income - Scouts']];

    public static function get(string $section): array
    {
        return match ($section) {
            'form' => self::$form,
            'inbox' => self::$inbox,
            default => [],
        };
    }
    public static function canonical_category(string $category): string
    {
        return preg_replace('/^Venturers - /', 'Sea Venturers - ', $category);
    }
}

final class GFAPI
{
    public static array $entries = [];
    public static int $calls = 0;
    public static function get_entry($id)
    {
        self::$calls++;
        return self::$entries[(int)$id] ?? new WP_Error('not_found', 'Entry not found');
    }
}

final class GFFormsModel
{
    public static array $uploaded_files = [];
    public static string $root = '';
    public static function get_upload_root(): string { return self::$root . '/'; }
    public static function get_upload_url_root(): string { return 'https://example.test/wp-content/uploads/gravity_forms/'; }
}

final class FakeStatement extends PDOStatement
{
    private array $rows = [];
    public function __construct(private FakePDO $db, private string $sql) {}
    public function execute($params = null): bool
    {
        $this->rows = $this->db->run($this->sql, array_values((array)$params));
        return true;
    }
    public function fetchAll($mode = PDO::FETCH_DEFAULT, ...$args): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }
    public function fetchColumn($column = 0): mixed
    {
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }
}

final class FakePDO extends PDO
{
    public array $claims = [];
    public array $corrections = [];
    public array $links = [];
    public array $writes = [];
    public array $queries = [];
    private bool $transaction = false;
    public function __construct() {}
    public function prepare($query, $options = []): PDOStatement|false { return new FakeStatement($this, $query); }
    public function query($query, $fetchMode = null, ...$args): PDOStatement|false
    {
        $statement = new FakeStatement($this, $query);
        $statement->execute();
        return $statement;
    }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function run(string $sql, array $params): array
    {
        $this->queries[] = $sql;
        $flat = preg_replace('/\s+/', ' ', $sql);
        if (str_contains($flat, "FROM inbox_observations o WHERE o.source_system = 'gravity_forms'")) {
            return $this->claims;
        }
        if (str_contains($flat, 'FROM sat_observation_corrections WHERE observation_id IN')) {
            return array_values(array_filter($this->corrections, static fn (array $c): bool => in_array((int)$c['observation_id'], array_map('intval', $params), true)));
        }
        if (str_contains($flat, 'FROM sat_observation_corrections ORDER BY')) {
            return $this->corrections;
        }
        if (str_contains($flat, 'FROM inbox_matches m JOIN inbox_observations c ON')) {
            return $this->links;
        }
        if (str_starts_with(trim($flat), 'INSERT INTO inbox_observations')) {
            $this->writes[] = $params;
            return [];
        }
        if (str_contains($flat, 'SELECT observation_id FROM inbox_observations')) {
            return [['observation_id' => 555]];
        }
        if (str_starts_with(trim($flat), 'INSERT INTO inbox_allocations')) {
            return [];
        }
        throw new RuntimeException('Unexpected SQL in test: ' . $flat);
    }
}

final class SAT_DB
{
    public static ?PDO $pdo = null;
    public static function pdo(): PDO { return self::$pdo; }
}

require_once SAT_DIR . '/includes/class-sat-ofx-writer.php';
require_once SAT_DIR . '/includes/class-sat-transfer-ofx.php';
require_once SAT_DIR . '/includes/class-sat-expense-form.php';
require_once SAT_DIR . '/includes/class-sat-claim-corrections.php';
require_once SAT_DIR . '/includes/class-sat-expense-report.php';

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

function ids(array $claims): array
{
    return array_map(static fn (array $c): int => $c['entry_id'], $claims);
}

function remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $item) {
        if ($item !== '.' && $item !== '..') {
            remove_tree($path . '/' . $item);
        }
    }
    rmdir($path);
}

$work = sys_get_temp_dir() . '/sat-expense-report-test-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$GLOBALS['temp_dir'] = $work . '/tmp';
mkdir($GLOBALS['temp_dir'], 0700);
$uploads = $work . '/uploads/gravity_forms';
$form_dir = $uploads . '/9-0123456789abcdef/2026/09';
mkdir($form_dir, 0700, true);
mkdir($work . '/outside', 0700);
GFFormsModel::$root = $uploads;
register_shutdown_function(static fn () => remove_tree($work));

$PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$JPEG = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

function receipt(string $name, string $bytes): string
{
    global $form_dir;
    file_put_contents($form_dir . '/' . $name, $bytes);
    return 'https://example.test/wp-content/uploads/gravity_forms/9-0123456789abcdef/2026/09/' . rawurlencode($name);
}

function receipt_url_only(string $name): string
{
    return 'https://example.test/wp-content/uploads/gravity_forms/9-0123456789abcdef/2026/09/' . rawurlencode($name);
}

/** One synthetic claim observation as returned by the claims query. */
function claim_row(int $observation, int $entry, string $date, string $amount, string $method, array $extra = []): array
{
    $payload = array_merge([
        'format' => 'gravity_forms',
        'form_id' => 9,
        'entry_id' => $entry,
        'submitter_name' => 'Alex Example',
        'submitter_email' => 'alex@example.test',
        'payee' => 'Vendor ' . $entry,
        'description' => 'Synthetic purchase ' . $entry,
        'payment_method' => $method,
        'submitted_category' => 'Group activities and events',
        'export_account' => 'Group activities and events',
        'external_id' => 'REQ-' . $entry,
        'receipt_urls' => [],
    ], $extra['payload'] ?? []);
    return [
        'observation_id' => $observation,
        'source_system' => 'gravity_forms',
        'source_key' => 'gravity_forms:9:' . $entry,
        'transaction_date' => $date,
        'amount' => $amount,
        'direction' => 'expense',
        'description' => 'fallback text',
        'gf_entry_id' => $entry,
        'payload_json' => json_encode($payload),
        'is_matched' => $extra['matched'] ?? 0,
    ];
}

/* ------------------------------------------------------------------ */
/* Synthetic claims                                                    */
/* ------------------------------------------------------------------ */

$receipt_123 = receipt('coffee-receipt.pdf', $PDF);
$receipt_127 = receipt('cafe photo.JPEG', $JPEG);
$receipt_130a = receipt('reimburse-a.pdf', $PDF);
$receipt_130b = receipt('reimburse-b.pdf', $PDF);
$receipt_131 = receipt('october.png', $PNG);
$receipt_142 = receipt('uniform-invoice.pdf', $PDF);
$unrelated = receipt('unrelated-receipt.pdf', $PDF);

$rows = [
    claim_row(101, 123, '2026-09-03', '28.91', 'Charge on Group credit card', [
        'matched' => 1, // matched to the card account
        'payload' => ['payee' => 'Brick and Beam Coffee', 'submitted_category' => 'Cubs - Camp food', 'receipt_urls' => [$receipt_123], 'submitted_at_gmt' => '2026-09-04 02:30:00'],
    ]),
    claim_row(102, 127, '2026-09-11', '14.68', 'Invoice requiring payment', [
        'payload' => [
            'submitter_name' => 'Blair Sample',
            'payee' => "Café Été, \"Quoted\"\nLine",
            'description' => 'Snacks, juice and "extras"',
            'submitted_category' => 'Venturers - Program',
            'receipt_urls' => [$receipt_127],
        ],
    ]),
    claim_row(103, 130, '2026-09-30', '100.00', 'Reimbursement', [
        'payload' => ['submitter_name' => 'Alex Example', 'receipt_urls' => [$receipt_130a, $receipt_130b], 'submitted_at_gmt' => '2026-10-01 12:00:00'],
    ]),
    claim_row(104, 131, '2026-10-01', '5.00', 'Credit card', ['payload' => ['receipt_urls' => [$receipt_131], 'submitted_at_gmt' => '2026-10-02 12:00:00']]),
    claim_row(105, 132, '2026-08-31', '7.50', 'Invoice requiring payment', ['payload' => ['receipt_urls' => [$receipt_123], 'submitted_at_gmt' => '2026-09-01 12:00:00']]),
    claim_row(106, 140, '2026-12-31', '12.00', 'Credit card', ['payload' => ['submitter_name' => 'Casey Placeholder', 'submitted_at_gmt' => '2027-01-01 03:00:00']]),
    claim_row(107, 141, '2027-01-01', '13.00', 'Invoice requiring payment', ['payload' => ['submitter_name' => 'casey placeholder ', 'submitted_at_gmt' => '2027-01-01 18:00:00']]),
    claim_row(108, 142, '2026-09-15', '50.00', 'Invoice already paid', [
        'matched' => 1, // matched to the operating account
        'payload' => [
            'submitter_name' => 'Blair Sample',
            'payee' => '=HYPERLINK("https://example.invalid")',
            'description' => "Line one\nLine two",
            'submitted_category' => 'Uniform Items - all sections',
            'receipt_urls' => [$receipt_142],
            'submitted_at_gmt' => '2026-09-16 15:45:00',
        ],
    ]),
];
GFAPI::$entries[127] = ['id' => 127, 'date_created' => '2026-09-12 13:00:00'];

$pdo = new FakePDO();
$pdo->claims = $rows;
SAT_DB::$pdo = $pdo;
$report = SAT_Expense_Report::instance();
$claims = $report->claims();
expect(GFAPI::$calls === 1, 'Only the claim without a saved submission time queries Gravity Forms.');

/* ------------------------------------------------------------------ */
/* Payment classification and claim fields                             */
/* ------------------------------------------------------------------ */

foreach ([
    'Charge on 403TO credit card' => 'card',
    'Credit card' => 'card',
    'Invoice requiring payment' => 'invoice',
    'Invoice already paid' => 'invoice',
    'Reimbursement' => 'reimburse',
    'reimbursement of Scouter (please avoid this)' => 'reimburse',
    'Cheque' => 'other',
    '' => 'other',
] as $method => $kind) {
    expect(SAT_Expense_Form::payment_kind($method) === $kind, 'Payment kind for "' . $method . '".');
}
expect(SAT_Expense_Form::requires_single_receipt('Credit card') && SAT_Expense_Form::requires_single_receipt('Invoice requiring payment'), 'Card and invoice require one receipt.');
expect(!SAT_Expense_Form::requires_single_receipt('Reimbursement'), 'Reimbursement allows several receipts.');

$by_entry = [];
foreach ($claims as $c) {
    $by_entry[$c['entry_id']] = $c;
}
expect($by_entry[123]['submitted_at'] === '2026-09-03 22:30:00', 'Stored UTC submission time is shown in site time (crosses midnight).');
expect($by_entry[127]['submitted_at'] === '2026-09-12 09:00:00', 'Older claims read submission time from the Gravity Forms entry.');
expect($by_entry[140]['submitted_at'] === '2026-12-31 22:00:00', 'UTC year change converts back to the site-time year.');
expect($by_entry[123]['section'] === 'Cubs', 'Section comes from a configured section prefix.');
expect($by_entry[127]['section'] === 'Sea Venturers', 'Section matching follows category aliases.');
expect($by_entry[142]['section'] === '', 'Group-level categories containing " - " have no section.');
expect(SAT_Expense_Report::section('Scouts - Program', ['Scouts', 'Sea Scouts']) === 'Scouts', 'Exact section prefix.');
expect(SAT_Expense_Report::section('Sea Scouts - Program', ['Scouts', 'Sea Scouts']) === 'Sea Scouts', 'Longer section names win.');
expect(SAT_Expense_Report::section('Cubs Camp food', ['Cubs']) === '', 'A section name without a separator is not a section prefix.');
expect($by_entry[123]['amount_cents'] === 2891 && $by_entry[123]['matched'] === true, 'Amount and match status.');
$report->claims();
expect(GFAPI::$calls === 1, 'Gravity Forms submission lookups are cached per entry.');

/* ------------------------------------------------------------------ */
/* Month validation and filters                                        */
/* ------------------------------------------------------------------ */

$september = SAT_Expense_Report::month_range('2026-09');
expect($september === ['month' => '2026-09', 'start' => '2026-09-01', 'end' => '2026-10-01', 'label' => 'September 2026'], 'September range.');
$december = SAT_Expense_Report::month_range('2026-12');
expect($december['end'] === '2027-01-01' && $december['label'] === 'December 2026', 'December ends at the next year.');
foreach (['', '2026-13', '2026-00', '2026-9', '26-09', '2026-09-01', "2026-09\n", '2026/09', 'September 2026', '0000-01', '2026-09 OR 1=1'] as $bad) {
    expect(SAT_Expense_Report::month_range($bad) === null, 'Invalid month rejected: ' . json_encode($bad));
}

$defaults = SAT_Expense_Report::filters([], '2026-10');
expect($defaults === ['preset' => '', 'month' => '', 'claimant' => '', 'method' => '', 'match' => '', 'sort' => 'expense_date', 'order' => 'desc'], 'Default filters.');
$invalid = SAT_Expense_Report::filters(['claim_sort' => 'o.amount; DROP TABLE x', 'claim_order' => 'sideways', 'claim_month' => '2026-13', 'claim_method' => 'cash', 'claim_match' => 'maybe', 'claim_preset' => 'other', 'claim_claimant' => ['array']], '2026-10');
expect($invalid === $defaults, 'Invalid parameters fall back to the defaults.');
$preset = SAT_Expense_Report::filters(['claim_preset' => 'cgaming', 'claim_claimant' => 'Alex Example', 'claim_method' => 'card', 'claim_match' => 'matched'], '2027-01');
expect($preset['month'] === '2026-12' && $preset['claimant'] === '' && $preset['method'] === '' && $preset['match'] === '', 'The preset defaults to the previous month across a year change and ignores other filters.');
expect(SAT_Expense_Report::filters(['claim_preset' => 'cgaming', 'claim_month' => 'bad'], '2026-10')['month'] === '2026-09', 'Invalid preset month falls back to the previous month.');

/* ------------------------------------------------------------------ */
/* Sorting and filtering                                               */
/* ------------------------------------------------------------------ */

$all = SAT_Expense_Report::select($claims, $defaults);
expect(ids($all) === [141, 140, 131, 130, 142, 127, 123, 132], 'Default view keeps the previous newest-first order and shows every claim, including reimbursements.');
$sorts = [
    'expense_date' => [132, 123, 127, 142, 130, 131, 140, 141],
    'submitted' => [132, 123, 127, 142, 130, 131, 140, 141],
    'claimant' => [132, 123, 130, 131, 127, 142, 140, 141],
    'vendor' => [142, 123, 127, 130, 131, 132, 140, 141],
    'amount' => [131, 132, 140, 141, 127, 123, 142, 130],
];
foreach ($sorts as $sort => $ascending) {
    $asc = SAT_Expense_Report::select($claims, ['sort' => $sort, 'order' => 'asc'] + $defaults);
    $desc = SAT_Expense_Report::select($claims, ['sort' => $sort, 'order' => 'desc'] + $defaults);
    expect(ids($asc) === $ascending, 'Ascending sort by ' . $sort . ': ' . json_encode(ids($asc)));
    expect(ids($desc) === array_reverse($ascending), 'Descending sort by ' . $sort . ': ' . json_encode(ids($desc)));
}
expect(ids(SAT_Expense_Report::select($claims, ['sort' => 'nonsense'] + $defaults)) === ids($all), 'Unknown sort keys use the default.');

$sep = SAT_Expense_Report::select($claims, ['month' => '2026-09'] + $defaults);
expect(ids($sep) === [130, 142, 127, 123], 'September includes the 1st-30th and excludes Aug 31 and Oct 1.');
expect(ids(SAT_Expense_Report::select($claims, ['month' => '2026-12'] + $defaults)) === [140], 'December includes Dec 31 only.');
expect(ids(SAT_Expense_Report::select($claims, ['month' => '2027-01'] + $defaults)) === [141], 'January includes Jan 1 only.');
expect(ids(SAT_Expense_Report::select($claims, ['claimant' => 'CASEY  placeholder'] + $defaults)) === [141, 140], 'Claimant filter ignores case and spacing.');
expect(ids(SAT_Expense_Report::select($claims, ['month' => '2026-09', 'method' => 'invoice', 'match' => 'unmatched'] + $defaults)) === [127], 'Combined month, method and match filters.');
expect(ids(SAT_Expense_Report::select($claims, ['month' => '2026-09', 'method' => 'reimburse'] + $defaults)) === [130], 'Reimbursement claims stay available outside the preset.');
expect(ids(SAT_Expense_Report::select($claims, ['month' => '2026-09', 'claimant' => 'Blair Sample', 'match' => 'matched'] + $defaults)) === [142], 'Claimant plus match status.');
expect(ids(SAT_Expense_Report::select($claims, SAT_Expense_Report::filters([], '2026-10'))) === ids($all), 'Cleared filters show every claim again.');
expect(SAT_Expense_Report::claimants($claims) === ['Alex Example', 'Blair Sample', 'Casey Placeholder'], 'Distinct claimant options.');
expect(SAT_Expense_Report::months($claims, '2025-01') === ['2027-01', '2026-12', '2026-10', '2026-09', '2026-08', '2025-01'], 'Month options include the selection.');
$duplicated = SAT_Expense_Report::select(array_merge($claims, [$claims[0]]), $defaults);
expect(count($duplicated) === count($claims), 'A claim cannot appear twice.');

/* ------------------------------------------------------------------ */
/* CG Funds monthly report: one selection everywhere                   */
/* ------------------------------------------------------------------ */

$cg_filters = SAT_Expense_Report::filters(['claim_preset' => 'cgaming', 'claim_month' => '2026-09', 'claim_sort' => 'vendor', 'claim_order' => 'asc'], '2026-10');
$visible = SAT_Expense_Report::select($claims, $cg_filters);
$included = SAT_Expense_Report::report_claims($claims, '2026-09');
expect(ids($visible) === [142, 123, 127], 'Preset table excludes only the reimbursement claim (and follows the chosen sort).');
expect(ids($included) === [123, 127, 142], 'Report rows are in expense-date order.');
$sorted_ids = static function (array $list): array {
    sort($list);
    return $list;
};
expect($sorted_ids(ids($visible)) === $sorted_ids(ids($included)), 'Visible preset rows equal report membership.');
expect(in_array(127, ids($included), true) && !$by_entry[127]['matched'], 'Unmatched claims are included.');
expect($by_entry[123]['matched'] && $by_entry[142]['matched'] && in_array(123, ids($included), true) && in_array(142, ids($included), true), 'Claims matched to different payment accounts are included.');
expect(SAT_Expense_Report::summary($included) === ['claims' => 3, 'total_cents' => 9359, 'receipts' => 3], 'Count, total and receipt summary.');

$source = file_get_contents(SAT_DIR . '/includes/class-sat-expense-report.php');
foreach (['ofx_', 'inbox_allocations', 'inbox_reviews', 'banks(', 'source_system IN', 'transfer_ofx_'] as $lineage) {
    expect(!str_contains($source, $lineage), 'No account-lineage or transfer inference in the report: ' . $lineage);
}

/* ------------------------------------------------------------------ */
/* Receipt plan, filenames and CSV                                     */
/* ------------------------------------------------------------------ */

$storage = ['base_dir' => $uploads . '/', 'base_url' => 'https://example.test/wp-content/uploads/gravity_forms/'];
$plan = SAT_Expense_Report::receipt_plan($included, $storage);
expect(count($plan) === 3, 'Exactly one receipt per included claim.');
expect($plan[101]['zip_name'] === 'receipts/2026-09-03 - Brick and Beam Coffee - 28.91 - entry-123.pdf' && $plan[101]['mime'] === 'application/pdf', 'PDF receipt name and type.');
expect($plan[102]['zip_name'] === 'receipts/2026-09-11 - Cafe Ete, Quoted Line - 14.68 - entry-127.jpeg' && $plan[102]['mime'] === 'image/jpeg', 'Accents are transliterated, unsafe characters removed and the .jpeg extension preserved.');
expect($plan[108]['zip_name'] === 'receipts/2026-09-15 - HYPERLINK(https example.invalid) - 50.00 - entry-142.pdf', 'Slashes, colons and quotes never reach a ZIP entry name.');
expect(SAT_Expense_Report::receipt_plan($included, $storage) === $plan, 'Receipt names are deterministic.');
expect(SAT_Expense_Report::filename_text('../..\\a:b*c?d|e<f>g "h" ☃', 'x') === 'a b c d e f g h', 'Traversal and reserved characters are removed.');
expect(SAT_Expense_Report::filename_text('...', 'Unknown vendor') === 'Unknown vendor', 'Empty names use a fallback.');
expect(strlen(SAT_Expense_Report::filename_text(str_repeat('Long vendor ', 20), 'x')) <= 60, 'Vendor text is bounded.');

$twin_a = $by_entry[123];
$twin_b = array_merge($by_entry[123], ['observation_id' => 901, 'vendor' => 'BRICK AND BEAM COFFEE', 'receipt_urls' => [receipt('twin.pdf', $PDF)]]);
$collision = SAT_Expense_Report::receipt_plan([$twin_a, $twin_b], $storage);
expect($collision[901]['zip_name'] === 'receipts/2026-09-03 - BRICK AND BEAM COFFEE - 28.91 - entry-123 (2).pdf', 'Case-insensitive collisions get a deterministic suffix.');

$range = SAT_Expense_Report::month_range('2026-09');
expect(SAT_Expense_Report::csv_filename($range) === 'CGaming expenses - September 2026.csv', 'CSV filename.');
expect(SAT_Expense_Report::zip_filename($range) === 'CGaming expenses - September 2026.zip', 'ZIP filename.');
$csv = SAT_Expense_Report::csv($included, $plan);
expect(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV starts with a UTF-8 BOM for spreadsheets.');
$body = substr($csv, 3);
$header = 'Gravity Forms Entry ID,Expense Date,Submission Date,Claimant,Vendor,Description,Expense Category,Amount,Section or Program,Payment Method,Match Status,Receipt Filename,Receipt MIME Type';
expect(explode("\r\n", $body)[0] === $header, 'Exact approved columns in order.');
$expected_rows = [
    '123,2026-09-03,2026-09-03,Alex Example,Brick and Beam Coffee,Synthetic purchase 123,Cubs - Camp food,28.91,Cubs,Charge on Group credit card,Matched,receipts/2026-09-03 - Brick and Beam Coffee - 28.91 - entry-123.pdf,application/pdf',
    "127,2026-09-11,2026-09-12,Blair Sample,\"Café Été, \"\"Quoted\"\"\nLine\",\"Snacks, juice and \"\"extras\"\"\",Venturers - Program,14.68,Sea Venturers,Invoice requiring payment,Unmatched,\"receipts/2026-09-11 - Cafe Ete, Quoted Line - 14.68 - entry-127.jpeg\",image/jpeg",
    "142,2026-09-15,2026-09-16,Blair Sample,\"'=HYPERLINK(\"\"https://example.invalid\"\")\",\"Line one\nLine two\",Uniform Items - all sections,50.00,,Invoice already paid,Matched,receipts/2026-09-15 - HYPERLINK(https example.invalid) - 50.00 - entry-142.pdf,application/pdf",
];
expect($body === $header . "\r\n" . implode("\r\n", $expected_rows) . "\r\n", "Exact CSV body:\n" . $body);
$parsed = [];
$stream = fopen('php://memory', 'w+');
fwrite($stream, $body);
rewind($stream);
while (($line = fgetcsv($stream, 0, ',', '"', '')) !== false) {
    $parsed[] = $line;
}
expect(count($parsed) === 4 && count(array_unique(array_map('count', $parsed))) === 1, 'CSV parses into one row per claim with 13 fields.');
expect($parsed[2][4] === "Café Été, \"Quoted\"\nLine", 'Commas, quotes, line breaks and non-ASCII survive a CSV round trip.');
foreach (['alex@example.test', 'REQ-', 'gravity_forms', 'https://example.test', $uploads, 'Reimburse', '101', 'fallback text', 'coffee-receipt.pdf', 'submitted_at', 'CG Funds', 'Group activities'] as $absent) {
    expect(!str_contains($csv, $absent), 'Excluded data absent from CSV: ' . $absent);
}
expect(!str_contains($body, 'Tax'), 'No Tax column without a stored tax field.');

/* ------------------------------------------------------------------ */
/* ZIP download                                                        */
/* ------------------------------------------------------------------ */

function temp_leftovers(): array
{
    return glob($GLOBALS['temp_dir'] . '/sat-cgaming-*') ?: [];
}

function download(string $month): string
{
    ob_start();
    try {
        SAT_Expense_Report::instance()->deliver($month);
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    return (string)ob_get_clean();
}

function refused(array $claim_rows, string $needle, string $label, string $month = '2026-09'): void
{
    global $pdo, $uploads;
    $pdo->claims = $claim_rows;
    $GLOBALS['redirects'] = [];
    try {
        download($month);
        throw new RuntimeException('FAILED: expected refusal for ' . $label);
    } catch (RedirectSignal $signal) {
        $message = rawurldecode((string)preg_replace('/^.*sat_message=/', '', $signal->getMessage()));
        expect(str_contains($message, $needle), $label . ' message: ' . $message);
        expect(!str_contains($message, $uploads) && !str_contains($message, '/tmp') && !str_contains($message, 'https://'), $label . ' message exposes no paths.');
        expect(str_contains($signal->getMessage(), 'sat_notice=error') && str_contains($signal->getMessage(), 'claim_preset=cgaming'), $label . ' returns to the report.');
    }
    expect(temp_leftovers() === [], $label . ' leaves no temporary file.');
}

$pdo->claims = $rows;
$zip_bytes = download('2026-09');
expect(temp_leftovers() === [], 'Temporary ZIP is removed after a successful download.');
$zip_path = $work . '/download.zip';
file_put_contents($zip_path, $zip_bytes);
$zip = new ZipArchive();
expect($zip->open($zip_path) === true, 'Downloaded bytes are a ZIP.');
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = $zip->getNameIndex($i);
}
expect($names === [
    'CGaming expenses - September 2026.csv',
    'receipts/',
    'receipts/2026-09-03 - Brick and Beam Coffee - 28.91 - entry-123.pdf',
    'receipts/2026-09-11 - Cafe Ete, Quoted Line - 14.68 - entry-127.jpeg',
    'receipts/2026-09-15 - HYPERLINK(https example.invalid) - 50.00 - entry-142.pdf',
], 'ZIP contents: ' . json_encode($names));
expect($zip->getFromName('CGaming expenses - September 2026.csv') === $csv, 'ZIP CSV equals the report CSV.');
expect($zip->getFromName('receipts/2026-09-11 - Cafe Ete, Quoted Line - 14.68 - entry-127.jpeg') === $JPEG, 'Receipt bytes are copied unchanged.');
$csv_receipts = array_map(static fn (array $row): string => $row[11], array_slice($parsed, 1));
expect($csv_receipts === array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'receipts/') && $n !== 'receipts/')), 'CSV receipt filenames equal ZIP receipt membership.');
expect(!array_filter($names, static fn (string $n): bool => str_contains($n, 'unrelated') || str_contains($n, 'reimburse') || str_contains($n, 'entry-130') || str_contains($n, 'entry-131') || str_contains($n, 'entry-132')), 'Unrelated claims and receipts are absent.');
$zip->close();
expect(download('2026-09') === $zip_bytes, 'Repeated downloads are byte-identical.');

$empty = download('2026-11');
file_put_contents($zip_path, $empty);
$zip->open($zip_path);
expect($zip->numFiles === 1 && $zip->getFromName('CGaming expenses - November 2026.csv') === "\xEF\xBB\xBF" . $header . "\r\n", 'A month without claims produces a header-only CSV.');
$zip->close();

$base = $rows[0];
$with_receipts = static function (array $urls, int $entry = 123, int $observation = 101) use ($base): array {
    $row = $base;
    $payload = json_decode($row['payload_json'], true);
    $payload['receipt_urls'] = $urls;
    $payload['entry_id'] = $entry;
    $row['payload_json'] = json_encode($payload);
    $row['gf_entry_id'] = $entry;
    $row['observation_id'] = $observation;
    return $row;
};
refused([$with_receipts([])], 'expense entry #123 does not have exactly one readable receipt (0 attached)', 'Zero receipts');
refused([$with_receipts([$receipt_123, $receipt_142])], 'expense entry #123 does not have exactly one readable receipt (2 attached)', 'Two receipts');
refused([$with_receipts([str_replace('coffee-receipt', 'missing-file', $receipt_123)])], 'expense entry #123 does not have exactly one readable receipt.', 'Missing file');
$locked = receipt('locked.pdf', $PDF);
chmod($form_dir . '/locked.pdf', 0000);
if (!is_readable($form_dir . '/locked.pdf')) {
    refused([$with_receipts([$locked])], 'expense entry #123 does not have exactly one readable receipt.', 'Unreadable file');
}
chmod($form_dir . '/locked.pdf', 0600);
refused([$with_receipts(['https://example.test/wp-content/uploads/gravity_forms/9-0123456789abcdef/2026/09/../../../../outside/x.pdf'])], 'expense entry #123 has a receipt outside the approved upload storage', 'Traversal');
refused([$with_receipts(['https://example.test/wp-content/uploads/gravity_forms/%2e%2e/%2e%2e/outside/x.pdf'])], 'outside the approved upload storage', 'Encoded traversal');
refused([$with_receipts(['https://elsewhere.example/wp-content/uploads/gravity_forms/9-0123456789abcdef/2026/09/coffee-receipt.pdf'])], 'outside the approved upload storage', 'Remote URL');
refused([$with_receipts(['https://example.test/index.php?gf-download=2026/09/coffee-receipt.pdf'])], 'outside the approved upload storage', 'Download-script URL');
refused([$with_receipts(['https://example.test/wp-content/uploads/private/coffee-receipt.pdf'])], 'outside the approved upload storage', 'Other upload folder');
file_put_contents($work . '/outside/secret.pdf', $PDF);
symlink($work . '/outside/secret.pdf', $form_dir . '/linked.pdf');
refused([$with_receipts([receipt_url_only('linked.pdf')])], 'outside the approved upload storage', 'Symlink out of storage');
refused([$with_receipts([$receipt_123]), $with_receipts([$receipt_123], 142, 108)], 'expense entries #123 and #142 use the same receipt file', 'Shared receipt');
refused([$with_receipts([receipt('mislabelled.jpg', $PDF)])], 'expense entry #123 has a receipt that is not a PDF, JPEG or PNG', 'Content/extension mismatch');
refused([$with_receipts([receipt('notes.txt', 'plain text')])], 'expense entry #123 has a receipt that is not a PDF, JPEG or PNG', 'Unsupported type');
refused([$with_receipts([$receipt_123], 0)], 'an included expense claim has no Gravity Forms entry ID', 'Missing entry ID');
refused($rows, 'choose a valid month', 'Invalid month', '2026-9');
foreach ([['base_dir' => '', 'base_url' => $storage['base_url']], ['base_dir' => $uploads, 'base_url' => ''], ['base_dir' => $work . '/missing', 'base_url' => $storage['base_url']]] as $broken) {
    try {
        SAT_Expense_Report::receipt_plan($included, $broken);
        throw new RuntimeException('FAILED: unavailable storage must refuse the report.');
    } catch (SAT_Expense_Report_Error $e) {
        expect(str_contains($e->getMessage(), 'the receipt upload storage is not available'), 'Unavailable or empty storage settings refuse the report.');
    }
}
$pdo->claims = $rows;

// Failure while writing the ZIP (the receipt disappears after validation) still cleans up.
$vanishing = receipt('vanishing.pdf', $PDF);
$plan_v = SAT_Expense_Report::receipt_plan([SAT_Expense_Report::claim($with_receipts([$vanishing]))], $storage);
unlink($form_dir . '/vanishing.pdf');
$partial = $work . '/partial.zip';
set_error_handler(static fn (): bool => true, E_WARNING);
try {
    SAT_Expense_Report::write_zip($partial, 'CGaming expenses - September 2026.csv', 'x', $plan_v);
    throw new RuntimeException('FAILED: write_zip should refuse a vanished receipt.');
} catch (SAT_Expense_Report_Error $e) {
    expect(str_contains($e->getMessage(), 'ZIP'), 'Vanished receipt refuses the ZIP.');
} finally {
    restore_error_handler();
}
@unlink($partial);
expect(str_contains($source, 'SAT_Transfer_OFX::register_temporary_cleanup($temporary);') && str_contains($source, "tempnam(get_temp_dir(), 'sat-cgaming-')") && str_contains($source, 'chmod($temporary, 0600)'), 'Report ZIPs use the private temporary file and shutdown cleanup pattern (covers aborted downloads).');
expect(!str_contains($source, 'wp_upload_dir()[') && !str_contains($source, 'wp_remote_') && !str_contains($source, 'file_get_contents($url') && !str_contains($source, 'error_log('), 'No public ZIP storage, remote fetches or logging of claim data.');

/* ------------------------------------------------------------------ */
/* Capability and nonce                                                */
/* ------------------------------------------------------------------ */

$attempt = static function (array $user, array $post): string {
    $GLOBALS['wp_user'] = $user;
    $_POST = $post;
    ob_start();
    try {
        SAT_Expense_Report::instance()->action_download();
        return 'completed';
    } catch (WpDie) {
        return 'denied';
    } catch (NonceFailure) {
        return 'nonce';
    } catch (RedirectSignal) {
        return 'redirect';
    } finally {
        ob_end_clean();
    }
};
$good = ['_wpnonce' => 'nonce-sat_cgaming_report', 'claim_month' => '2026-09'];
expect($attempt(['logged_in' => false, 'caps' => []], $good) === 'denied', 'Logged-out users are refused.');
expect($attempt(['logged_in' => true, 'caps' => []], $good) === 'denied', 'Users without the review capability are refused.');
expect($attempt(['logged_in' => true, 'caps' => [SAT_CAP_REVIEW => true]], ['claim_month' => '2026-09']) === 'nonce', 'A missing nonce is refused.');
expect($attempt(['logged_in' => true, 'caps' => [SAT_CAP_REVIEW => true]], ['_wpnonce' => 'nonce-sat_transfer_ofx', 'claim_month' => '2026-09']) === 'nonce', 'Another workflow\'s nonce is refused.');
expect(temp_leftovers() === [], 'Refused requests create no temporary file.');
$GLOBALS['wp_user'] = ['logged_in' => true, 'caps' => [SAT_CAP_REVIEW => true]];
$_POST = [];
expect(in_array('admin_post_sat_cgaming_report', $GLOBALS['hooks'], true) && !in_array('admin_post_nopriv_sat_cgaming_report', $GLOBALS['hooks'], true), 'Download is registered for logged-in users only.');

/* ------------------------------------------------------------------ */
/* Rendering                                                           */
/* ------------------------------------------------------------------ */

$render = static function (array $f) use ($report, $claims): string {
    ob_start();
    $report->render_controls($f, $claims, SAT_Expense_Report::select($claims, $f));
    return (string)ob_get_clean();
};
$_GET = ['sat_view' => 'claims', 'claim_sort' => 'amount', 'page_id' => '7"><script>'];
$html = $render(SAT_Expense_Report::filters(['claim_sort' => 'amount', 'claim_claimant' => 'Blair Sample'], '2026-10'));
expect(str_contains($html, 'name="claim_claimant"') && str_contains($html, 'name="claim_method"') && str_contains($html, 'name="claim_match"'), 'Ordinary view shows claimant, method and match filters.');
expect(str_contains($html, '<option value="amount" selected="selected">Amount</option>') && str_contains($html, '<option value="Blair Sample" selected="selected">'), 'Selected sort and claimant are preserved.');
expect(str_contains($html, 'name="page_id" value="7&quot;&gt;&lt;script&gt;"') && !str_contains($html, '<script>'), 'Other query parameters are preserved and escaped.');
expect(!str_contains($html, 'name="claim_sort" value='), 'Owned parameters are not duplicated as hidden fields.');
expect(str_contains($html, 'Clear filters') && !str_contains($html, 'Download receipts ZIP'), 'Ordinary view offers Clear filters and no download.');

$_GET = ['sat_view' => 'claims', 'claim_preset' => 'cgaming', 'claim_month' => '2026-09'];
$html = $render(SAT_Expense_Report::filters($_GET, '2026-10'));
expect(str_contains($html, 'Includes all claims for the selected month except Reimburse a Scouter. Review the exported CSV for non-CGaming exceptions.'), 'Honest inclusion note.');
expect(str_contains($html, '<dd>September 2026</dd>') && str_contains($html, '<dt>Included claims</dt><dd>3</dd>') && str_contains($html, '<dd>$93.59</dd>') && str_contains($html, '<dt>Included receipts</dt><dd>3</dd>'), 'Preset summary.');
expect(str_contains($html, 'name="_wpnonce" value="nonce-sat_cgaming_report"') && str_contains($html, 'name="action" value="sat_cgaming_report"') && str_contains($html, 'name="claim_month" value="2026-09"') && str_contains($html, 'Download receipts ZIP'), 'Download form uses the dedicated nonce and month.');
expect(!str_contains($html, 'name="claim_claimant"') && !str_contains($html, 'name="claim_method"') && str_contains($html, '>Apply</button>'), 'Preset shows only month and sort controls.');
expect(!str_contains($html, 'All months') && !str_contains($html, 'Receipt check'), 'Preset requires a month; no warning when every claim has one receipt.');
$pdo->claims = [$with_receipts([]), $with_receipts([$receipt_123, $receipt_142], 142, 108)];
$bad_claims = $report->claims();
ob_start();
$report->render_controls(SAT_Expense_Report::filters($_GET, '2026-10'), $bad_claims, SAT_Expense_Report::select($bad_claims, SAT_Expense_Report::filters($_GET, '2026-10')));
$html = (string)ob_get_clean();
expect(str_contains($html, 'Receipt check: expense entries #123, #142 do not have exactly one receipt.'), 'Preset warns before the ZIP is refused.');
$pdo->claims = $rows;
$_GET = [];

/* ------------------------------------------------------------------ */
/* Gravity Forms receipt-count rule                                    */
/* ------------------------------------------------------------------ */

final class FakeUploadField
{
    public int $id = 7;
    public int $formId = 9;
    public function __construct(private int $existing, private int $new = 0) {}
    public function get_submission_files(): array
    {
        return ['existing' => array_fill(0, $this->existing, ['uploaded_filename' => 'r.pdf']), 'new' => array_fill(0, $this->new, ['name' => 'n.pdf'])];
    }
}

$GLOBALS['hooks'] = [];
$form_handler = SAT_Expense_Form::instance();
expect(in_array('gform_field_validation_9_7', $GLOBALS['hooks'], true), 'Validation is registered for the configured form and receipt field only.');
expect(in_array('gform_enqueue_scripts_9', $GLOBALS['hooks'], true), 'Client rule loads only when the configured form is rendered.');

$valid = ['is_valid' => true, 'message' => ''];
$check = static function (string $method, $field, $value = '') use ($form_handler, $valid): array {
    $_POST = ['input_6' => $method];
    return $form_handler->validate_receipt_count($valid, $value, ['id' => 9], $field);
};
expect($check('Invoice requiring payment', new FakeUploadField(1))['is_valid'] === true, 'Invoice with one receipt is accepted.');
$rejected = $check('Invoice requiring payment', new FakeUploadField(2));
expect($rejected === ['is_valid' => false, 'message' => SAT_Expense_Form::ONE_RECEIPT_MESSAGE], 'Invoice with two receipts is rejected on the receipt field.');
expect($check('Credit card', new FakeUploadField(1))['is_valid'] === true, 'Credit card with one receipt is accepted.');
expect($check('Credit card', new FakeUploadField(1, 1))['is_valid'] === false, 'Credit card with an uploaded plus a new receipt is rejected.');
expect($check('Charge on 403TO credit card', new FakeUploadField(2))['is_valid'] === false, 'Older card wording is still limited to one receipt.');
expect($check('Reimbursement', new FakeUploadField(1))['is_valid'] === true, 'Reimbursement with one receipt is accepted.');
expect($check('Reimbursement', new FakeUploadField(4))['is_valid'] === true, 'Reimbursement with several receipts is accepted.');
expect($check('Invoice requiring payment', new FakeUploadField(1), json_encode(['https://example.test/a.pdf']))['is_valid'] === false, 'Receipts saved on an existing entry count too.');
$required = ['is_valid' => false, 'message' => 'This field is required.'];
$_POST = ['input_6' => 'Invoice requiring payment'];
expect($form_handler->validate_receipt_count($required, '', ['id' => 9], new FakeUploadField(0)) === $required, 'Zero receipts stay with Gravity Forms required-field validation.');
expect($check('Invoice requiring payment', new FakeUploadField(0))['is_valid'] === true, 'The rule never fails a field for having no receipt.');
$legacy_field = new stdClass();
$legacy_field->id = 7;
$legacy_field->formId = 9;
GFFormsModel::$uploaded_files = [9 => ['input_7' => [['temp_filename' => 'a'], ['temp_filename' => 'b']]]];
expect($check('Credit card', $legacy_field)['is_valid'] === false, 'Older Gravity Forms upload state is counted.');
GFFormsModel::$uploaded_files = [];
expect($check('', new FakeUploadField(3))['is_valid'] === true, 'No payment method selected: required validation on that field decides.');
$_POST = [];

$GLOBALS['scripts'] = [];
$form_handler->enqueue_receipt_rule();
expect(($GLOBALS['scripts']['satExpenseForm'] ?? null) === ['formId' => 9, 'receiptFieldId' => 7, 'paymentFieldId' => 6, 'message' => SAT_Expense_Form::ONE_RECEIPT_MESSAGE], 'Client rule receives the configured IDs and the server message.');
expect(str_ends_with((string)$GLOBALS['scripts']['sat-expense-form'], 'assets/expense-form.js'), 'Client rule script handle.');

/* ------------------------------------------------------------------ */
/* Submission date captured on import                                  */
/* ------------------------------------------------------------------ */

$entry = ['id' => 77, 'date_created' => '2026-09-14 16:20:05', '1.3' => 'Alex', '3' => 'alex@example.test', '9' => '12.34', '8' => '2026-09-13', '5' => 'Cubs - Program', '4' => 'Vendor', '21' => 'Purpose', '6' => 'Credit card', '7' => json_encode(['https://example.test/wp-content/uploads/gravity_forms/9-x/2026/09/a.pdf']), '17' => 'REQ'];
expect($form_handler->import_entry($entry, ['id' => 9]) === 555, 'Import succeeds.');
$saved = json_decode($pdo->writes[0][5], true);
expect($saved['submitted_at_gmt'] === '2026-09-14 16:20:05', 'Import saves the Gravity Forms submission time.');
$entry['date_created'] = 'not a date';
$form_handler->import_entry($entry, ['id' => 9]);
expect(json_decode($pdo->writes[1][5], true)['submitted_at_gmt'] === '', 'An invalid submission time is not stored.');

/* ------------------------------------------------------------------ */
/* Claim corrections: effective values in the table, report and ZIP    */
/* ------------------------------------------------------------------ */

function correction_row(int $id, int $observation, string $field, string $value, string $source): array
{
    return [
        'correction_id' => $id, 'observation_id' => $observation, 'field_name' => $field, 'corrected_value' => $value,
        'source_value' => $source, 'observed_source_value' => $source, 'reason_code' => 'data_entry', 'source_state' => 'clear',
        'version' => 1, 'created_by' => 3, 'created_at' => '2026-10-02 09:00:00', 'updated_by' => 3, 'updated_at' => '2026-10-02 09:00:00',
    ];
}

$payloads_before = array_column($rows, 'payload_json', 'observation_id');
$pdo->claims = $rows;
$pdo->corrections = [
    correction_row(1, 101, 'payee', 'Brick & Beam Coffee Co', 'Brick and Beam Coffee'),
    correction_row(2, 101, 'description', 'Coffee for LEADERS, (camp) — urn rental', 'Synthetic purchase 123'),
    correction_row(3, 102, 'transaction_date', '2026-10-05', '2026-09-11'),
    correction_row(4, 108, 'amount', '10.00', '50.00'),
];
$pdo->links = [
    ['claim_id' => 101, 'bank_id' => 9001, 'bank_amount' => '-28.91'],
    ['claim_id' => 108, 'bank_id' => 9002, 'bank_amount' => '-50.00'],
];
SAT_Claim_Corrections::forget();
$corrected = [];
foreach ($report->claims() as $c) {
    $corrected[$c['entry_id']] = $c;
}
$corrected_claims = array_values($corrected);
expect($corrected[123]['vendor'] === 'Brick & Beam Coffee Co' && $corrected[123]['description'] === 'Coffee for LEADERS, (camp) — urn rental', 'Effective payee and description keep their capitalization and punctuation.');
expect($corrected[123]['corrections']['fields']['payee']['source'] === 'Brick and Beam Coffee', 'The imported payee stays available beside the correction.');
expect($corrected[123]['corrected'] && !$corrected[123]['correction_review'] && !$corrected[130]['corrected'], 'Corrected marker only where a correction is active.');
expect($corrected[127]['expense_date'] === '2026-10-05' && $corrected[142]['amount_cents'] === 1000, 'Effective date and amount.');
expect($corrected[142]['amount_mismatch'] && !$corrected[123]['amount_mismatch'], 'An amount correction on a matched claim is flagged against its bank transaction.');
expect(array_column($rows, 'payload_json', 'observation_id') === $payloads_before, 'Imported payloads are never modified.');

expect(ids(SAT_Expense_Report::select($corrected_claims, ['month' => '2026-09'] + $defaults)) === [130, 142, 123], 'A date correction moves a claim out of its imported month.');
expect(ids(SAT_Expense_Report::select($corrected_claims, ['month' => '2026-10'] + $defaults)) === [127, 131], 'and into its corrected month.');
expect(in_array('2026-10', SAT_Expense_Report::months($corrected_claims), true), 'Month options use effective dates.');
expect(ids(SAT_Expense_Report::select($corrected_claims, ['month' => '2026-09', 'sort' => 'amount', 'order' => 'desc'] + $defaults)) === [130, 123, 142], 'Amount sorting uses the corrected amount.');
expect(ids(SAT_Expense_Report::select($corrected_claims, ['sort' => 'vendor', 'order' => 'asc'] + $defaults))[0] === 142, 'Vendor sorting uses the corrected payee.');
$sorted_vendor = array_map(static fn (array $c): string => $c['vendor'], SAT_Expense_Report::select($corrected_claims, ['sort' => 'vendor', 'order' => 'asc'] + $defaults));
expect(array_search('Brick & Beam Coffee Co', $sorted_vendor, true) < array_search('Vendor 130', $sorted_vendor, true), 'Corrected payee sorts by its corrected text.');

$corrected_included = SAT_Expense_Report::report_claims($corrected_claims, '2026-09');
expect(ids($corrected_included) === [123, 142], 'The CG Funds month follows effective Expense Dates.');
expect(SAT_Expense_Report::summary($corrected_included) === ['claims' => 2, 'total_cents' => 3891, 'receipts' => 2], 'Count, total (amount counted once) and receipts follow corrections.');
expect(count(array_unique(ids(SAT_Expense_Report::select(array_merge($corrected_claims, $corrected_claims), $defaults)))) === count($corrected_claims), 'Corrections never duplicate rows.');
$corrected_plan = SAT_Expense_Report::receipt_plan($corrected_included, $storage);
expect($corrected_plan[101]['zip_name'] === 'receipts/2026-09-03 - Brick & Beam Coffee Co - 28.91 - entry-123.pdf', 'Receipt filenames use the effective payee.');
expect($corrected_plan[108]['zip_name'] === 'receipts/2026-09-15 - HYPERLINK(https example.invalid) - 10.00 - entry-142.pdf', 'Receipt filenames use the effective amount.');
$corrected_csv = substr(SAT_Expense_Report::csv($corrected_included, $corrected_plan), 3);
expect(str_contains($corrected_csv, "\r\n123,2026-09-03,2026-09-03,Alex Example,Brick & Beam Coffee Co,\"Coffee for LEADERS, (camp) — urn rental\",Cubs - Camp food,28.91,"), 'CSV uses the effective payee and description.');
expect(str_contains($corrected_csv, 'Uniform Items - all sections,10.00,') && !str_contains($corrected_csv, '127,'), 'CSV uses the effective amount and date.');
$corrected_zip = download('2026-09');
file_put_contents($zip_path, $corrected_zip);
$zip->open($zip_path);
expect($zip->getFromName('receipts/2026-09-03 - Brick & Beam Coffee Co - 28.91 - entry-123.pdf') === $PDF, 'The downloaded ZIP names receipts from effective values.');
expect($zip->getFromName('CGaming expenses - September 2026.csv') === "\xEF\xBB\xBF" . $corrected_csv, 'The downloaded CSV uses effective values.');
$zip->close();

// Resetting the amount returns totals and the inconsistency state to the imported values.
$pdo->corrections = array_slice($pdo->corrections, 0, 3);
SAT_Claim_Corrections::forget();
$reset = [];
foreach ($report->claims() as $c) {
    $reset[$c['entry_id']] = $c;
}
expect($reset[142]['amount_cents'] === 5000 && !$reset[142]['amount_mismatch'], 'Amount reset clears the mismatch.');
expect(SAT_Expense_Report::summary(SAT_Expense_Report::report_claims(array_values($reset), '2026-09'))['total_cents'] === 7891, 'Amount reset restores the imported total.');

// A group of claims matched to one transaction is compared as a group.
$group = [
    ['observation_id' => 1, 'amount_cents' => 8000, 'amount_mismatch' => false],
    ['observation_id' => 2, 'amount_cents' => 4000, 'amount_mismatch' => false],
];
$group_links = [['claim_id' => 1, 'bank_id' => 50, 'bank_amount' => '-120.00'], ['claim_id' => 2, 'bank_id' => 50, 'bank_amount' => '-120.00']];
expect(array_column(SAT_Expense_Report::flag_amount_mismatches($group, $group_links), 'amount_mismatch') === [false, false], 'Grouped claims that still add up are consistent.');
$group[1]['amount_cents'] = 4100;
expect(array_column(SAT_Expense_Report::flag_amount_mismatches($group, $group_links), 'amount_mismatch') === [true, true], 'A corrected amount inside a group flags the whole group.');
$pdo->corrections = [];
$pdo->links = [];
SAT_Claim_Corrections::forget();

echo 'Expense claims and CGaming report checks: OK (' . $GLOBALS['checks'] . " assertions)\n";
