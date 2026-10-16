<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);
require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-ofx-writer.php';
require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-ofx-parser.php';
require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-transfer-ofx.php';

function transfer_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function transfer_rejected(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

$banks = [
    'ofx_operating' => ['label' => 'Operating', 'type' => 'bank', 'acctid' => 'ACCOUNT-OPERATING'],
    'ofx_card' => ['label' => 'Group Visa', 'type' => 'credit_card', 'acctid' => 'ACCOUNT-CARD'],
    'ofx_fund' => ['label' => 'Camp Fund', 'type' => 'bank', 'acctid' => 'ACCOUNT-FUND'],
];
$accounts = [
    'ACCOUNT-OPERATING' => ['source_system' => 'ofx_operating'],
    'ACCOUNT-CARD' => ['source_system' => 'ofx_card'],
    'ACCOUNT-FUND' => ['source_system' => 'ofx_fund'],
];

function transfer_observation(
    int $id,
    string $source,
    string $source_key,
    string $amount,
    string $date,
    string $currency = 'CAD',
    string $description = 'Transfer memo'
): array {
    $account_id = [
        'ofx_operating' => 'ACCOUNT-OPERATING',
        'ofx_card' => 'ACCOUNT-CARD',
        'ofx_fund' => 'ACCOUNT-FUND',
    ][$source] ?? '';
    $type = $source === 'ofx_card' ? 'credit_card' : 'bank';
    return [
        'observation_id' => $id,
        'match_id' => 77,
        'source_system' => $source,
        'source_key' => $source_key,
        'transaction_date' => $date,
        'amount' => $amount,
        'description' => $description,
        'gf_entry_id' => null,
        'review_status' => 'ready',
        'payload_json' => json_encode([
            'account_id' => $account_id,
            'statement_type' => $type,
            'currency' => $currency,
            'bank_id' => $type === 'bank' ? '123456789' : '',
            'branch_id' => $type === 'bank' ? '123' : '',
            'account_type' => $type === 'bank' ? 'CHECKING' : '',
            'name' => 'ONLINE TRANSFER',
            'memo' => $description,
        ]),
    ];
}

$bank_to_card = [
    transfer_observation(1, 'ofx_operating', 'operating-1', '-816.99', '2026-10-06'),
    transfer_observation(2, 'ofx_card', 'card-1', '816.99', '2026-10-07'),
];
$valid_card = SAT_Transfer_OFX::validate_pair($bank_to_card, $banks, $accounts, [1 => 1, 2 => 1]);
transfer_expect($valid_card['source']['observation_id'] === 1, 'Negative bank leg must be the source.');
transfer_expect($valid_card['destination']['observation_id'] === 2, 'Positive card leg must be the destination.');
transfer_expect($valid_card['amount_cents'] === 81699, 'Pair display amount must be positive cents.');
transfer_expect($valid_card['currency'] === 'CAD', 'Pair currency must be preserved.');

$bank_to_bank = [
    transfer_observation(3, 'ofx_operating', 'operating-2', '-10.50', '2026-10-08'),
    transfer_observation(4, 'ofx_fund', 'fund-2', '10.50', '2026-10-08'),
];
transfer_expect(
    SAT_Transfer_OFX::validate_pair($bank_to_bank, $banks, $accounts, [3 => 1, 4 => 1])['match_id'] === 77,
    'A valid bank-to-bank pair must be eligible.'
);
transfer_expect(count([SAT_Transfer_OFX::validate_pair($bank_to_bank, $banks, $accounts, [3 => 1, 4 => 1])]) === 1, 'A transfer pair counts once rather than as two legs.');

transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([$bank_to_card[0], array_merge($bank_to_card[1], ['source_system' => 'gravity_forms'])], $banks, $accounts, [1 => 1, 2 => 1]),
    'Gravity Forms evidence must not qualify as a transfer.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([$bank_to_card[0], $bank_to_card[0]], $banks, $accounts, [1 => 1]),
    'A transfer must contain two distinct observations.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([$bank_to_card[0], $bank_to_card[1], $bank_to_card[0]], $banks, $accounts, [1 => 1, 2 => 1]),
    'A transfer must contain exactly two observations.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([$bank_to_card[0], array_merge($bank_to_card[1], ['source_system' => 'ofx_operating', 'payload_json' => json_encode(['account_id' => 'ACCOUNT-OPERATING', 'statement_type' => 'bank', 'currency' => 'CAD', 'bank_id' => '123456789', 'branch_id' => '123', 'account_type' => 'CHECKING'])])], $banks, $accounts, [1 => 1, 2 => 1]),
    'Same-account observations must not qualify.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([$bank_to_card[0], array_merge($bank_to_card[1], ['amount' => '-816.99'])], $banks, $accounts, [1 => 1, 2 => 1]),
    'Same-sign amounts must not qualify.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([array_merge($bank_to_card[0], ['amount' => '-0.00']), array_merge($bank_to_card[1], ['amount' => '0.00'])], $banks, $accounts, [1 => 1, 2 => 1]),
    'Zero-value observations must not qualify as transfers.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([$bank_to_card[0], array_merge($bank_to_card[1], ['amount' => '816.98'])], $banks, $accounts, [1 => 1, 2 => 1]),
    'Unequal cents must not qualify.'
);
$different_currency = $bank_to_card;
$different_currency[1]['payload_json'] = json_encode(['account_id' => 'ACCOUNT-CARD', 'statement_type' => 'credit_card', 'currency' => 'USD']);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair($different_currency, $banks, $accounts, [1 => 1, 2 => 1]),
    'Currency mismatch must not qualify.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair($bank_to_card, $banks, $accounts, [1 => 2, 2 => 1]),
    'Multiply-linked observations must not qualify.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair($bank_to_card, $banks, $accounts, [1 => 1, 2 => 1], [1 => true]),
    'Already-batched observations must not qualify.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([array_merge($bank_to_card[0], ['review_status' => 'excluded']), $bank_to_card[1]], $banks, $accounts, [1 => 1, 2 => 1]),
    'Excluded observations must not qualify.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([array_merge($bank_to_card[0], ['review_status' => 'exported']), $bank_to_card[1]], $banks, $accounts, [1 => 1, 2 => 1]),
    'Exported observations must not qualify.'
);
transfer_rejected(
    static fn () => SAT_Transfer_OFX::validate_pair([array_merge($bank_to_card[0], ['gf_entry_id' => 9]), $bank_to_card[1]], $banks, $accounts, [1 => 1, 2 => 1]),
    'Observations linked to a Gravity Forms entry must not qualify.'
);
$missing_branch = $bank_to_bank;
$missing_branch[0]['payload_json'] = json_encode(['account_id' => 'ACCOUNT-OPERATING', 'statement_type' => 'bank', 'currency' => 'CAD', 'bank_id' => '123456789', 'branch_id' => '', 'account_type' => 'CHECKING']);
try {
    SAT_Transfer_OFX::validate_pair($missing_branch, $banks, $accounts, [3 => 1, 4 => 1]);
    throw new RuntimeException('Missing bank metadata must fail OFX eligibility.');
} catch (InvalidArgumentException $e) {
    transfer_expect(str_contains($e->getMessage(), 'branch ID'), 'OFX eligibility failures must explain the specific reason.');
}

// M1/M2: reserved observations and every accepted payment-account transfer are blocked from the Wave API.
transfer_expect(SAT_Transfer_OFX::wave_api_block_reason(5, [], []) === '', 'Ordinary observations must remain available to the Wave API.');
$reserved_reason = SAT_Transfer_OFX::wave_api_block_reason(5, [], [5 => true]);
transfer_expect(str_contains($reserved_reason, 'transfer OFX batch'), 'Reserved observations must be rejected by the Wave API path.');
$transfer_reason = SAT_Transfer_OFX::wave_api_block_reason(5, [5 => true], []);
transfer_expect(
    str_starts_with($transfer_reason, 'Matched transfers cannot be sent through the Wave API.') && str_contains($transfer_reason, 'Transfers to Wave'),
    'Accepted payment-account transfers must be rejected even when OFX eligibility fails, directing users to Transfers to Wave.'
);
transfer_expect(SAT_Transfer_OFX::wave_api_block_reason(5, [5 => true], [5 => true]) === $reserved_reason, 'Reservation must take precedence in the block reason.');
transfer_expect(SAT_Transfer_OFX::wave_api_block_reason(6, [5 => true], [5 => true]) === '', 'Blocking must apply only to the affected observation.');

// M2: blocked payment-account matches show under Needs attention unless already batched or fully exported.
$attention_pair = [
    ['observation_id' => 1, 'review_status' => 'ready'],
    ['observation_id' => 2, 'review_status' => null],
];
transfer_expect(SAT_Transfer_OFX::needs_attention($attention_pair, []), 'An ineligible open transfer must need attention.');
transfer_expect(!SAT_Transfer_OFX::needs_attention($attention_pair, [2 => true]), 'A transfer owned by a generated or confirmed batch must not need attention.');
transfer_expect(
    !SAT_Transfer_OFX::needs_attention([['observation_id' => 1, 'review_status' => 'exported'], ['observation_id' => 2, 'review_status' => 'exported']], []),
    'A fully exported transfer must not need attention.'
);
transfer_expect(
    SAT_Transfer_OFX::needs_attention([['observation_id' => 1, 'review_status' => 'exported'], ['observation_id' => 2, 'review_status' => 'ready']], []),
    'A half-exported transfer must need attention.'
);

// M3: Ready counts do not depend on the active Transactions view.
$fixture_rows = [
    ['bucket' => 'ready', 'transfer' => false],
    ['bucket' => 'ready', 'transfer' => true],
    ['bucket' => 'ready', 'transfer' => true],
    ['bucket' => 'attention', 'transfer' => false],
    ['bucket' => 'attention', 'transfer' => true],
    ['bucket' => 'excluded', 'transfer' => false],
];
$counts_by_view = [];
$shown_by_view = [];
foreach (['attention', 'ready', 'excluded', 'all'] as $view) {
    $counts = ['attention' => 0, 'ready' => 0, 'excluded' => 0];
    $shown = 0;
    foreach ($fixture_rows as $row) {
        $placement = SAT_Transfer_OFX::inbox_placement($row['bucket'], $row['transfer'], $view);
        if ($placement['count'] !== null) {
            $counts[$placement['count']]++;
        }
        $shown += $placement['show'] ? 1 : 0;
    }
    $counts_by_view[$view] = $counts;
    $shown_by_view[$view] = $shown;
}
transfer_expect(count(array_unique(array_map('json_encode', $counts_by_view))) === 1, 'Bucket counts must be identical in every view.');
transfer_expect($counts_by_view['all'] === ['attention' => 2, 'ready' => 1, 'excluded' => 1], 'Ready transfer legs must not be counted as Ready for Wave.');
transfer_expect($shown_by_view['ready'] === 1, 'Ready for Wave must list only the ordinary ready row.');
transfer_expect($shown_by_view['all'] === count($fixture_rows), 'All must still list transfer legs.');
transfer_expect($shown_by_view['attention'] === 2, 'Needs attention listing must be unchanged.');

// L2: shutdown cleanup removes a temporary bundle even when the request ends before the normal unlink.
$orphan = tempnam(sys_get_temp_dir(), 'sat-test-transfer-orphan-');
if ($orphan === false) {
    throw new RuntimeException('Unable to create the shutdown-cleanup test file.');
}
$child = sprintf(
    'define("ABSPATH", "/"); require %s; SAT_Transfer_OFX::register_temporary_cleanup(%s); exit(0);',
    var_export(dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-transfer-ofx.php', true),
    var_export($orphan, true)
);
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($child), $child_output, $child_status);
$orphan_survived = is_file($orphan);
if ($orphan_survived) {
    unlink($orphan);
}
transfer_expect($child_status === 0, 'Shutdown-cleanup child process must run.');
transfer_expect(!$orphan_survived, 'Registered shutdown cleanup must delete the temporary bundle.');

transfer_expect(
    SAT_Transfer_OFX::transition_allowed('generated', 'confirmed')
    && SAT_Transfer_OFX::transition_allowed('generated', 'cancelled')
    && !SAT_Transfer_OFX::transition_allowed('confirmed', 'cancelled')
    && !SAT_Transfer_OFX::transition_allowed('cancelled', 'confirmed'),
    'Only generated batches may transition to confirmed or cancelled.'
);

$grouped = SAT_Transfer_OFX::group_legs_by_account([
    ['account_id' => 'ACCOUNT-CARD', 'fitid' => 'C2'],
    ['account_id' => 'ACCOUNT-OPERATING', 'fitid' => 'B1'],
    ['account_id' => 'ACCOUNT-CARD', 'fitid' => 'C1'],
]);
transfer_expect(array_keys($grouped) === ['ACCOUNT-CARD', 'ACCOUNT-OPERATING'], 'Frozen legs must group deterministically by account identity.');
transfer_expect(count($grouped['ACCOUNT-CARD']) === 2, 'Each affected account file must include every frozen account leg.');

$manifest_args = [
    12,
    '2026-10-15 12:00:00',
    [
        ['filename' => 'sat-transfer-batch-12-account-01.ofx', 'transaction_count' => 1, 'account_label' => 'Group Visa 5555'],
        ['filename' => 'sat-transfer-batch-12-account-02.ofx', 'transaction_count' => 1, 'account_label' => 'Operating'],
    ],
    [[
        'match_id' => 77,
        'source_label' => 'Operating',
        'destination_label' => 'Group Visa 5555',
        'amount' => '816.99',
        'currency' => 'CAD',
        'source_date' => '2026-10-06',
        'destination_date' => '2026-10-07',
    ]],
];
$manifest = SAT_Transfer_OFX::manifest(...$manifest_args);
transfer_expect($manifest === SAT_Transfer_OFX::manifest(...$manifest_args), 'Manifest output must be deterministic.');
transfer_expect(str_contains($manifest, 'Batch ID: 12') && str_contains($manifest, 'Created: 2026-10-15 12:00:00'), 'Manifest must include batch identity and creation date.');
transfer_expect(str_contains($manifest, 'sat-transfer-batch-12-account-01.ofx (1 transactions) - Group Visa [account reference omitted]'), 'Manifest must map account filenames and transaction counts to safely redacted account labels.');
transfer_expect(str_contains($manifest, 'Operating -> Group Visa [account reference omitted] | 816.99 CAD | 2026-10-06 / 2026-10-07'), 'Manifest must include transfer account, amount, currency and dates while redacting numeric account references.');
transfer_expect(!str_contains($manifest, 'ACCOUNT-OPERATING'), 'Manifest must not expose account IDs.');
transfer_expect(!str_contains($manifest, 'operating-1'), 'Manifest must not expose source keys.');
transfer_expect(!str_contains($manifest, '5555'), 'Manifest must not expose account numbers embedded in configured account labels.');

if (!class_exists('ZipArchive')) {
    throw new RuntimeException('ZipArchive is required to test bundle contents.');
}
$zip_path = tempnam(sys_get_temp_dir(), 'sat-test-transfer-');
if ($zip_path === false) {
    throw new RuntimeException('Unable to create the temporary transfer ZIP test file.');
}
try {
    $zip = new ZipArchive();
    transfer_expect($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Test ZIP must open.');
    SAT_Transfer_OFX::add_bundle_entries($zip, [
        ['filename' => 'sat-transfer-batch-12-ofx_operating.ofx', 'contents' => 'OFX bank bytes'],
        ['filename' => 'sat-transfer-batch-12-ofx_card.ofx', 'contents' => 'OFX card bytes'],
        ['filename' => 'manifest.txt', 'contents' => $manifest],
    ]);
    transfer_expect($zip->close(), 'Test ZIP must close.');
    $read = new ZipArchive();
    transfer_expect($read->open($zip_path) === true, 'Generated test ZIP must reopen.');
    $names = [];
    for ($index = 0; $index < $read->numFiles; $index++) {
        $names[] = $read->getNameIndex($index);
    }
    sort($names);
    transfer_expect($names === ['manifest.txt', 'sat-transfer-batch-12-ofx_card.ofx', 'sat-transfer-batch-12-ofx_operating.ofx'], 'Bundle must contain account files and one manifest.');
    transfer_expect($read->getFromName('manifest.txt') === $manifest, 'Bundle manifest contents must be deterministic.');
    transfer_expect($read->getFromName('sat-transfer-batch-12-ofx_operating.ofx') === 'OFX bank bytes', 'Bundle must preserve account OFX bytes.');
    $read->close();
    $first_zip = file_get_contents($zip_path);
} finally {
    if (is_file($zip_path)) {
        unlink($zip_path);
    }
}
$second_zip_path = tempnam(sys_get_temp_dir(), 'sat-test-transfer-second-');
if ($second_zip_path === false) {
    throw new RuntimeException('Unable to create the second temporary transfer ZIP test file.');
}
try {
    $second_zip = new ZipArchive();
    transfer_expect($second_zip->open($second_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Second test ZIP must open.');
    SAT_Transfer_OFX::add_bundle_entries($second_zip, [
        ['filename' => 'sat-transfer-batch-12-ofx_operating.ofx', 'contents' => 'OFX bank bytes'],
        ['filename' => 'sat-transfer-batch-12-ofx_card.ofx', 'contents' => 'OFX card bytes'],
        ['filename' => 'manifest.txt', 'contents' => $manifest],
    ]);
    transfer_expect($second_zip->close(), 'Second test ZIP must close.');
    transfer_expect(file_get_contents($second_zip_path) === $first_zip, 'Repeated bundle generation must be byte-identical.');
} finally {
    if (is_file($second_zip_path)) {
        unlink($second_zip_path);
    }
}
transfer_rejected(
    static function (): void {
        $zip = new ZipArchive();
        $path = tempnam(sys_get_temp_dir(), 'sat-test-transfer-unsafe-');
        if ($path === false) {
            throw new RuntimeException('Unable to create unsafe-filename test archive.');
        }
        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to open unsafe-filename test archive.');
            }
            SAT_Transfer_OFX::add_bundle_entries($zip, [['filename' => '../unsafe.ofx', 'contents' => 'x']]);
        } finally {
            $zip->close();
            if (is_file($path)) {
                unlink($path);
            }
        }
    },
    'Unsafe ZIP entry paths must be rejected.'
);

// Batch file records: counts cover every frozen leg in the complete batch.
$frozen_accounts = [
    'ofx_cg_funds' => ['account_id' => 'ACCOUNT-CG-FUNDS', 'account_label' => 'CG Funds', 'account_type' => 'bank', 'bank_id' => '123456789', 'branch_id' => '123', 'bank_account_type' => 'CHECKING'],
    'ofx_gc_visa' => ['account_id' => 'ACCOUNT-GC-VISA', 'account_label' => 'GC Visa', 'account_type' => 'credit_card', 'bank_id' => null, 'branch_id' => null, 'bank_account_type' => null],
    'ofx_operating' => ['account_id' => 'ACCOUNT-OPERATING', 'account_label' => 'Operating', 'account_type' => 'bank', 'bank_id' => '123456789', 'branch_id' => '456', 'bank_account_type' => 'CHECKING'],
];

function frozen_transfer(int $batch_match_id, array $accounts, string $from, string $to, string $amount, string $date): array
{
    $from_key = $from . '-key-' . $batch_match_id;
    $to_key = $to . '-key-' . $batch_match_id;
    $pair = [
        ['source_system' => $from, 'source_key' => $from_key],
        ['source_system' => $to, 'source_key' => $to_key],
    ];
    $legs = [];
    foreach ([[$from, $from_key, '-' . $amount, $to], [$to, $to_key, $amount, $from]] as $index => [$source, $key, $signed, $other]) {
        $account = $accounts[$source];
        $legs[] = $account + [
            'batch_match_id' => $batch_match_id,
            'observation_id' => $batch_match_id * 10 + $index,
            'match_id' => $batch_match_id + 100,
            'source_system' => $source,
            'source_key' => $key,
            'currency' => 'CAD',
            'transaction_date' => $date,
            'amount' => $signed,
            'fitid' => SAT_OFX_Writer::fitid_for_leg($pair, $source, $key, $account['account_id']),
            'name' => 'Transfer ' . (str_starts_with($signed, '-') ? 'to ' : 'from ') . $accounts[$other]['account_label'],
            'memo' => 'Transfer ' . $batch_match_id,
        ];
    }
    return [
        'legs' => $legs,
        'transfer' => [
            'match_id' => $batch_match_id + 100,
            'source_label' => $accounts[$from]['account_label'],
            'destination_label' => $accounts[$to]['account_label'],
            'amount' => $amount,
            'currency' => 'CAD',
            'source_date' => $date,
            'destination_date' => $date,
        ],
    ];
}

function frozen_batch(array $transfers): array
{
    $legs = [];
    $rows = [];
    foreach ($transfers as $transfer) {
        $legs = array_merge($legs, $transfer['legs']);
        $rows[] = $transfer['transfer'];
    }
    return [$legs, $rows];
}

function file_counts(array $files): array
{
    $counts = [];
    foreach ($files as $file) {
        $counts[$file['account_id']] = $file['transaction_count'];
    }
    return $counts;
}

$transfer_batch_row = ['batch_id' => 1, 'created_at' => '2026-10-16 10:00:00'];

// Three CG Funds -> GC Visa transfers: two files, three transactions each.
[$same_pair_legs, $same_pair_transfers] = frozen_batch([
    frozen_transfer(1, $frozen_accounts, 'ofx_cg_funds', 'ofx_gc_visa', '100.00', '2026-10-01'),
    frozen_transfer(2, $frozen_accounts, 'ofx_cg_funds', 'ofx_gc_visa', '200.00', '2026-10-02'),
    frozen_transfer(3, $frozen_accounts, 'ofx_cg_funds', 'ofx_gc_visa', '300.00', '2026-10-03'),
]);
$same_pair_files = SAT_Transfer_OFX::account_file_plan(1, $same_pair_legs);
transfer_expect(count($same_pair_files) === 2, 'Three transfers between two accounts must create two file records.');
transfer_expect(
    file_counts($same_pair_files) === ['ACCOUNT-CG-FUNDS' => 3, 'ACCOUNT-GC-VISA' => 3],
    'Each account file record must count all three frozen legs for that account.'
);
transfer_expect(
    array_column($same_pair_files, 'filename') === ['sat-transfer-batch-1-account-01.ofx', 'sat-transfer-batch-1-account-02.ofx'],
    'File records must keep deterministic account order and filenames.'
);
transfer_expect(
    array_column($same_pair_files, 'source_system') === ['ofx_cg_funds', 'ofx_gc_visa'],
    'File records must keep each account source system.'
);
transfer_expect(
    SAT_Transfer_OFX::account_file_plan(1, array_reverse($same_pair_legs)) === $same_pair_files,
    'File records must not depend on leg assembly order.'
);
transfer_expect(array_sum(array_column($same_pair_files, 'transaction_count')) === count($same_pair_legs), 'File counts must total the frozen-leg count.');

$same_pair_bundle = SAT_Transfer_OFX::bundle_entries($transfer_batch_row, $same_pair_files, $same_pair_legs, $same_pair_transfers);
transfer_expect(
    array_column($same_pair_bundle, 'filename') === ['sat-transfer-batch-1-account-01.ofx', 'sat-transfer-batch-1-account-02.ofx', 'manifest.txt'],
    'Bundle generation must succeed with correctly recorded counts.'
);
foreach ([0, 1] as $index) {
    $parsed = SAT_OFX_Parser::parse($same_pair_bundle[$index]['contents']);
    transfer_expect(count($parsed['transactions']) === 3, 'Each account OFX file must contain all three frozen legs.');
}
transfer_expect(str_contains($same_pair_bundle[2]['contents'], '(3 transactions) - CG Funds'), 'Manifest must report the full CG Funds count.');
transfer_expect(str_contains($same_pair_bundle[2]['contents'], '(3 transactions) - GC Visa'), 'Manifest must report the full GC Visa count.');
transfer_expect(
    SAT_Transfer_OFX::bundle_entries($transfer_batch_row, $same_pair_files, array_reverse($same_pair_legs), $same_pair_transfers) === $same_pair_bundle,
    'Regenerated bundle contents must be identical regardless of frozen-row order.'
);

// Three accounts with unequal counts.
[$three_account_legs, $three_account_transfers] = frozen_batch([
    frozen_transfer(4, $frozen_accounts, 'ofx_operating', 'ofx_gc_visa', '10.00', '2026-10-04'),
    frozen_transfer(5, $frozen_accounts, 'ofx_operating', 'ofx_gc_visa', '20.00', '2026-10-05'),
    frozen_transfer(6, $frozen_accounts, 'ofx_operating', 'ofx_cg_funds', '30.00', '2026-10-06'),
]);
$three_account_files = SAT_Transfer_OFX::account_file_plan(2, $three_account_legs);
transfer_expect(
    file_counts($three_account_files) === ['ACCOUNT-CG-FUNDS' => 1, 'ACCOUNT-GC-VISA' => 2, 'ACCOUNT-OPERATING' => 3],
    'A three-account batch must record each account\'s own unequal count.'
);
transfer_expect(array_sum(array_column($three_account_files, 'transaction_count')) === count($three_account_legs), 'Three-account file counts must total the frozen-leg count.');
$three_account_bundle = SAT_Transfer_OFX::bundle_entries(['batch_id' => 2, 'created_at' => '2026-10-16 10:00:00'], $three_account_files, $three_account_legs, $three_account_transfers);
transfer_expect(count($three_account_bundle) === 4, 'A three-account bundle must contain three OFX files and a manifest.');
foreach ($three_account_files as $index => $file) {
    $parsed = SAT_OFX_Parser::parse($three_account_bundle[$index]['contents']);
    transfer_expect(count($parsed['transactions']) === $file['transaction_count'], 'Each OFX file must contain exactly its recorded number of legs.');
}

// A deliberately wrong count (the original defect) is still refused on download.
$wrong_count_files = $same_pair_files;
$wrong_count_files[0]['transaction_count'] = 1;
try {
    SAT_Transfer_OFX::bundle_entries($transfer_batch_row, $wrong_count_files, $same_pair_legs, $same_pair_transfers);
    throw new LogicException('An incorrect file transaction count must be rejected.');
} catch (RuntimeException $e) {
    transfer_expect($e->getMessage() === 'Frozen account transaction count does not match its file record.', 'Download must reject an incorrect recorded count.');
}
$missing_file = [$same_pair_files[0]];
try {
    SAT_Transfer_OFX::bundle_entries($transfer_batch_row, $missing_file, $same_pair_legs, $same_pair_transfers);
    throw new LogicException('Frozen legs without a file record must be rejected.');
} catch (RuntimeException $e) {
    transfer_expect($e->getMessage() === 'Frozen account legs do not match the batch file records.', 'Download must reject legs with no file record.');
}

echo "SAT transfer OFX tests: OK\n";
