<?php
/**
 * Receipt OCR for the expense form. When a receipt is uploaded, reads it with local Tesseract and
 * suggests payee, date, total and payment type. Suggestions stay editable and never overwrite a value
 * the user has entered. Uses the expense form and field mapping from the Expense form settings.
 *
 * Needs: Tesseract, Poppler pdftoppm (for scanned PDFs), PHP proc_open.
 * Poppler pdftotext is optional and avoids OCR for usable text PDFs.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Receipt_OCR
{
    private const ACTION = 'sat_receipt_ocr';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::ACTION, [$this, 'ajax_ocr']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'ajax_ocr']);
    }

    /** Problems that stop OCR from working on this server (shown on the settings tab). */
    public static function server_checks(): array
    {
        $s = SAT_Settings::get('ocr');
        return [
            'Tesseract' => self::command_available($s['tesseract_path']),
            'pdftoppm (PDF receipts)' => self::command_available($s['pdftoppm_path']),
            'pdftotext (optional PDF text)' => self::command_available($s['pdftotext_path']),
            'PHP proc_open' => function_exists('proc_open'),
            'Gravity Forms' => class_exists('GFForms'),
        ];
    }

    public function enqueue(): void
    {
        $s = SAT_Settings::get('ocr');
        $form = SAT_Settings::get('form');
        if ($s['enabled'] !== '1' || !class_exists('GFForms') || empty($form['form_id']) || $form['receipt'] === '') {
            return;
        }
        wp_enqueue_style('sat-receipt-ocr', SAT_URL . 'assets/receipt-ocr.css', [], SAT_VERSION);
        wp_enqueue_script('sat-receipt-ocr', SAT_URL . 'assets/receipt-ocr.js', [], SAT_VERSION, true);
        $card_choice = (string)$s['credit_card_choice'];
        wp_localize_script('sat-receipt-ocr', 'satReceiptOcr', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::ACTION,
            'nonce' => wp_create_nonce(self::ACTION),
            'formId' => (int)$form['form_id'],
            'uploadFieldId' => self::input_id($form['receipt']),
            'payeeFieldId' => self::input_id($form['payee']),
            'descriptionFieldId' => self::input_id($form['description']),
            'dateFieldId' => self::input_id($form['date']),
            'amountFieldId' => self::input_id($form['amount']),
            'paymentFieldId' => (string)$form['payment'],
            'creditCardChoice' => $card_choice,
            'reimbursementChoice' => (string)$s['reimbursement_choice'],
            'maxFiles' => (int)$s['max_files'],
            'messages' => [
                'reading' => 'Reading receipt…',
                'review' => 'Receipt details found. Please check the values before submitting.',
                'none' => 'The receipt could not be read confidently. Please enter the details manually.',
                'error' => 'OCR was unavailable. You can continue by entering the details manually.',
                'multiple' => 'Multiple receipts were selected. Check all details carefully.',
                'card' => ($card_choice !== '' ? $card_choice : 'The group credit card') . ' was detected and selected. Please confirm it is correct.',
                'reimbursement' => 'A personal card was detected, so reimbursement was selected. Please confirm it is correct.',
            ],
        ]);
    }

    /** Gravity Forms input id suffix: field "4" -> "4", sub-input "1.3" -> "1_3". */
    private static function input_id(string $field): string
    {
        return str_replace('.', '_', trim($field));
    }

    public function ajax_ocr(): void
    {
        check_ajax_referer(self::ACTION, 'nonce');
        $s = SAT_Settings::get('ocr');
        if ($s['enabled'] !== '1') {
            wp_send_json_error(['message' => 'OCR is disabled.'], 503);
        }
        if (!$this->allow_request((int)$s['rate_limit'])) {
            wp_send_json_error(['message' => 'Too many OCR requests. Please enter the details manually.'], 429);
        }
        if (empty($_FILES['receipts'])) {
            wp_send_json_error(['message' => 'No receipt was received.'], 400);
        }
        if (!function_exists('proc_open')) {
            wp_send_json_error(['message' => 'The OCR engine is unavailable.'], 503);
        }

        $files = array_slice($this->normalize_files($_FILES['receipts']), 0, (int)$s['max_files']);
        $results = [];
        foreach ($files as $file) {
            try {
                $results[] = $this->process_upload($file, $s);
            } catch (Throwable $e) {
                $this->log('OCR file failed: ' . $e->getMessage(), $s);
                $results[] = ['success' => false, 'merchant' => '', 'date' => '', 'total' => '', 'card_match' => false, 'warning' => 'Could not read this receipt.'];
            }
        }
        wp_send_json_success($this->combine_receipts($results));
    }

    private function combine_receipts(array $results): array
    {
        $ok = array_values(array_filter($results, function ($r) {
            return !empty($r['success']);
        }));
        if (!$ok) {
            return ['success' => false, 'receipts' => $results, 'merchant' => '', 'date' => '', 'total' => '', 'card_detected' => false, 'card_match' => false, 'card_suffix' => '', 'warnings' => ['No receipt was read confidently.']];
        }

        $combined = count($results) > 1;
        $warnings = [];
        $complete_total = count($ok) === count($results);
        foreach ($results as $r) {
            $warnings = array_merge($warnings, $r['warnings'] ?? [], isset($r['warning']) ? [$r['warning']] : []);
            if (($r['total'] ?? '') === '') {
                $complete_total = false;
            }
        }
        if (!$complete_total) {
            $warnings[] = 'A receipt amount could not be read confidently. Please enter the full total manually.';
        }
        if ($combined) {
            $warnings[] = 'Multiple receipts were selected. Confirm the full total and enter a suitable payee and date.';
        }
        $total = 0.0;
        $card_detected = false;
        $card_match = false;
        $suffix = '';
        foreach ($ok as $r) {
            if ($r['total'] !== '') {
                $total += (float)$r['total'];
            }
            if (!empty($r['card_detected'])) {
                $card_detected = true;
                if ($suffix === '' && !empty($r['card_suffix'])) {
                    $suffix = $r['card_suffix'];
                }
            }
            if (!empty($r['card_match'])) {
                $card_match = true;
                $suffix = $r['card_suffix'];
            }
        }
        return [
            'success' => true,
            'receipts' => $results,
            'merchant' => $combined ? '' : $ok[0]['merchant'],
            'description' => $combined ? '' : $ok[0]['description'],
            'date' => $combined ? '' : $ok[0]['date'],
            'total' => $complete_total && $total > 0 ? number_format($total, 2, '.', '') : '',
            'confidence' => $combined ? ['total' => 0.75] : $ok[0]['confidence'],
            'card_detected' => $card_detected,
            'card_match' => $card_match,
            'card_suffix' => $suffix,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function process_upload(array $file, array $s): array
    {
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error ' . (int)$file['error']);
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > (int)$s['max_file_mb'] * 1024 * 1024) {
            throw new RuntimeException('Invalid file size.');
        }
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf']);
        $mime = $check['type'] ?: '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            throw new RuntimeException('Unsupported file type.');
        }
        $tmp = trailingslashit(get_temp_dir()) . 'sat-ocr-' . wp_generate_uuid4();
        if (!wp_mkdir_p($tmp)) {
            throw new RuntimeException('Could not create temporary directory.');
        }
        try {
            $ext = $mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg');
            $input = $tmp . '/input.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $input)) {
                throw new RuntimeException('Could not stage upload.');
            }
            return $this->read_document($input, $mime, $s, $tmp);
        } finally {
            $this->remove_tree($tmp);
        }
    }

    private function read_document(string $input, string $mime, array $s, string $tmp): array
    {
        $warnings = [];
        if ($mime === 'application/pdf') {
            if (self::command_available($s['pdftotext_path'])) {
                try {
                    $textfile = $tmp . '/embedded.txt';
                    $this->run([$s['pdftotext_path'], '-f', '1', '-l', '1', '-layout', '-enc', 'UTF-8', $input, $textfile], 15, $textfile);
                    $text = $this->read_text($textfile);
                    if ($this->usable_pdf_text($text)) {
                        $parsed = $this->parse_receipt($text, $s);
                        if ($parsed['confidence']['total'] >= 0.80) {
                            return $parsed + ['text_source' => 'embedded_pdf', 'warnings' => []];
                        }
                    }
                    $warnings[] = 'PDF text was incomplete or unclear; OCR was used.';
                } catch (RuntimeException $e) {
                    $this->log('PDF text extraction failed: ' . $e->getMessage(), $s);
                    $warnings[] = 'PDF text extraction failed; OCR was used.';
                }
            } else {
                $warnings[] = 'PDF text extraction is unavailable; OCR was used.';
            }
            if (!self::command_available($s['pdftoppm_path'])) {
                throw new RuntimeException('PDF conversion is unavailable.');
            }
            $prefix = $tmp . '/page';
            $this->run([$s['pdftoppm_path'], '-f', '1', '-singlefile', '-r', '200', '-png', $input, $prefix], 30, $prefix . '.png', 16 * 1024 * 1024);
            $input = $prefix . '.png';
            if (!is_file($input)) {
                throw new RuntimeException('PDF conversion failed.');
            }
        }
        if (!self::command_available($s['tesseract_path'])) {
            throw new RuntimeException('The OCR engine is unavailable.');
        }
        $outbase = $tmp . '/ocr';
        $this->run([$s['tesseract_path'], $input, $outbase, '-l', $s['language'], '--psm', '6'], 30, $outbase . '.txt');
        $text = $this->read_text($outbase . '.txt');
        if (trim($text) === '') {
            throw new RuntimeException('No OCR text was returned.');
        }
        $parsed = $this->parse_receipt($text, $s);
        $layout = 'single_block';
        if ($mime !== 'application/pdf' && ($parsed['date'] === '' || $parsed['total'] === '')) {
            try {
                $retry = $tmp . '/ocr-auto';
                $this->run([$s['tesseract_path'], $input, $retry, '-l', $s['language'], '--psm', '1'], 30, $retry . '.txt');
                $alternative = $this->parse_receipt($this->read_text($retry . '.txt'), $s);
                $rank = static fn (array $r): int => ($r['total'] !== '' ? 4 : 0) + ($r['date'] !== '' ? 2 : 0) + ($r['confidence']['merchant'] >= 0.90 ? 1 : 0);
                if ($rank($alternative) > $rank($parsed)
                    && ($parsed['total'] === '' || $alternative['total'] === $parsed['total'])) {
                    $parsed = $alternative;
                    $layout = 'auto_oriented';
                }
            } catch (RuntimeException $e) {
                $this->log('Alternate OCR layout failed: ' . $e->getMessage(), $s);
                $warnings[] = 'The alternate OCR layout was unavailable. Check the suggested details carefully.';
            }
        }
        return $parsed + ['text_source' => 'ocr', 'ocr_layout' => $layout, 'warnings' => $warnings];
    }

    private function read_text(string $path): string
    {
        if (!is_file($path) || filesize($path) > 1024 * 1024) {
            throw new RuntimeException('Receipt text is missing or exceeds the size limit.');
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException('Receipt text could not be read.');
        }
        return $text;
    }

    private function usable_pdf_text(string $text): bool
    {
        return preg_match('//u', $text) === 1
            && substr_count($text, "\u{FFFD}") <= intdiv(strlen($text), 500)
            && preg_match_all('/[\p{L}\p{N}]/u', $text) >= 80
            && preg_match_all('/\p{L}/u', $text) >= 40
            && count(preg_split('/\R/u', trim($text))) >= 3
            && preg_match('/[\x00-\x08\x0B\x0E-\x1F]/', $text) === 0;
    }

    private function parse_receipt(string $text, array $s): array
    {
        $text = wp_check_invalid_utf8($text, true);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), function ($v) {
            return $v !== '';
        }));
        $doubleknot = $this->is_doubleknot_booking_receipt($text);
        $type = $this->classify_document($text);
        $merchant = $doubleknot ? 'Scouts Canada' : $this->parse_merchant($lines, $text, $s);
        $description = $doubleknot ? $this->parse_booking_description($lines) : '';
        $date = $this->parse_date($lines, $text);
        $total = $this->parse_total($lines, $type, $text);
        $card = $this->detect_card($text, $s);
        return [
            'success' => (bool)($merchant || $description || $date['value'] || $total['value'] || $card['detected']),
            'merchant' => $merchant,
            'description' => $description,
            'date' => $date['value'],
            'date_source' => $date['source'],
            'total' => $total['value'],
            'total_source' => $total['source'],
            'document_type' => $type,
            'confidence' => [
                'merchant' => $merchant ? ($doubleknot || $merchant === $this->known_receipt_brand($text) ? 0.95 : 0.78) : 0.0,
                'date' => $date['value'] ? ($date['source'] === 'order_date' ? 0.95 : 0.75) : 0.0,
                'total' => $total['confidence'],
                'payment_type' => $card['detected'] ? 0.90 : 0.0,
            ],
            'card_detected' => $card['detected'],
            'card_match' => $card['match'],
            'card_suffix' => $card['suffix'],
            'text_hash' => hash('sha256', $text),
        ];
    }

    private function card_mappings(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            $suffix = preg_replace('/\D/', '', $parts[0] ?? '');
            if (strlen($suffix) === 4) {
                $out[$suffix] = $parts[1] ?? '';
            }
        }
        return $out;
    }

    private function detect_card(string $text, array $s): array
    {
        $flat = strtolower(preg_replace('/\s+/u', ' ', $text));
        $distance = max(10, min(100, (int)$s['card_context_chars']));
        $keywords = '(?:credit\s*card|card\s*(?:no\.?|number)?|ending\s+in|ends?\s+in|visa|mastercard|master\s*card|amex|american\s+express)';
        $patterns = [
            '/' . $keywords . '.{0,' . $distance . '}?(?:x{2,}|\*{2,}|#?\s*)?(\d{4})\b/i',
            '/(?:x{2,}|\*{2,})(\d{4}).{0,' . $distance . '}?' . $keywords . '/i',
            '/\b(\d{4})\b.{0,' . $distance . '}?' . $keywords . '/i',
        ];
        $candidates = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $flat, $matches)) {
                foreach ($matches[1] as $suffix) {
                    $candidates[] = $suffix;
                }
            }
        }
        $candidates = array_values(array_unique($candidates));
        if (!$candidates) {
            return ['detected' => false, 'match' => false, 'suffix' => ''];
        }
        $known = $this->card_mappings((string)$s['card_mappings']);
        foreach ($candidates as $suffix) {
            if (isset($known[$suffix])) {
                return ['detected' => true, 'match' => true, 'suffix' => $suffix];
            }
        }
        return ['detected' => true, 'match' => false, 'suffix' => $candidates[0]];
    }

    private function classify_document(string $text): string
    {
        $flat = strtolower($text);
        if (preg_match('/\b(order confirmed|order confirmation|thank you for your order|order has been placed)\b/', $flat)) {
            return 'order_confirmation';
        }
        if (preg_match('/\b(invoice|amount due|balance due)\b/', $flat)) {
            return 'invoice';
        }
        if (preg_match('/\b(credit card receipt|merchant copy|terminal id|authorization code)\b/', $flat)) {
            return 'credit_card_slip';
        }
        if (preg_match('/\b(receipt|subtotal|grand total|cashier)\b/', $flat)) {
            return 'receipt';
        }
        return 'unknown';
    }

    private function parse_merchant(array $lines, string $text, array $s): string
    {
        $known = array_change_key_case(SAT_Settings::parse_pairs((string)$s['known_merchants']), CASE_LOWER);
        $hosts = [];
        if (preg_match_all('/https?:\/\/(?:www\.)?([a-z0-9-]+(?:\.[a-z0-9-]+)+)/i', $text, $matches)) {
            foreach ($matches[1] as $hostname) {
                $labels = explode('.', strtolower($hostname));
                foreach ($labels as $label) {
                    if (isset($known[$label])) {
                        return $known[$label];
                    }
                }
                if (!array_intersect($labels, ['google', 'gmail', 'outlook', 'intuit', 'quickbooks', 'paypal', 'stripe', 'square', 'squareup', 'shopify', 'webbrowsertools'])) {
                    $hosts[] = $labels[0];
                }
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:paid\s+to\s*:?\s*|to\s+)(.+?)\s+on\s+\d{1,2}[\/.-]\d{1,2}[\/.-]20\d{2}\b/i', $line, $match)) {
                return sanitize_text_field($match[1]);
            }
            if (preg_match('/^\s*order\s+confirmation\s+from\s+([A-Za-z][A-Za-z0-9 &\'’.-]{2,79})\s*$/i', $line, $match)
                && !$this->is_platform_name($match[1])) {
                return sanitize_text_field(trim($match[1]));
            }
        }

        $domains = [];
        if (preg_match_all('/(?:https?:\/\/|www\.|@)([a-z0-9.-]+\.[a-z]{2,})/i', $text, $matches)) {
            $domains = array_map('strtolower', $matches[1]);
        }
        foreach ($known as $website => $payee) {
            foreach ($domains as $domain) {
                if (in_array($website, explode('.', $domain), true)) {
                    return $payee;
                }
            }
        }
        foreach ([
            'amazon.ca' => 'Amazon',
            'amazon.com' => 'Amazon',
            'scouts.ca' => 'Scouts Canada',
            'mec.ca' => 'MEC',
            'voila.ca' => 'Voila',
            'canadiantire.ca' => 'Canadian Tire',
            'canadianoutdoorequipment.com' => 'The Canadian Outdoor Equipment Co.',
            'mybackcheck.com' => 'My BackCheck',
            'avery.ca' => 'Avery',
        ] as $website => $payee) {
            foreach ($domains as $domain) {
                if ($domain === $website || str_ends_with($domain, '.' . $website)) {
                    return $payee;
                }
            }
        }

        $header = implode("\n", array_slice($lines, 0, 15));
        $brand = $this->known_receipt_brand($text);
        if ($brand !== '') {
            return $brand;
        }
        if (preg_match('/(?:^|[|\-])\s*([A-Z][A-Za-z0-9 &\'’.-]{2,60})\s*(?:https?:\/\/|www\.)/m', $header, $match)) {
            $candidate = trim(preg_replace('/\s*-\s*Account\s*$/i', '', $match[1]));
            if (!$this->is_platform_name($candidate)
                && !preg_match('/\b(firefox|chrome|gmail|outlook|print|receipt|invoice)\b/i', $candidate)) {
                return sanitize_text_field($candidate);
            }
        }
        foreach (array_slice($lines, 0, 12) as $line) {
            if (preg_match('/^(.{3,80}?)\s*<\s*[^<>@\s]+@[^<>@\s]+\s*>$/', $line, $match)
                && !$this->is_platform_name($match[1])
                && !preg_match('/\b(order|receipt|invoice|confirmed|gmail|outlook|firefox|chrome)\b|^(?:to|from)\b/i', $match[1])) {
                return sanitize_text_field(trim($match[1]));
            }
            if (preg_match('/^([A-Za-z][A-Za-z &\'’.-]{2,79}?)\s+(?:via\s+Square\s+)?[^\s<>@]+@[^\s<>@]+\.[a-z]{2,}\s+(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)\b/i', $line, $match)
                && !$this->is_platform_name($match[1])) {
                return sanitize_text_field(trim($match[1]));
            }
        }
        if ($hosts) {
            return sanitize_text_field(ucwords(str_replace('-', ' ', $hosts[0])));
        }
        $reject = '/\b(order confirmed|confirmation|thank you|receipt|invoice|transaction|merchant|terminal|cashier|customer|copy|visa|mastercard|interac|approved|subtotal|total|tax|hst|gst|expected ship date|track your order|firefox|chrome|safari|outlook|gmail)\b/i';
        foreach (array_slice($lines, 0, 20) as $line) {
            $clean = trim(preg_replace('/\s{2,}/', ' ', $line));
            $clean = trim(preg_replace('/\s+INVOICE\s*#.*$/i', '', $clean));
            if (strlen($clean) < 3 || strlen($clean) > 80 || $this->is_platform_name($clean) || preg_match($reject, $clean)
                || preg_match('/https?:\/\/|www\.|@|[<>]|^\d(?!\d*(?:st|nd|rd|th)\b)|^(?:from|to|sent|subject|date|tel|phone|fax|bill\s+to|ship\s+to)\s*:/i', $clean)
                || preg_match('/^(?:paid|payment|orders?|account|shipping|billing|delivery|your|you|view|print|page|powered\s+by|purchased\s+by|purchaser|vendor\/supplier|recipient|received\s+from|billed\s+to|mail|to|thanks|enjoy|discover|free\s+shipping|begin\s+processing|save\s+this|we.re|ship\s+to|quantity|tip)\b/i', $clean)
                || preg_match('/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:day)?\b|^(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d/i', $clean)) {
                continue;
            }
            if (preg_match('/[A-Za-z]{3}/', $clean) && !preg_match('/^\d+[\d\s\-().]*$/', $clean)) {
                return sanitize_text_field($clean);
            }
        }
        return '';
    }

    private function is_platform_name(string $name): bool
    {
        return preg_match('/^\s*(?:intuit|quickbooks|paypal|stripe|square|squareup|shopify)(?:\s+payments?)?\s*$/i', $name) === 1;
    }

    private function known_receipt_brand(string $text): string
    {
        $header = implode("\n", array_slice(preg_split('/\R/u', $text), 0, 20));
        if (preg_match('/^\W*D\s*O\s*L\s*L\s*A\s*R\s*A\s*M\s*A(?:\s*(?:#?\s*\d+|\*+))?\s*$/im', $header)) {
            return 'Dollarama';
        }
        if (preg_match('/^\s*CANAD[IT]AN\s+T[IT]RE(?:\s*#[^\r\n]*)?\s*$/im', $header)) {
            return 'Canadian Tire';
        }
        if (preg_match('/^\s*(?:THE\s+)?DOCK\s+SHOPPE\s*[|.,]*\s*$/im', $header)) {
            return 'The Dock Shoppe';
        }
        if (preg_match('/^\s*BULK\s*BARN[\'’]*\s*$/im', $header)) {
            return 'Bulk Barn';
        }
        if (preg_match('/^\s*KAWARTHA\s+DAIRY(?:\s+LIMITED)?\s*$/im', $header)) {
            return 'Kawartha Dairy';
        }
        if (preg_match('/^\s*TORONTO\s+ISLAND\s+MARINA\s*[|.,]*\s*$/im', $header)) {
            return 'Toronto Island Marina';
        }
        return '';
    }

    private function parse_booking_description(array $lines): string
    {
        $table_description = $this->parse_booking_table_description($lines);
        if ($table_description !== '') {
            return $table_description;
        }

        $booking_index = null;
        $booking = '';
        $property_index = null;
        $property = '';
        foreach ($lines as $index => $line) {
            if (preg_match('/^(?:property|camp(?:\s+name)?)\s*:\s*(.+)$/i', $line, $match)) {
                $property_index = $index;
                $property = $this->clean_booking_name($match[1]);
            }
            if ($booking_index === null && preg_match('/^(?:booking(?:\s+description)?|facility(?:\s+booking)?)\s*:\s*(.+)$/i', $line, $match)) {
                $booking_index = $index;
                $booking = $match[1];
            }
        }

        $dates = '';
        if ($booking_index !== null) {
            $dates = $this->format_booking_date_range($booking);
            if ($dates === '') {
                for ($index = $booking_index + 1; $index <= min(count($lines) - 1, $booking_index + 3); $index++) {
                    if (preg_match('/^(?:dates?|when|event\s+dates?)\s*:/i', $lines[$index])) {
                        $dates = $this->format_booking_date_range($lines[$index]);
                        if ($dates !== '') {
                            break;
                        }
                    }
                }
            }
        } else {
            foreach ($lines as $index => $line) {
                if (preg_match('/^(?:dates?|when|event\s+dates?|arrival|departure|email|sent|printed|print|generated|copyright|policy|cancellation|terms|page|payment|balance)\b/i', $line)
                    || preg_match('/^https?:\/\//i', $line)) {
                    continue;
                }
                $range = $this->format_booking_date_range($line);
                if ($range === '') {
                    continue;
                }
                $booking_index = $index;
                $booking = preg_replace('/(?:,?\s*)' . $this->booking_date_pattern() . '.*/i', '', $line);
                $dates = $range;
                break;
            }
        }
        if ($booking_index === null) {
            return '';
        }

        $booking = preg_replace('/(?:,?\s*)' . $this->booking_date_pattern() . '.*/i', '', $booking);
        $booking = preg_replace('/^(?:booking(?:\s+description)?|facility(?:\s+booking)?)\s*:\s*/i', '', $booking);
        $booking = $this->clean_booking_name($booking);
        if ($booking === '') {
            return '';
        }
        if ($property !== '' && ($property_index === null || abs($property_index - $booking_index) > 3)) {
            $property = '';
        }
        if ($property !== '' && (
            strcasecmp($property, $booking) === 0
            || stripos($booking, $property . ' - ') === 0
        )) {
            $property = '';
        }
        if ($property !== '') {
            $booking = $property . ' - ' . $booking;
        }
        return sanitize_text_field($booking . ($dates !== '' ? ' (' . $dates . ')' : ''));
    }

    private function parse_booking_table_description(array $lines): string
    {
        $header_index = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*description\s+amount\b/i', $line)) {
                $header_index = $index;
                break;
            }
        }
        if ($header_index === null) {
            return '';
        }

        $end = min(count($lines) - 1, $header_index + 30);
        for ($date_index = $header_index + 1; $date_index <= $end; $date_index++) {
            if (preg_match('/^\s*(?:order\s+date|total(?:\s+payment)?|balance|previous\s+convenience\s+fee|policy|terms|https?:\/\/|www\.)\b/i', $lines[$date_index])) {
                break;
            }
            $date_context = implode(' ', array_slice($lines, $date_index, 3));
            $dates = $this->format_booking_date_range($date_context);
            if ($dates === '') {
                continue;
            }

            $date_start_index = $date_index;
            for ($index = $date_index; $index < min(count($lines), $date_index + 3); $index++) {
                if (preg_match('/' . $this->booking_date_pattern() . '/i', $lines[$index])) {
                    $date_start_index = $index;
                    break;
                }
            }
            $booking_lines = [];
            $property = '';
            $property_index = null;
            $preceding_lines = array_slice($lines, $header_index + 1, $date_start_index - $header_index - 1, true);
            foreach ($preceding_lines as $index => $line) {
                if (preg_match('/^(?:property|camp(?:\s+name)?)\s*:\s*(.+)$/i', $line, $match)) {
                    $property_index = $index;
                    $property = $this->clean_booking_name($match[1]);
                    continue;
                }
                if (preg_match('/^(?:description|amount|qty|quantity|participants?|booking\s+id)\b/i', $line)
                    || preg_match('/^https?:\/\/|^www\.|@/i', $line)) {
                    continue;
                }
                $line = preg_replace('/^(?:booking(?:\s+description)?|facility(?:\s+booking)?)\s*:\s*/i', '', $line);
                $clean = $this->clean_booking_name($line);
                if ($clean !== '') {
                    $booking_lines[] = ['index' => $index, 'text' => $clean];
                }
            }
            if (!$booking_lines) {
                continue;
            }

            $booking = $this->clean_booking_name(implode(' ', array_column($booking_lines, 'text')));
            if ($booking === '') {
                continue;
            }
            $booking_index = $booking_lines[0]['index'];
            if ($property !== '' && ($property_index === null || abs($property_index - $booking_index) > 3)) {
                $property = '';
            }
            if ($property !== '' && (
                strcasecmp($property, $booking) === 0
                || stripos($booking, $property . ' - ') === 0
            )) {
                $property = '';
            }
            if ($property !== '') {
                $booking = $property . ' - ' . $booking;
            }
            return sanitize_text_field($booking . ' (' . $dates . ')');
        }
        return '';
    }

    private function clean_booking_name(string $value): string
    {
        $value = preg_replace('/https?:\/\/\S+|www\.\S+/i', '', $value);
        $value = preg_replace('/\b(?:booking|item)\s*(?:id|number|#)\s*[:#]?\s*[A-Z0-9-]+\b/i', '', $value);
        $value = preg_replace('/\b\d{4,}\b/', '', $value);
        $value = preg_replace('/\b(?:qty|quantity|participants?|people|attendees|persons|scouts)\s*[:x]?\s*\d+\b|\b\d+\s*(?:participants?|people|attendees|persons|scouts)\b/i', '', $value);
        $value = preg_replace('/\$\s*\d[\d,]*(?:\.\d{2})?/', '', $value);
        $value = preg_replace('/\b\d[\d,]*\.\d{2}\b/', '', $value);
        $value = preg_replace('/\bweek\s+\d+\b/i', '', $value);
        $value = preg_replace('/\((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:day)?\s*-\s*(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:day)?\)/i', '', $value);
        $value = preg_replace('/[,;|]\s*[,;|]+/', ' ', $value);
        $value = preg_replace('/\s*[,;|]\s*$/', '', $value);
        $value = preg_replace('/\s*-\s*(?:\s*-\s*)+/', ' - ', $value);
        $value = preg_replace('/^[\s#\-\d]+|[\s,;|\-]+$/', '', $value);
        return trim(preg_replace('/\s{2,}/', ' ', $value));
    }

    private function booking_date_pattern(): string
    {
        return '(?:(?:0?[1-9]|1[0-2])\/(?:0?[1-9]|[12]\d|3[01])\/20\d{2}|20\d{2}-(?:0?[1-9]|1[0-2])-(?:0?[1-9]|[12]\d|3[01]))';
    }

    private function format_booking_date_range(string $text): string
    {
        $pattern = '/(?<!\d)(' . $this->booking_date_pattern() . ')(?!\d)/';
        if (!preg_match_all($pattern, $text, $matches) || count($matches[1]) < 2) {
            return '';
        }
        $start = $this->parse_booking_date($matches[1][0]);
        $end = $this->parse_booking_date($matches[1][1]);
        if (!$start || !$end || $end < $start) {
            return '';
        }
        if ($start->format('Y') !== $end->format('Y')) {
            return $start->format('M j, Y') . '-' . $end->format('M j, Y');
        }
        if ($start->format('n') === $end->format('n')) {
            return $start->format('M j-') . $end->format('j, Y');
        }
        return $start->format('M j-') . $end->format('M j, Y');
    }

    private function parse_booking_date(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^(20\d{2})-(\d{1,2})-(\d{1,2})$/', $value, $match)) {
            $date = DateTimeImmutable::createFromFormat('!Y-n-j', $match[1] . '-' . $match[2] . '-' . $match[3]);
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(20\d{2})$/', $value, $match)) {
            $date = DateTimeImmutable::createFromFormat('!n/j/Y', $match[1] . '/' . $match[2] . '/' . $match[3]);
        } else {
            return null;
        }
        $errors = DateTimeImmutable::getLastErrors();
        return $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date
            : null;
    }

    private function parse_total(array $lines, string $type, string $text): array
    {
        if ($this->is_doubleknot_booking_receipt($text)) {
            $amount = $this->parse_labelled_amount($lines, 'total\\s+payment', false, true);
            if ($amount !== null) {
                return ['value' => number_format($amount, 2, '.', ''), 'confidence' => 0.99, 'source' => 'total_payment'];
            }

            $amount = $this->parse_doubleknot_card_charge($lines);
            if ($amount !== null) {
                return ['value' => number_format($amount, 2, '.', ''), 'confidence' => 0.98, 'source' => 'card_charge'];
            }

            foreach ([
                ['amount\\s+charged', 'amount_charged'],
                ['payment\\s+amount', 'payment_amount'],
            ] as [$label, $source]) {
                $amount = $this->parse_labelled_amount($lines, $label, $source === 'payment_amount');
                if ($amount !== null) {
                    return ['value' => number_format($amount, 2, '.', ''), 'confidence' => 0.95, 'source' => $source];
                }
            }

            foreach ([
                ['total', 'total'],
                ['order\\s+total', 'order_total'],
            ] as [$label, $source]) {
                $amount = $this->parse_labelled_amount($lines, $label);
                if ($amount !== null) {
                    return ['value' => number_format($amount, 2, '.', ''), 'confidence' => 0.92, 'source' => $source];
                }
            }
            return ['value' => '', 'confidence' => 0.0, 'source' => 'none'];
        }

        $best = null;
        $best_score = -999;
        $positive = [
            'charged' => 120, 'amount paid' => 115, 'payment total' => 110, 'grand total' => 105,
            'order total' => 100, 'amount due' => 95, 'balance due' => 95, 'total due' => 95,
            'total' => 80, 'order summary' => 80, 'amount' => 60, 'payment' => 60,
        ];
        foreach ($lines as $index => $line) {
            if (preg_match('/^(?:grand\s+total|total(?:\s+(?:due|paid))?|amount\s+(?:paid|due|charged)|balance\s+due)\s*:?\s*$/i', $line)
                && preg_match('/^(?:(?:CAD|CA|USD)\s*)?\$?\s*\d[\d,. ]*(?:\s*(?:CAD|USD))?$/i', $lines[$index + 1] ?? '')) {
                $line .= ' ' . $lines[$index + 1];
            }
            if (preg_match('/^((?:grand\s+total|total(?:\s+(?:due|paid))?|amount\s+(?:paid|due|charged)|balance\s+due)\s*:?\s*(?:(?:CAD|CA|USD)\s*)?\$?\s*)(\d{1,6})(\s*(?:CAD|USD)?)$/i', $line, $whole)) {
                $line = $whole[1] . $whole[2] . '.00' . $whole[3];
            }
            $normalized = preg_replace('/(?<=\.\d)\s+(?=\d(?!\d))/', '', $line);
            $normalized = preg_replace('/(?<=\d)\s+(?=\d{3}(?:[,.]\d{2})(?!\d))/', '', $normalized);
            if (preg_match('/\b\d{1,3}(?:\.\d{1,3}){2,}\b/', $normalized)) {
                continue;
            }
            if (!preg_match_all('/(?<![\d.])(?:CAD\s*)?\$?\s*(\d{1,6}(?:,\d{3})*(?:\.\d{2})|\d{1,6}(?:\.\d{3})*,\d{2})(?![\d.])/i', $normalized, $matches)) {
                continue;
            }
            foreach ($matches[1] as $raw) {
                $value = $this->money_value($raw);
                if ($value <= 0 || $value > 100000) {
                    continue;
                }
                $lower = strtolower($normalized);
                $score = $index / max(1, count($lines));
                foreach ($positive as $phrase => $bonus) {
                    if (stripos($lower, $phrase) !== false) {
                        $score += $bonus;
                    }
                }
                if ($type === 'order_confirmation' && preg_match('/\b(charged|amount paid|order total)\b/i', $normalized)) {
                    $score += 30;
                }
                if ($type === 'invoice' && preg_match('/\b(amount due|balance due|total due)\b/i', $normalized)) {
                    $score += 30;
                }
                if (preg_match('/\b(subtotal|sub-total|tax|hst|gst|pst|qst|change|cash|tender|savings?|discount|shipping)\b/i', $normalized)) {
                    $score -= 90;
                }
                if (preg_match('/\b(unit price|price each|qty|quantity|sku|item|per unit)\b/i', $normalized)) {
                    $score -= 140;
                }
                if (preg_match('/(?:https?:\/\/|www\.|\b(?:ip|page)\b)/i', $normalized)) {
                    $score -= 200;
                }
                if ($score > $best_score) {
                    $best_score = $score;
                    $best = $value;
                }
            }
        }
        if ($best === null || $best_score < 50) {
            return ['value' => '', 'confidence' => 0.0, 'source' => 'none'];
        }
        $confidence = $best_score >= 100 ? 0.97 : ($best_score >= 80 ? 0.92 : ($best_score >= 50 ? 0.80 : 0.55));
        return ['value' => number_format($best, 2, '.', ''), 'confidence' => $confidence, 'source' => 'generic'];
    }

    private function parse_labelled_amount(
        array $lines,
        string $label,
        bool $require_completed_context = false,
        bool $require_balance_context = false
    ): ?float {
        $number = '(?:\\d{1,6}(?:,\\d{3})*(?:\\.\\d{2})|\\d{1,6}(?:\\.\\d{3})*,\\d{2})';
        foreach ($lines as $index => $line) {
            if (!preg_match('/^\\s*' . $label . '\\s*:?\\s*(.*)$/i', $line, $match)) {
                continue;
            }
            $raw = trim($match[1]);
            if ($raw === '') {
                $raw = trim((string)($lines[$index + 1] ?? ''));
            }
            if (!preg_match('/^(?:CAD\\s*)?\\$?\\s*(' . $number . ')\\s*$/i', $raw, $value)) {
                continue;
            }
            if ($require_balance_context && !$this->has_nearby_balance($lines, $index)) {
                continue;
            }
            if ($require_completed_context) {
                $context = implode(' ', array_slice($lines, max(0, $index - 2), 5));
                if (!preg_match('/\\b(?:completed|charged|paid|processed|posted|transaction)\\b/i', $context)
                    || preg_match('/\\b(?:scheduled|upcoming|future|balance\\s+owing|due\\s+date)\\b/i', $context)) {
                    continue;
                }
            }
            $amount = $this->money_value($value[1]);
            if ($amount > 0 && $amount <= 100000) {
                return $amount;
            }
        }
        return null;
    }

    private function has_nearby_balance(array $lines, int $index): bool
    {
        $start = max(0, $index - 6);
        $end = min(count($lines) - 1, $index + 6);
        for ($candidate = $start; $candidate <= $end; $candidate++) {
            if (preg_match('/^\\s*balance\\b/i', $lines[$candidate])) {
                return true;
            }
        }
        return false;
    }

    private function parse_doubleknot_card_charge(array $lines): ?float
    {
        $card_marker = '/(?:\\.{2,}|[*xX]{2,})\\s*\\d{4}\\b|\\b(?:visa|master\\s*card|mastercard|amex|american\\s+express|credit\\s+card|debit\\s+card)\\b.{0,40}\\b\\d{4}\\b/i';
        $charge = '/\\(\\s*(?:CAD\\s*)?\\$\\s*(\\d{1,6}(?:,\\d{3})*(?:\\.\\d{2})|\\d{1,6}(?:\\.\\d{3})*,\\d{2})\\s*\\)/i';

        $balance_found = false;
        for ($balance_index = count($lines) - 1; $balance_index >= 0; $balance_index--) {
            if (!preg_match('/^\\s*balance\\b/i', $lines[$balance_index])) {
                continue;
            }
            $balance_found = true;
            for ($line_index = $balance_index - 1; $line_index >= max(0, $balance_index - 3); $line_index--) {
                $line = $lines[$line_index];
                if (!preg_match($card_marker, $line) || !preg_match($charge, $line, $match)) {
                    continue;
                }
                $amount = $this->money_value($match[1]);
                if ($amount > 0 && $amount <= 100000) {
                    return $amount;
                }
            }
        }

        if (!$balance_found) {
            for ($line_index = count($lines) - 1; $line_index >= 0; $line_index--) {
                $line = $lines[$line_index];
                if (preg_match('/\\b(?:previous|refund|reversal|void|scheduled|example)\\b/i', $line)
                    || !preg_match($card_marker, $line)
                    || !preg_match($charge, $line, $match)) {
                    continue;
                }
                $amount = $this->money_value($match[1]);
                if ($amount > 0 && $amount <= 100000) {
                    return $amount;
                }
            }
        }
        return null;
    }

    private function money_value(string $raw): float
    {
        $raw = preg_replace('/\s+/', '', trim($raw));
        if (strpos($raw, ',') !== false && strpos($raw, '.') !== false) {
            if (strrpos($raw, '.') > strrpos($raw, ',')) {
                $raw = str_replace(',', '', $raw);
            } else {
                $raw = str_replace(',', '.', str_replace('.', '', $raw));
            }
        } elseif (strpos($raw, ',') !== false) {
            $raw = str_replace(',', '.', $raw);
        }
        return is_numeric($raw) ? (float)$raw : 0.0;
    }

    private function parse_date(array $lines, string $text): array
    {
        if ($this->is_doubleknot_booking_receipt($text)) {
            foreach ([
                ['order\\s+date', 'order_date'],
                ['(?:date\\s+paid|paid\\s+on|payment\\s+(?:received|completed|processed)(?:\\s+(?:on|date))?)', 'payment_date'],
                ['(?:email\\s+sent|sent(?:\\s+(?:date|time))?)', 'email_timestamp'],
                ['(?:printed(?:\\s+on)?|print\\s+date|generated(?:\\s+on)?|document\\s+date)', 'document_timestamp'],
            ] as [$label, $source]) {
                $date = $this->parse_labelled_date($text, $label, true);
                if ($date !== '') {
                    return ['value' => $date, 'source' => $source];
                }
            }
            return ['value' => '', 'source' => 'none'];
        }

        $labelled = $this->parse_labelled_date($text, '(?:order\\s+(?:date|placed)|invoice\\s+date|purchase\\s+date|transaction\\s+date)');
        return ['value' => $labelled !== '' ? $labelled : $this->parse_generic_date($lines), 'source' => $labelled !== '' ? 'transaction_date' : 'generic'];
    }

    private function is_doubleknot_booking_receipt(string $text): bool
    {
        return preg_match('/\\bscouts\\s+canada\\b/i', $text) === 1
            && preg_match('/\\bdoubleknot(?:\\s+llc)?\\b/i', $text) === 1
            && preg_match('/\\border\\s+number\\s*:/i', $text) === 1;
    }

    private function parse_labelled_date(string $text, string $label, bool $month_first = false): string
    {
        if (!preg_match_all('/\\b' . $label . '\\b\\s*:?\\s*/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $numeric = '/^((?:20\\d{2}\\s*[-\\/.]\\s*\\d{1,2}\\s*[-\\/.]\\s*\\d{1,2})|(?:\\d{1,2}\\s*[-\\/.]\\s*\\d{1,2}\\s*[-\\/.]\\s*20\\d{2}))/';
        $words = '/^((?:(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)\\s*,?\\s*)?(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\\s+\\d{1,2},?\\s+20\\d{2})\\b/i';
        foreach ($matches[0] as $match) {
            $remaining = ltrim(substr($text, $match[1] + strlen($match[0])));
            if (!preg_match($numeric, $remaining, $value) && !preg_match($words, $remaining, $value)) {
                continue;
            }
            $candidate = trim($value[1]);
            if (preg_match('/^(\\d{1,2})\\s*([\\/.\\-])\\s*(\\d{1,2})\\s*\\2\\s*(20\\d{2})$/', $candidate, $parts)) {
                $first = (int)$parts[1];
                $second = (int)$parts[3];
                if ($first > 12 || (!$month_first && $first <= 12 && $second <= 12)) {
                    $date = $this->parse_generic_date([$candidate]);
                    if ($date !== '') {
                        return $date;
                    }
                    continue;
                }
                $year = (int)$parts[4];
                if (checkdate($first, $second, $year)) {
                    return sprintf('%02d-%02d-%04d', $second, $first, $year);
                }
                continue;
            }
            $date = $this->parse_generic_date([$candidate]);
            if ($date !== '') {
                return $date;
            }
        }
        return '';
    }

    private function parse_generic_date(array $lines): string
    {
        $iso = '/\b(20\d{2})[-\/.](0?[1-9]|1[0-2])[-\/.](0?[1-9]|[12]\d|3[01])\b/';
        $numeric = '/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](20\d{2}|\d{2})\b/';
        $words = '/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2}),?\s+(20\d{2})\b/i';
        $iso_dates = [];
        foreach ($lines as $line) {
            if (preg_match($iso, $line, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                $iso_dates[] = sprintf('%02d-%02d-%04d', $m[3], $m[2], $m[1]);
            }
        }
        foreach ($lines as $line) {
            if (preg_match($iso, $line, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return sprintf('%02d-%02d-%04d', $m[3], $m[2], $m[1]);
            }
            if (preg_match($numeric, $line, $m)) {
                $first = (int)$m[1];
                $second = (int)$m[2];
                $year = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
                $dmy = checkdate($second, $first, $year) ? sprintf('%02d-%02d-%04d', $first, $second, $year) : '';
                $mdy = checkdate($first, $second, $year) ? sprintf('%02d-%02d-%04d', $second, $first, $year) : '';
                if (strlen($m[3]) === 2) {
                    $ymd = checkdate($second, (int)$m[3], 2000 + $first) ? sprintf('%02d-%02d-%04d', (int)$m[3], $second, 2000 + $first) : '';
                    foreach ([$ymd, $dmy, $mdy] as $candidate) {
                        if ($candidate !== '' && in_array($candidate, $iso_dates, true)) {
                            return $candidate;
                        }
                    }
                    continue;
                }
                if ($mdy !== '' && in_array($mdy, $iso_dates, true) && !in_array($dmy, $iso_dates, true)) {
                    return $mdy;
                }
                if ($dmy !== '' || $mdy !== '') {
                    return $dmy !== '' ? $dmy : $mdy;
                }
            }
            if (preg_match($words, $line, $m)) {
                $dt = DateTime::createFromFormat('!M j Y', substr($m[1], 0, 3) . ' ' . $m[2] . ' ' . $m[3]);
                $errors = DateTime::getLastErrors();
                if ($dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $dt->format('d-m-Y');
                }
            }
            if (preg_match('/\b(\d{1,2})[- ](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[- ](20\d{2}|\d{2})\b/i', $line, $m)) {
                $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
                $dt = DateTime::createFromFormat('!j M Y', $m[1] . ' ' . $m[2] . ' ' . $year);
                $errors = DateTime::getLastErrors();
                if ($dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $dt->format('d-m-Y');
                }
            }
        }
        return '';
    }

    private function normalize_files(array $files): array
    {
        if (!is_array($files['name'])) {
            return [$files];
        }
        $out = [];
        foreach ($files['name'] as $i => $name) {
            $out[] = [
                'name' => $name,
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }
        return $out;
    }

    private function allow_request(int $limit): bool
    {
        $key = 'sat_ocr_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $count = (int)get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private static function command_available(string $path): bool
    {
        return $path !== '' && is_file($path) && is_executable($path);
    }

    private function run(array $args, int $timeout, ?string $output = null, int $max_bytes = 1048576): void
    {
        $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'OMP_THREAD_LIMIT' => '1']);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start OCR process.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $start = microtime(true);
        try {
            do {
                stream_get_contents($pipes[1], 65536);
                stream_get_contents($pipes[2], 65536);
                $status = proc_get_status($process);
                if ($output !== null) {
                    clearstatcache(true, $output);
                    if (is_file($output) && filesize($output) > $max_bytes) {
                        throw new RuntimeException('Receipt command output exceeds the size limit.');
                    }
                }
                if (microtime(true) - $start > $timeout) {
                    throw new RuntimeException('Receipt command timed out.');
                }
                if ($status['running']) {
                    usleep(100000);
                }
            } while ($status['running']);
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('Receipt command failed with exit code ' . $status['exitcode'] . '.');
            }
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }

    private function remove_tree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->remove_tree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function log(string $message, array $s): void
    {
        if ($s['debug_log'] === '1') {
            error_log('[Scout Accounting OCR] ' . $message);
        }
    }
}
