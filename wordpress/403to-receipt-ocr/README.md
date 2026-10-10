# 403TO Receipt OCR 1.2.1

### Changes in 1.2.1

- Fixes reimbursement selection for unrecognized cards.
- Returns card_detected and card_suffix at the top level of the AJAX response.
- Uses receipt-level card data as a fallback.
- Displays detected card suffixes in payment-method messages.
- Adds logged-in WordPress user prefill for submitter name and email.
- Supports Populate, Populate and Read Only, and Populate and Hide modes.

## Changes in 1.2

- Supports the Gravity Forms Plupload multi-file field using a capture-phase file selection listener.
- Suggests payee, date, amount, and payment type while leaving all values editable.
- Adds configurable payment-type radio field, group-card choice, and reimbursement choice.
- Adds configurable recognized-card suffix mappings. Defaults: 2445, 5084, and 3556, based on the current 403TO card list.
- Selects `Charge on 403TO credit card` when a recognized suffix appears near clear card language.
- Selects the configured reimbursement option when clear card language and a last-four suffix are found, but the suffix is not recognized.
- Never overrides a payment option the user already selected.
- Classifies receipts as receipt, invoice, order confirmation, credit-card slip, or unknown for parsing purposes.
- Gives strong priority to amounts labelled `Charged`, `Amount Paid`, `Grand Total`, `Order Total`, `Amount Due`, `Balance Due`, or `Total Due`.
- Strongly penalizes amounts near `Subtotal`, taxes, shipping, discounts, unit prices, quantities, SKUs, URLs, page metadata, and IP-address-like strings.
- Supports thousands separators such as `$3,983.62`.
- Improves merchant extraction from document titles and website domains. Includes normalization for T-Shirt Elephant.
- Does not automatically select an accounting category.
- Raw OCR text is not stored.

## Default Gravity Forms mapping

- Form: 9
- Receipt upload: 7
- Payee: 4
- Payment type: 6
- Date: 8
- Total: 9
- Group-card option: `Charge on 403TO credit card`
- Reimbursement option: `reimbursement of Scouter (please avoid this)`

Confirm the exact radio values or labels at Settings > 403TO Receipt OCR. The matching is case-insensitive but otherwise exact.

## Upgrade and test

1. Back up the existing plugin directory and database.
2. Upload this ZIP in WordPress. If WordPress does not offer to replace the installed plugin, deactivate and delete the older plugin first, then upload this ZIP.
3. Activate 403TO Receipt OCR.
4. Open Settings > 403TO Receipt OCR and confirm all field IDs and payment labels.
5. Confirm the recognized card mappings. Update the suffixes when cards change.
6. Clear page/plugin caches and force-refresh the form.
7. Test:
   - a recognized 403TO card;
   - an unrecognized personal card;
   - a receipt with no card details;
   - the T-Shirt Elephant order confirmation;
   - a grocery receipt with subtotal, tax, and total;
   - an invoice with amount due;
   - values manually entered before selecting a file.
8. Confirm all OCR suggestions remain editable and that an existing payment selection is never overwritten.

## Server requirements

- WordPress 6.0+
- PHP 7.4+
- Gravity Forms
- PHP `proc_open`
- Tesseract
- Poppler `pdftoppm` for PDF first-page conversion

Example Debian/Ubuntu packages:

    sudo apt install tesseract-ocr poppler-utils

## Validation

    php -l 403to-receipt-ocr/403to-receipt-ocr.php

OCR is heuristic. Users must verify the payee, date, total, and payment type before submitting.
