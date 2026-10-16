<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);
require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-ofx-writer.php';
require_once dirname(__DIR__) . '/wordpress/scout-accounting-toolkit/includes/class-sat-ofx-parser.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expect_invalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException($message);
}

$pair = [
    ['source_system' => 'ofx_test_bank', 'source_key' => 'bank-source-1'],
    ['source_system' => 'ofx_test_card', 'source_key' => 'card-source-2'],
];
$bank_account = [
    'type' => 'bank',
    'label' => 'Synthetic Operating',
    'acctid' => 'BANK-TEST-001',
    'bank_id' => '123456789',
    'branch_id' => '123',
    'account_type' => 'CHECKING',
    'currency' => 'CAD',
];
$card_account = [
    'type' => 'credit_card',
    'label' => 'Synthetic Card & Fund',
    'acctid' => 'CARD-TEST-002',
    'currency' => 'CAD',
];
$bank_leg = [
    'source_system' => 'ofx_test_bank',
    'source_key' => 'bank-source-1',
    'pair_identity' => $pair,
    'date' => '2026-01-12',
    'amount' => '-125.45',
    'other_account' => 'Synthetic Card',
    'description' => 'Monthly transfer',
    'memo' => 'Transfer & reserve <January> café',
    'statement_start' => '2026-01-01',
    'statement_end' => '2026-01-31',
];
$card_leg = [
    'source_system' => 'ofx_test_card',
    'source_key' => 'card-source-2',
    'pair_identity' => $pair,
    'date' => '2026-01-13',
    'amount' => '125.45',
    'other_account' => 'Synthetic Operating',
    'description' => 'Payment received',
    'memo' => 'Transfer & reserve <January> café',
    'statement_start' => '2026-01-01',
    'statement_end' => '2026-01-31',
];

$bank_output = SAT_OFX_Writer::serialize($bank_account, $bank_leg);
$bank_again = SAT_OFX_Writer::serialize($bank_account, $bank_leg);
$card_output = SAT_OFX_Writer::serialize($card_account, $card_leg);
$card_again = SAT_OFX_Writer::serialize($card_account, $card_leg);

expect($bank_output === $bank_again, 'Repeated bank serialization must be byte-identical.');
expect($card_output === $card_again, 'Repeated credit-card serialization must be byte-identical.');
$reversed_pair_leg = $bank_leg;
$reversed_pair_leg['pair_identity'] = array_reverse($pair);
expect(
    SAT_OFX_Writer::serialize($bank_account, $reversed_pair_leg) === $bank_output,
    'Canonical pair identity must not depend on leg ordering.'
);
expect(
    preg_match('/<FITID>([A-F0-9]{24})/', $bank_output, $bank_fitid) === 1,
    'Bank FITID must be a fixed-length 24-character hexadecimal value.'
);
expect(
    preg_match('/<FITID>([A-F0-9]{24})/', $card_output, $card_fitid) === 1,
    'Credit-card FITID must be a fixed-length 24-character hexadecimal value.'
);
expect($bank_fitid[1] !== $card_fitid[1], 'The two transfer legs must have distinct FITIDs.');
expect(str_contains($bank_output, '<TRNAMT>-125.45'), 'Bank amount sign must be preserved.');
expect(str_contains($card_output, '<TRNAMT>125.45'), 'Credit-card amount sign must be preserved.');
expect(str_contains($bank_output, '<DTPOSTED>20260112000000'), 'Bank posting date must be preserved.');
expect(str_contains($card_output, '<DTPOSTED>20260113000000'), 'Credit-card posting date must be preserved.');
expect(str_contains($bank_output, '<DTSTART>20260101000000') && str_contains($bank_output, '<DTEND>20260131000000'), 'The compatibility wrapper must preserve explicit statement dates.');
expect(str_contains($bank_output, '<BANKACCTFROM>'), 'Bank output must use BANKACCTFROM.');
expect(!str_contains($bank_output, '<CCACCTFROM>'), 'Bank output must not use CCACCTFROM.');
expect(str_contains($card_output, '<CCACCTFROM>'), 'Credit-card output must use CCACCTFROM.');
expect(!str_contains($card_output, '<BANKACCTFROM>'), 'Credit-card output must not use BANKACCTFROM.');
expect(str_contains($bank_output, '<MEMO>Transfer &amp; reserve &lt;January&gt; caf&#xE9;'), 'Bank dynamic text must be escaped.');
expect(str_contains($card_output, '<MEMO>Transfer &amp; reserve &lt;January&gt; caf&#xE9;'), 'XML dynamic text must be escaped.');
expect(str_contains($card_output, '<ORG>Synthetic Card &amp; Fund</ORG>'), 'Credit-card account name must be escaped.');
expect(str_contains($bank_output, 'VERSION:100'), 'Bank output must use the observed OFXSGML 1.00 header.');
expect(str_contains($card_output, 'VERSION="200"'), 'Credit-card output must use the observed OFX XML 2.00 header.');
expect(!str_contains($bank_output, '<LEDGERBAL>'), 'Bank transfer output must not contain a ledger balance.');
expect(!str_contains($card_output, '<LEDGERBAL>'), 'Credit-card transfer output must not contain a ledger balance.');

$multi_bank = [
    ['source_system' => 'ofx_test_bank', 'source_key' => 'bank-source-3', 'pair_identity' => [['source_system' => 'ofx_test_bank', 'source_key' => 'bank-source-3'], ['source_system' => 'ofx_test_card', 'source_key' => 'card-source-3']], 'date' => '2026-01-20', 'amount' => '-87.00', 'other_account' => 'Synthetic Card', 'description' => 'Multi transfer A', 'memo' => 'A', 'statement_start' => '2026-01-01', 'statement_end' => '2026-01-31'],
    ['source_system' => 'ofx_test_bank', 'source_key' => 'bank-source-4', 'pair_identity' => [['source_system' => 'ofx_test_bank', 'source_key' => 'bank-source-4'], ['source_system' => 'ofx_test_card', 'source_key' => 'card-source-4']], 'date' => '2026-01-10', 'amount' => '-47.50', 'other_account' => 'Synthetic Card', 'description' => 'Multi transfer B', 'memo' => 'B', 'statement_start' => '2026-01-01', 'statement_end' => '2026-01-31'],
];
$multi_card = [
    ['source_system' => 'ofx_test_card', 'source_key' => 'card-source-3', 'pair_identity' => [['source_system' => 'ofx_test_bank', 'source_key' => 'bank-source-3'], ['source_system' => 'ofx_test_card', 'source_key' => 'card-source-3']], 'date' => '2026-01-21', 'amount' => '87.00', 'other_account' => 'Synthetic Operating', 'description' => 'Multi transfer A', 'memo' => 'A', 'statement_start' => '2026-01-01', 'statement_end' => '2026-01-31'],
    ['source_system' => 'ofx_test_card', 'source_key' => 'card-source-4', 'pair_identity' => [['source_system' => 'ofx_test_bank', 'source_key' => 'bank-source-4'], ['source_system' => 'ofx_test_card', 'source_key' => 'card-source-4']], 'date' => '2026-01-11', 'amount' => '47.50', 'other_account' => 'Synthetic Operating', 'description' => 'Multi transfer B', 'memo' => 'B', 'statement_start' => '2026-01-01', 'statement_end' => '2026-01-31'],
];
$multi_bank_output = SAT_OFX_Writer::serialize_statement($bank_account, $multi_bank);
$multi_card_output = SAT_OFX_Writer::serialize_statement($card_account, $multi_card);
$multi_bank_reversed = SAT_OFX_Writer::serialize_statement($bank_account, array_reverse($multi_bank));
$multi_card_reversed = SAT_OFX_Writer::serialize_statement($card_account, array_reverse($multi_card));
expect($multi_bank_output === $multi_bank_reversed, 'Bank transaction order must be deterministic.');
expect($multi_card_output === $multi_card_reversed, 'Card transaction order must be deterministic.');
expect(substr_count($multi_bank_output, '<STMTTRN>') === 2, 'Multi-transaction bank output must include two STMTTRN blocks.');
expect(substr_count($multi_card_output, '<STMTTRN>') === 2, 'Multi-transaction credit-card output must include two STMTTRN blocks.');
expect(str_contains($multi_bank_output, '<DTSTART>20260110000000'), 'Bank multi-transaction DTSTART must be the earliest posting date.');
expect(str_contains($multi_bank_output, '<DTEND>20260120000000'), 'Bank multi-transaction DTEND must be the latest posting date.');
expect(str_contains($multi_card_output, '<DTSTART>20260111000000'), 'Card multi-transaction DTSTART must be the earliest posting date.');
expect(str_contains($multi_card_output, '<DTEND>20260121000000'), 'Card multi-transaction DTEND must be the latest posting date.');
expect(!str_contains($multi_bank_output, '<DTSTART>20260101000000'), 'Multi-transaction statements must derive their date range from posting dates.');
expect(str_contains($multi_bank_output, '<TRNAMT>-47.50'), 'Multi-transaction bank output must keep signed amounts.');
expect(str_contains($multi_card_output, '<TRNAMT>47.50'), 'Multi-transaction card output must keep signed amounts.');
expect(!str_contains($multi_bank_output, '<LEDGERBAL>'), 'Multi-transaction bank output must omit LEDGERBAL.');
expect(!str_contains($multi_card_output, '<LEDGERBAL>'), 'Multi-transaction card output must omit LEDGERBAL.');
expect(SAT_OFX_Parser::parse($multi_bank_output)['transactions'][0]['date'] === '2026-01-10', 'Bank statement must round-trip its earliest dated leg.');
expect(SAT_OFX_Parser::parse($multi_card_output)['transactions'][1]['date'] === '2026-01-21', 'Card statement must round-trip its latest dated leg.');
expect_invalid(
    static fn () => SAT_OFX_Writer::serialize_statement($bank_account, []),
    'An empty multi-transaction batch must be rejected.'
);
$duplicate = $multi_bank;
$duplicate[] = $duplicate[0];
expect_invalid(
    static fn () => SAT_OFX_Writer::serialize_statement($bank_account, $duplicate),
    'Duplicate FITIDs in a multi-transaction batch must be rejected.'
);

$parsed_bank = SAT_OFX_Parser::parse($bank_output);
$parsed_card = SAT_OFX_Parser::parse($card_output);
expect($parsed_bank['statement_type'] === 'bank', 'Generated bank statement must parse as a bank statement.');
expect($parsed_card['statement_type'] === 'credit_card', 'Generated card statement must parse as a credit-card statement.');
expect($parsed_bank['transactions'][0]['date'] === '2026-01-12', 'Bank output parser must recover the posting date.');
expect($parsed_card['transactions'][0]['date'] === '2026-01-13', 'Card output parser must recover the posting date.');
expect($parsed_bank['transactions'][0]['amount'] === -125.45, 'Bank output parser must recover the signed amount.');
expect($parsed_card['transactions'][0]['amount'] === 125.45, 'Card output parser must recover the signed amount.');
expect($parsed_bank['transactions'][0]['fitid'] === $bank_fitid[1], 'Bank output parser must recover the deterministic FITID.');
expect($parsed_card['transactions'][0]['fitid'] === $card_fitid[1], 'Card output parser must recover the deterministic FITID.');

$missing_currency = $bank_account;
unset($missing_currency['currency']);
expect_invalid(
    static fn () => SAT_OFX_Writer::serialize($missing_currency, $bank_leg),
    'Missing currency must be rejected.'
);
$missing_account_id = $bank_account;
unset($missing_account_id['acctid']);
expect_invalid(
    static fn () => SAT_OFX_Writer::serialize($missing_account_id, $bank_leg),
    'Missing account ID must be rejected.'
);
$missing_bank_id = $bank_account;
unset($missing_bank_id['bank_id']);
expect_invalid(
    static fn () => SAT_OFX_Writer::serialize($missing_bank_id, $bank_leg),
    'Missing bank ID must be rejected.'
);
$missing_branch_id = $bank_account;
unset($missing_branch_id['branch_id']);
expect_invalid(
    static fn () => SAT_OFX_Writer::serialize($missing_branch_id, $bank_leg),
    'Missing branch ID must be rejected.'
);
$missing_account_type = $bank_account;
unset($missing_account_type['account_type']);
expect_invalid(
    static fn () => SAT_OFX_Writer::serialize($missing_account_type, $bank_leg),
    'Missing bank account type must be rejected.'
);
$missing_card_account_id = $card_account;
unset($missing_card_account_id['acctid']);
expect_invalid(
    static fn () => SAT_OFX_Writer::serialize($missing_card_account_id, $card_leg),
    'Missing credit-card account ID must be rejected.'
);

echo "SAT OFX writer tests: OK\n";
