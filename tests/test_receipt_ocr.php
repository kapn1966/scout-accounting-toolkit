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
Firefox
Customer Copy
https://scouts.doubleknot.com/receipt
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1001
Booking: Booking ID 884422 - Cliff Cabin - Weekend Overnight (Fri-Sun); Qty: 2; Participants: 8; $200.00, 2/5/2027 7:00 PM - 2/7/2027 11:00 AM
Order Date: 10/14/2026
Total $120.00
...1234 ($120.00)
Balance $0.00
RECEIPT;
$short = parse_synthetic_receipt($short_receipt);
expect_same('14-10-2026', $short['date'], 'Order Date must win over an earlier future booking range.');
expect_same('order_date', $short['date_source'], 'The selected date source must identify Order Date evidence.');
expect_same(0.95, $short['confidence']['date'], 'A labelled Order Date must have stronger date confidence.');
expect_same('Scouts Canada', $short['merchant'], 'The identified booking vendor must supply the canonical payee.');
expect_same('Cliff Cabin - Weekend Overnight (Feb 5-7, 2027)', $short['description'], 'A structured facility booking must produce a concise description and event date range.');
foreach (['Firefox', 'Customer Copy', 'Doubleknot', 'scouts.doubleknot.com', '884422', '1234', 'Participants', '$200.00', 'Policy'] as $excluded) {
    expect_same(false, str_contains($short['merchant'], $excluded), 'Non-payee text must not become the canonical payee.');
    expect_same(false, str_contains($short['description'], $excluded), 'Identifiers, platform text and unrelated content must not enter the booking description.');
}
expect_same('120.00', $short['total'], 'Booking description extraction must preserve the completed-payment amount.');
expect_same('card_charge', $short['total_source'], 'A card suffix must be used only as a structural signal, not exposed as amount provenance.');

$bedford_booking = parse_synthetic_receipt(<<<'RECEIPT'
Firefox Print
https://scouts.doubleknot.com/receipt
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1014
Order Date: 9/17/2026
Booking: Bedford Park - Week 7 - Sailing/Boating Week, 8/14/2027 12:00 PM - 8/21/2027 10:00 AM
Total Payment: $517.00
Balance $0.00
RECEIPT);
expect_same('Scouts Canada', $bedford_booking['merchant'], 'Firefox and browser headers must not override the identified-family payee.');
expect_same('Bedford Park - Sailing/Boating Week (Aug 14-21, 2027)', $bedford_booking['description'], 'Week-number booking labels must be concise while preserving activity and event dates.');
expect_same('17-09-2026', $bedford_booking['date'], 'Booking event dates must not displace the labelled transaction Order Date.');
expect_same('order_date', $bedford_booking['date_source'], 'The extracted transaction date must retain Order Date provenance.');
expect_same('517.00', $bedford_booking['total'], 'Booking description extraction must preserve completed-payment amount selection.');
expect_same('total_payment', $bedford_booking['total_source'], 'The completed-payment amount provenance must remain unchanged.');

$property_booking = parse_synthetic_receipt(<<<'RECEIPT'
Gmail print view
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1015
Property: Synthetic Woodland Camp
Booking: Synthetic Poplar Village - Weekend Overnight
Dates: 10/2/2026 6:00 PM - 10/4/2026 2:00 PM
Order Date: 9/14/2026
Convenience Fee: $22.58
Total Payment: $708.58
Balance: $0.00
Policy example: Synthetic Lakeside Camp
RECEIPT);
expect_same('Synthetic Woodland Camp - Synthetic Poplar Village - Weekend Overnight (Oct 2-4, 2026)', $property_booking['description'], 'An explicitly associated property and facility must both appear once in the concise description.');
expect_same('14-09-2026', $property_booking['date'], 'The long booking event range must not replace Order Date.');
expect_same('708.58', $property_booking['total'], 'The long booking description must preserve Total Payment.');
expect_same(false, str_contains($property_booking['description'], 'Synthetic Lakeside Camp'), 'Policy text must not be inferred as the property.');

$booking_without_property = parse_synthetic_receipt(<<<'RECEIPT'
Firefox print header
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1016
Booking: Cliff Cabin - Weekend Overnight
When: 2/5/2027 7:00 PM - 2/7/2027 11:00 AM
Address: 100 Synthetic Road
RECEIPT);
expect_same('Cliff Cabin - Weekend Overnight (Feb 5-7, 2027)', $booking_without_property['description'], 'A broader property must not be inferred from a facility or postal address.');

$unlabelled_booking = parse_synthetic_receipt(<<<'RECEIPT'
Firefox
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1017
Cliff Cabin - Weekend Overnight (Fri-Sun), 2/5/2027 7:00 PM - 2/7/2027 11:00 AM
Policy effective 2026-01-01 through 2026-01-31.
RECEIPT);
expect_same('Cliff Cabin - Weekend Overnight (Feb 5-7, 2027)', $unlabelled_booking['description'], 'An unlabelled structured facility/event line must be extracted without policy dates.');

$policy_only_dates = parse_synthetic_receipt(<<<'RECEIPT'
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-1018
Policy effective 2026-01-01 through 2026-01-31
RECEIPT);
expect_same('', $policy_only_dates['description'], 'Policy date ranges must not be mistaken for booking descriptions.');

$card_payment_200_text = <<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2001
Subtotal $200.00
Convenience Fee $8.00
Previous Convenience Fee Paid ($8.00)
Total $200.00
...1234 ($208.00)
Balance $0.00
RECEIPT;
$card_payment_200 = parse_synthetic_receipt($card_payment_200_text);
expect_same('208.00', $card_payment_200['total'], 'The final card charge must include the convenience fee and remain positive.');
expect_same('card_charge', $card_payment_200['total_source'], 'The amount provenance must identify a card charge without exposing its suffix.');
expect_same(false, str_contains($card_payment_200['total_source'], '1234'), 'Card suffixes must not be included in amount provenance.');
expect_same('', $card_payment_200['date'], 'Amount extraction must not invent a receipt date.');
expect_same(hash('sha256', $card_payment_200_text), $card_payment_200['text_hash'], 'Amount selection must not alter OCR text.');

$card_payment_500 = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2002
Subtotal $500.00
Convenience Fee $17.00
Previous Convenience Fee Paid ($17.00)
Total $500.00
...1234 ($517.00)
Balance $0.00
RECEIPT);
expect_same('517.00', $card_payment_500['total'], 'A second fee-bearing card charge must outrank the reservation total.');
expect_same('card_charge', $card_payment_500['total_source'], 'The second charge must retain card-charge provenance.');

$explicit_total_payment = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2003
Reservation Item $686.00
Convenience Fee $22.58
Total $686.00
...1234 ($708.58)
Total Payment: $708.58
Balance $0.00
Earlier item payment $686.00
RECEIPT);
expect_same('708.58', $explicit_total_payment['total'], 'Explicit Total Payment must outrank the card line and reservation total.');
expect_same('total_payment', $explicit_total_payment['total_source'], 'Explicit Total Payment must be recorded as the selected source.');

$wrapped_total_payment = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2004
Total Payment
$708.58
Total $686.00
Balance $0.00
RECEIPT);
expect_same('708.58', $wrapped_total_payment['total'], 'A Total Payment label followed by a line break must be recognized.');
expect_same('total_payment', $wrapped_total_payment['total_source'], 'The wrapped Total Payment value must retain provenance.');

$amount_charged_fallback = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2005
Amount Charged: $208.00
Total $200.00
RECEIPT);
expect_same('208.00', $amount_charged_fallback['total'], 'An explicit Amount Charged field is a valid fallback.');
expect_same('amount_charged', $amount_charged_fallback['total_source'], 'Amount Charged provenance must be preserved.');

$payment_amount_fallback = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2006
Payment completed
Payment Amount: $208.00
Total $200.00
RECEIPT);
expect_same('208.00', $payment_amount_fallback['total'], 'Payment Amount requires and accepts completed-payment context.');
expect_same('payment_amount', $payment_amount_fallback['total_source'], 'Payment Amount provenance must be preserved.');

$unconfirmed_payment_amount = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2012
Payment Amount: $208.00
Total $200.00
RECEIPT);
expect_same('200.00', $unconfirmed_payment_amount['total'], 'Payment Amount without completed-payment context must not override Total.');
expect_same('total', $unconfirmed_payment_amount['total_source'], 'Unconfirmed payment amount evidence must fall back to Total.');

$unsafe_scheduled_payment = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2007
Scheduled future Payment Amount: $208.00
Total $200.00
RECEIPT);
expect_same('200.00', $unsafe_scheduled_payment['total'], 'A scheduled future payment must not outrank ordinary Total.');
expect_same('total', $unsafe_scheduled_payment['total_source'], 'The safe fallback must identify Total.');

$policy_payment_example = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2011
Policy example:
Total Payment: $999.99
This amount is explanatory only.
Policy text continues.
Additional policy text.
More policy text.
Further policy text.
Final policy note.
Total $200.00
Balance $0.00
RECEIPT);
expect_same('200.00', $policy_payment_example['total'], 'A Total Payment example outside the balance section must not be treated as a completed charge.');
expect_same('total', $policy_payment_example['total_source'], 'A policy example must not claim Total Payment provenance.');

$malformed_card_fallback = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2008
Total $200.00
...1234 (208.00)
Balance $0.00
RECEIPT);
expect_same('200.00', $malformed_card_fallback['total'], 'A malformed card amount must fall back to ordinary Total.');
expect_same('total', $malformed_card_fallback['total_source'], 'Malformed payment evidence must not be marked as a card charge.');

$unmarked_parenthesized_amount = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2013
Total $200.00
Adjustment ($208.00)
Balance $0.00
RECEIPT);
expect_same('200.00', $unmarked_parenthesized_amount['total'], 'A parenthesized amount without a card marker must not be treated as a charge.');
expect_same('total', $unmarked_parenthesized_amount['total_source'], 'Unmarked parentheses must preserve the ordinary Total fallback.');

$no_total_only_fee = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Booking https://scouts.doubleknot.com
Scouts Canada
Doubleknot LLC
Order Number: SYNTHETIC-2009
Convenience Fee $8.00
Previous Convenience Fee Paid ($8.00)
Balance $0.00
RECEIPT);
expect_same('', $no_total_only_fee['total'], 'A fee, previously paid fee and zero balance must not become the transaction amount.');
expect_same('none', $no_total_only_fee['total_source'], 'No completed charge evidence must be reported as unavailable.');

$generic_parenthesized_amount = parse_synthetic_receipt(<<<'RECEIPT'
Unrelated Vendor
Total $200.00
...1234 ($208.00)
Balance $0.00
RECEIPT);
expect_same('Unrelated Vendor', $generic_parenthesized_amount['merchant'], 'Generic payee extraction must remain unchanged for unrelated receipts.');
expect_same('', $generic_parenthesized_amount['description'], 'Unrelated receipts must retain their existing lack of booking-description extraction.');
expect_same('200.00', $generic_parenthesized_amount['total'], 'A parenthesized card amount must not change generic vendor extraction.');
expect_same('generic', $generic_parenthesized_amount['total_source'], 'Unrelated vendor amount provenance remains generic.');

$scouts_near_miss_amount = parse_synthetic_receipt(<<<'RECEIPT'
Synthetic Scout Receipt
Scouts Canada
Order Number: SYNTHETIC-2010
Total $200.00
...1234 ($208.00)
Balance $0.00
RECEIPT);
expect_same('200.00', $scouts_near_miss_amount['total'], 'Scouts Canada alone must not activate the Doubleknot amount rule.');
expect_same('generic', $scouts_near_miss_amount['total_source'], 'A nonmatching Scouts document retains generic amount provenance.');

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
