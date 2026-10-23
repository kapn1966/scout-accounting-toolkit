<?php
/**
 * Read-only Income projection tests with synthetic observations and a fake PDO.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__);
define('SAT_CAP_REVIEW', 'sat_review');
define('SAT_DIR', dirname(__DIR__) . '/wordpress/scout-accounting-toolkit');

function add_action(string $hook, $callback): void {}
function add_shortcode(string $tag, $callback): void {}
function is_user_logged_in(): bool { return true; }
function current_user_can(string $cap): bool { return $cap === SAT_CAP_REVIEW; }
function is_admin(): bool { return (bool)($GLOBALS['is_admin'] ?? false); }
function wp_die($message = '', $title = '', $args = []): never { throw new RuntimeException((string)$message); }
function sanitize_key($value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$value)); }
function wp_unslash($value) { return $value; }
function esc_html($value): string { return htmlspecialchars((string)$value, ENT_QUOTES); }
function esc_attr($value): string { return htmlspecialchars((string)$value, ENT_QUOTES); }
function esc_url($value): string { return htmlspecialchars((string)$value, ENT_QUOTES); }
function selected($selected, $current, bool $echo = true): string
{
    return (string)$selected === (string)$current ? ' selected="selected"' : '';
}
function remove_query_arg($keys, $url = ''): string { return 'https://example.test/inbox/'; }
function add_query_arg($key, $value = null, $url = ''): string
{
    if (is_array($key)) {
        $url = is_string($value) ? $value : '';
        $args = $key;
    } else {
        $args = [(string)$key => $value];
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

final class SAT_Expense_Report
{
    public const PARAMS = ['claim_month', 'claim_sort'];
}

final class SAT_Settings
{
    public static function banks(): array
    {
        return [
            'ofx_bank_a' => ['label' => 'Operating account'],
            'ofx_bank_b' => ['label' => 'Savings account'],
        ];
    }
}

final class SAT_Transfer_OFX
{
    public static array $transfer_ids = [];
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function matched_transfer_observation_ids(): array
    {
        return self::$transfer_ids;
    }
}

final class SAT_Admin
{
    public static function heading(string $title): void
    {
        echo '<h1 class="sat-admin-heading">' . esc_html($title) . '</h1>';
    }
}

final class IncomeStatement extends PDOStatement
{
    public function __construct(private array $rows) {}

    public function fetchAll($mode = PDO::FETCH_DEFAULT, ...$args): array
    {
        return $this->rows;
    }
}

final class IncomePDO extends PDO
{
    public array $queries = [];

    public function __construct(private array $interac, private array $ofx) {}

    public function query($query, $fetchMode = null, ...$args): PDOStatement|false
    {
        $this->queries[] = (string)$query;
        if (str_contains((string)$query, "source_system = 'interac_email'")) {
            return new IncomeStatement($this->interac);
        }
        if (str_contains((string)$query, "source_system LIKE 'ofx")) {
            return new IncomeStatement($this->ofx);
        }
        throw new RuntimeException('Unexpected query in Income test.');
    }
}

final class SAT_DB
{
    public static ?PDO $connection = null;

    public static function pdo(): PDO
    {
        return self::$connection;
    }
}

require_once SAT_DIR . '/includes/class-sat-income-view.php';
require_once SAT_DIR . '/includes/class-sat-inbox.php';

$checks = 0;
function expect_income(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
}

function synthetic_interac(int $id, string $payer = 'Jane Smith', string $date = '2026-04-12', string $amount = '123.45', string $section = 'Cubs'): array
{
    return [
        'observation_id' => $id,
        'source_system' => 'interac_email',
        'transaction_date' => $date,
        'amount' => $amount,
        'direction' => 'income',
        'description' => 'Synthetic Interac description',
        'payments_reference_number' => 'SYN-REF-' . $id,
        'payload_json' => json_encode([
            'payer_name' => $payer,
            'payment_date' => $date,
            'amount' => $amount,
            'section' => $section,
            'payment_message' => 'Synthetic camp payment',
            'reference_number' => 'SYN-REF-' . $id,
        ], JSON_THROW_ON_ERROR),
    ];
}

function synthetic_ofx(int $id, string $payer = 'Jane Smith', string $date = '2026-04-12', string $amount = '123.45', string $source = 'ofx_bank_a'): array
{
    return [
        'observation_id' => $id,
        'source_system' => $source,
        'transaction_date' => $date,
        'amount' => $amount,
        'direction' => 'income',
        'description' => 'Synthetic OFX description',
        'payload_json' => json_encode([
            'name' => $payer,
            'memo' => 'Synthetic bank memo',
            'amount' => $amount,
            'account_name' => $source === 'ofx_bank_b' ? 'Savings account' : 'Operating account',
            'reference_number' => 'SYN-OFX-' . $id,
        ], JSON_THROW_ON_ERROR),
    ];
}

function evidence_counts(array $rows): array
{
    $counts = ['interac_ofx' => 0, 'interac' => 0, 'ofx' => 0];
    foreach ($rows as $row) {
        $counts[$row['evidence']]++;
    }
    return $counts;
}

$interac_only = SAT_Income_View::project([synthetic_interac(1)], []);
expect_income(count($interac_only) === 1 && $interac_only[0]['evidence'] === 'interac', 'One Interac observation displays as one standalone row.');

$ofx_only = SAT_Income_View::project([], [synthetic_ofx(2)]);
expect_income(count($ofx_only) === 1 && $ofx_only[0]['evidence'] === 'ofx', 'One incoming OFX observation displays as one standalone row.');

$paired = SAT_Income_View::project(
    [synthetic_interac(10, ' Jane   Smith ')],
    [synthetic_ofx(20, 'JANE SMITH')],
    [],
    ['ofx_bank_a' => ['label' => 'Operating account']]
);
expect_income(count($paired) === 1, 'Normalized payer/date/amount pair produces one logical row.');
expect_income($paired[0]['evidence'] === 'interac_ofx', 'Paired evidence type is distinct from canonical match status.');
expect_income($paired[0]['amount_cents'] === 12345 && SAT_Income_View::summary($paired)['total_cents'] === 12345, 'A paired payment contributes its amount exactly once.');
expect_income($paired[0]['account'] === 'Operating account' && $paired[0]['section'] === 'Cubs', 'Paired row takes the bank account from OFX and section from Interac.');
expect_income($paired[0]['reference'] === 'SYN-REF-10' && $paired[0]['description'] === 'Synthetic camp payment', 'Paired row keeps Interac reference and payment description.');

foreach ([
    [synthetic_interac(31, 'Jane Smith', '2026-04-12', '123.45'), synthetic_ofx(41, 'Jane Smith', '2026-04-13', '123.45'), 'different dates remain separate'],
    [synthetic_interac(32, 'Jane Smith', '2026-04-12', '123.45'), synthetic_ofx(42, 'Jane Smith', '2026-04-12', '123.46'), 'different amounts remain separate'],
    [synthetic_interac(33, 'Jane Smith', '2026-04-12', '123.45'), synthetic_ofx(43, 'J Smith', '2026-04-12', '123.45'), 'different payers remain separate without fuzzy matching'],
] as [$interac_row, $ofx_row, $message]) {
    expect_income(count(SAT_Income_View::project([$interac_row], [$ofx_row])) === 2, $message);
}

$duplicates = SAT_Income_View::project(
    [synthetic_interac(50), synthetic_interac(51)],
    [synthetic_ofx(60), synthetic_ofx(61)]
);
expect_income(count($duplicates) === 2 && evidence_counts($duplicates)['interac_ofx'] === 2, 'Duplicate same-key payments pair deterministically one-to-one.');

$interac_extra = SAT_Income_View::project(
    [synthetic_interac(70), synthetic_interac(71)],
    [synthetic_ofx(80)]
);
expect_income(evidence_counts($interac_extra) === ['interac_ofx' => 1, 'interac' => 1, 'ofx' => 0], 'An unmatched duplicate Interac observation remains visible.');

$ofx_extra = SAT_Income_View::project(
    [synthetic_interac(90)],
    [synthetic_ofx(100), synthetic_ofx(101)]
);
expect_income(evidence_counts($ofx_extra) === ['interac_ofx' => 1, 'interac' => 0, 'ofx' => 1], 'An unmatched duplicate OFX observation remains visible.');

$transfer_rows = SAT_Income_View::project(
    [],
    [synthetic_ofx(110), synthetic_ofx(111, 'Other Payer')],
    [110 => true]
);
expect_income(count($transfer_rows) === 1 && $transfer_rows[0]['_stable_id'] === 111, 'Accepted internal transfer leg is excluded while unresolved possible transfer remains visible.');

$filter_rows = SAT_Income_View::project(
    [
        synthetic_interac(120),
        synthetic_interac(121, 'Other Payer', '2026-05-12', '25.00'),
        synthetic_interac(122, 'Standalone Payer', '2026-04-14', '10.00', 'Scouts'),
    ],
    [
        synthetic_ofx(130),
        synthetic_ofx(131, 'Other Payer', '2026-05-12', '25.00', 'ofx_bank_b'),
        synthetic_ofx(132, 'OFX Standalone', '2026-04-15', '5.00'),
    ]
);
$filters = SAT_Income_View::filters([
    'income_month' => '2026-04',
    'income_account' => 'Operating account',
    'income_section' => 'Cubs',
    'income_evidence' => 'interac_ofx',
]);
$filtered = SAT_Income_View::select($filter_rows, $filters);
expect_income(count($filtered) === 1 && SAT_Income_View::summary($filtered) === ['transactions' => 1, 'total_cents' => 12345], 'Month, account, section and evidence filters operate on paired logical rows and totals.');
expect_income(count(SAT_Income_View::select($filter_rows, SAT_Income_View::filters(['income_month' => '2026-04']))) === 3, 'Month filter selects matching logical rows.');
expect_income(count(SAT_Income_View::select($filter_rows, SAT_Income_View::filters(['income_account' => 'Operating account']))) === 2, 'Account filter selects matching logical rows.');
expect_income(count(SAT_Income_View::select($filter_rows, SAT_Income_View::filters(['income_section' => 'Cubs']))) === 2, 'Section filter selects matching logical rows.');
expect_income(count(SAT_Income_View::select($filter_rows, SAT_Income_View::filters(['income_evidence' => 'ofx']))) === 1, 'Evidence filter selects OFX-only logical rows.');
expect_income(count(SAT_Income_View::select($filter_rows, SAT_Income_View::filters(['income_account' => '__unconfirmed__']))) === 1, 'Account filter can select Interac rows without a bank account yet.');
expect_income(count(SAT_Income_View::select($filter_rows, SAT_Income_View::filters(['income_section' => '__no_section__']))) === 1, 'Section filter can select OFX-only rows with no section.');
expect_income(SAT_Income_View::select($filter_rows, SAT_Income_View::filters([]))[0]['date'] === '2026-05-12', 'Default sorting is transaction date descending.');
$amount_sorted = SAT_Income_View::select($filter_rows, SAT_Income_View::filters(['income_sort' => 'amount', 'income_order' => 'asc']));
expect_income($amount_sorted[0]['amount_cents'] === 500 && $amount_sorted[3]['amount_cents'] === 12345, 'Amount sorting uses integer cents.');
expect_income(SAT_Income_View::filters(['income_month' => 'not-a-month'])['month'] === '', 'Invalid month filter safely falls back to all months.');

$db_interac = [synthetic_interac(200)];
$db_ofx = [synthetic_ofx(201), synthetic_ofx(202, 'Transfer Sender')];
SAT_Transfer_OFX::$transfer_ids = [202 => true];
$pdo = new IncomePDO($db_interac, $db_ofx);
SAT_DB::$connection = $pdo;
$_GET = ['sat_view' => 'income', 'page' => 'sat-inbox'];

$GLOBALS['is_admin'] = false;
$shortcode_html = SAT_Inbox::instance()->render();
foreach (['Transactions', 'Expense claims', 'Income', 'Wave export', 'Transfers to Wave'] as $tab) {
    expect_income(str_contains($shortcode_html, $tab), 'Shortcode Inbox retains the "' . $tab . '" navigation tab.');
}
expect_income(str_contains($shortcode_html, 'Interac + OFX') && !str_contains($shortcode_html, 'method="post"'), 'Shortcode Income view shows paired evidence without mutation controls.');

$GLOBALS['is_admin'] = true;
ob_start();
SAT_Inbox::instance()->admin_page();
$admin_html = ob_get_clean();
expect_income(str_contains((string)$admin_html, 'Income') && str_contains((string)$admin_html, 'sat-admin-heading'), 'Admin Inbox renders the Income tab under the SAT heading.');
expect_income(count($pdo->queries) === 4 && count(array_filter($pdo->queries, static fn (string $sql): bool => !str_starts_with(ltrim($sql), 'SELECT'))) === 0, 'Rendering Income performs only source SELECTs and creates no persistent state.');

echo "PASS: {$checks} Income view checks\n";
