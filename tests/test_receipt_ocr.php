<?php
declare(strict_types=1);

define('ABSPATH', __DIR__);

function wp_check_invalid_utf8(string $text, bool $strip = false): string
{
    return $text;
}

function sanitize_text_field(string $text): string
{
    return trim($text);
}

final class SAT_Settings
{
    public static function parse_pairs(string $raw): array
    {
        $pairs = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2 && $parts[0] !== '') {
                $pairs[$parts[0]] = $parts[1];
            }
        }
        return $pairs;
    }
}

require_once __DIR__ . '/../wordpress/scout-accounting-toolkit/includes/class-sat-receipt-ocr.php';

function expect_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function parse_synthetic_receipt(string $text): array
{
    $ocr = (new ReflectionClass(SAT_Receipt_OCR::class))->newInstanceWithoutConstructor();
    $parse = new ReflectionMethod(SAT_Receipt_OCR::class, 'parse_receipt');
    return $parse->invoke($ocr, $text, [
        'known_merchants' => '',
        'card_context_chars' => 40,
        'card_mappings' => '',
    ]);
}

$short_receipt = <<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1001
Booking Description: Synthetic Winter Weekend
When: 2027-02-05 through 2027-02-07
Order Date: 10/14/2026
Order Total: $120.00
RECEIPT;
$short = parse_synthetic_receipt($short_receipt);
expect_same('14-10-2026', $short['date'], 'Order Date must win over an earlier future booking range.');
expect_same('order_date', $short['date_source'], 'The selected date source must identify Order Date evidence.');
expect_same(0.95, $short['confidence']['date'], 'A labelled Order Date must have stronger date confidence.');
expect_same('Synthetic Booking', $short['merchant'], 'Vendor-specific date selection must not change merchant extraction.');
expect_same('120.00', $short['total'], 'Vendor-specific date selection must not change amount extraction.');

$future_camp_receipt = <<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1002
Booking: 8/14/2027 - 8/21/2027
Order Date : 9/17/2026
Order Total: $85.00
RECEIPT;
$camp = parse_synthetic_receipt($future_camp_receipt);
expect_same('17-09-2026', $camp['date'], 'A spaced Order Date label must win over a future-year camp range.');
expect_same('order_date', $camp['date_source'], 'The date provenance must remain Order Date.');

$long_email_receipt = <<<'RECEIPT'
Email sent: 9/16/2026 08:30 AM
Printed: 9/18/2026
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1003
Order Date:

  9/14/2026
When: 2026-10-02 through 2026-10-04
Cancellation policy effective 2026-01-01; example date 2025-05-06.
Printed: 9/18/2026
When: 2026-10-02 through 2026-10-04
When: 2026-10-02 through 2026-10-04
When: 2026-10-02 through 2026-10-04
RECEIPT;
$long = parse_synthetic_receipt($long_email_receipt);
expect_same('14-09-2026', $long['date'], 'The labelled date must beat earlier email/print dates and repeated event dates.');
expect_same('order_date', $long['date_source'], 'The long receipt must report Order Date provenance.');

$case_and_spacing = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1004
Booking: 2027-08-14 through 2027-08-21
oRdEr    dAtE : 9/17/2026
RECEIPT);
expect_same('17-09-2026', $case_and_spacing['date'], 'Order Date matching must tolerate case and repeated whitespace.');

$labelled_dmy = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1010
Order Date: 17/09/2026
RECEIPT);
expect_same('17-09-2026', $labelled_dmy['date'], 'A labelled day-first date must remain valid for Canadian documents.');

$labelled_ymd = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1011
Order Date: 2026-09-17
RECEIPT);
expect_same('17-09-2026', $labelled_ymd['date'], 'A labelled year-first date must remain valid for Canadian documents.');

$labelled_ambiguous = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1012
Order Date: 9/10/2026
RECEIPT);
expect_same('09-10-2026', $labelled_ambiguous['date'], 'Ambiguous labelled numeric dates must keep the existing day-first convention.');

$invalid_order_date = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1005
Order Date: 2/30/2026
When: 2027-02-05 through 2027-02-07
RECEIPT);
expect_same('', $invalid_order_date['date'], 'An invalid Order Date must not fall back to an event date.');
expect_same('none', $invalid_order_date['date_source'], 'An invalid labelled date must have no claimed provenance.');

$missing_order_date = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1006
When: 2027-02-05 through 2027-02-07
RECEIPT);
expect_same('', $missing_order_date['date'], 'A recognized booking without a safe transaction date must remain blank.');

$payment_fallback = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1007
Payment completed: 2026-09-13
Order Date: not-a-date
When: 2027-02-05 through 2027-02-07
RECEIPT);
expect_same('13-09-2026', $payment_fallback['date'], 'An explicitly completed payment is a safe fallback when Order Date is invalid.');
expect_same('payment_date', $payment_fallback['date_source'], 'The safe fallback provenance must identify the completed payment.');

$email_fallback = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1008
Email sent: 9/16/2026
When: 2027-02-05 through 2027-02-07
RECEIPT);
expect_same('16-09-2026', $email_fallback['date'], 'A labelled email timestamp may be used when Order Date is absent.');
expect_same('email_timestamp', $email_fallback['date_source'], 'The fallback provenance must identify the email timestamp.');

$document_fallback = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1013
Printed: Friday, September 18, 2026
When: 2027-02-05 through 2027-02-07
RECEIPT);
expect_same('18-09-2026', $document_fallback['date'], 'A document print timestamp may be used as a lower-priority fallback.');
expect_same('document_timestamp', $document_fallback['date_source'], 'The fallback provenance must identify the document timestamp.');

$near_miss = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Scout Receipt
Scouts Canada
Order Number: SYNTHETIC-1009
When: 2027-02-05
Order Date: 10/14/2026
RECEIPT);
expect_same('05-02-2027', $near_miss['date'], 'A non-Doubleknot Scouts Canada document must retain generic date selection.');
expect_same('generic', $near_miss['date_source'], 'A nonmatching receipt must retain generic provenance.');

$unrelated_scouts_document = parse_synthetic_receipt(<<<'RECEIPT'
Scouts Canada
Program Date: 2026-10-14
Receipt Total: $10.00
RECEIPT);
expect_same('14-10-2026', $unrelated_scouts_document['date'], 'An unrelated Scouts Canada document must retain generic extraction.');

$canadian_dmy = parse_synthetic_receipt("Local Receipt\nDate: 17/09/2026\nTotal: $15.00");
expect_same('17-09-2026', $canadian_dmy['date'], 'Generic day-first Canadian dates must remain unchanged.');
expect_same('generic', $canadian_dmy['date_source'], 'Generic Canadian dates must retain generic provenance.');
$generic_ymd = parse_synthetic_receipt("Local Receipt\nDate: 2026-09-17\nTotal: $15.00");
expect_same('17-09-2026', $generic_ymd['date'], 'Generic year-first dates must remain unchanged.');

echo "Receipt OCR tests passed.\n";
