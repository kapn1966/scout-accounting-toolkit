<?php
/**
 * Receipt OCR for the expense form. When a receipt is uploaded, reads it with local Tesseract and
 * suggests payee, date, total and payment type. Suggestions stay editable and never overwrite a value
 * the user has entered. Uses the expense form and field mapping from the Expense form settings.
 *
 * Needs: Tesseract, Poppler pdftoppm (for PDFs), PHP proc_open.
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
                'multiple' => 'Multiple receipts were read. The total is their combined amount; check all details carefully.',
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
        if (!function_exists('proc_open') || !self::command_available($s['tesseract_path'])) {
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

        $ok = array_values(array_filter($results, function ($r) {
            return !empty($r['success']);
        }));
        if (!$ok) {
            wp_send_json_success(['success' => false, 'receipts' => $results, 'merchant' => '', 'date' => '', 'total' => '', 'card_detected' => false, 'card_match' => false, 'card_suffix' => '', 'warnings' => ['No receipt was read confidently.']]);
        }

        $combined = count($ok) > 1;
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
        wp_send_json_success([
            'success' => true,
            'receipts' => $results,
            'merchant' => $combined ? '' : $ok[0]['merchant'],
            'date' => $combined ? '' : $ok[0]['date'],
            'total' => $total > 0 ? number_format($total, 2, '.', '') : '',
            'confidence' => $combined ? ['total' => 0.75] : $ok[0]['confidence'],
            'card_detected' => $card_detected,
            'card_match' => $card_match,
            'card_suffix' => $suffix,
            'warnings' => $combined ? ['Multiple receipts were combined. Confirm the total and enter a suitable payee and date.'] : [],
        ]);
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
            if ($mime === 'application/pdf') {
                if (!self::command_available($s['pdftoppm_path'])) {
                    throw new RuntimeException('PDF conversion is unavailable.');
                }
                $prefix = $tmp . '/page';
                $this->run([$s['pdftoppm_path'], '-f', '1', '-singlefile', '-r', '200', '-png', $input, $prefix], 30);
                $input = $prefix . '.png';
                if (!is_file($input)) {
                    throw new RuntimeException('PDF conversion failed.');
                }
            }
            $outbase = $tmp . '/ocr';
            $this->run([$s['tesseract_path'], $input, $outbase, '-l', $s['language'], '--psm', '6'], 30);
            $text = is_file($outbase . '.txt') ? (string)file_get_contents($outbase . '.txt') : '';
            if (trim($text) === '') {
                throw new RuntimeException('No OCR text was returned.');
            }
            return $this->parse_receipt($text, $s);
        } finally {
            $this->remove_tree($tmp);
        }
    }

    private function parse_receipt(string $text, array $s): array
    {
        $text = wp_check_invalid_utf8($text, true);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), function ($v) {
            return $v !== '';
        }));
        $type = $this->classify_document($text);
        $merchant = $this->parse_merchant($lines, $text, $s);
        $date = $this->parse_date($lines);
        $total = $this->parse_total($lines, $type);
        $card = $this->detect_card($text, $s);
        return [
            'success' => (bool)($merchant || $date || $total['value'] || $card['detected']),
            'merchant' => $merchant,
            'date' => $date,
            'total' => $total['value'],
            'document_type' => $type,
            'confidence' => [
                'merchant' => $merchant ? 0.78 : 0.0,
                'date' => $date ? 0.75 : 0.0,
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
        $joined = implode("\n", array_slice($lines, 0, 20));
        if (preg_match('/(?:^|[|\-])\s*([A-Z][A-Za-z0-9 &\'’.-]{2,60})\s*(?:https?:\/\/|www\.)/m', $joined, $m)) {
            return sanitize_text_field(trim($m[1]));
        }
        if (preg_match('/https?:\/\/(?:www\.)?([a-z0-9-]+)\.[a-z]{2,}/i', $text, $m)) {
            $host = strtolower($m[1]);
            $known = array_change_key_case(SAT_Settings::parse_pairs((string)$s['known_merchants']), CASE_LOWER);
            if (isset($known[$host])) {
                return $known[$host];
            }
            if (!in_array($host, ['google', 'gmail', 'webbrowsertools'], true)) {
                return sanitize_text_field(ucwords(str_replace('-', ' ', $host)));
            }
        }
        $reject = '/\b(order confirmed|confirmation|thank you|receipt|invoice|transaction|merchant|terminal|cashier|customer|copy|visa|mastercard|interac|approved|subtotal|total|tax|hst|gst|expected ship date|track your order)\b/i';
        foreach (array_slice($lines, 0, 12) as $line) {
            $clean = trim(preg_replace('/\s{2,}/', ' ', $line));
            if (strlen($clean) < 3 || strlen($clean) > 80 || preg_match($reject, $clean)) {
                continue;
            }
            if (preg_match('/[A-Za-z]{3}/', $clean) && !preg_match('/^\d+[\d\s\-().]*$/', $clean)) {
                return sanitize_text_field($clean);
            }
        }
        return '';
    }

    private function parse_total(array $lines, string $type): array
    {
        $best = null;
        $best_score = -999;
        $positive = [
            'charged' => 120, 'amount paid' => 115, 'payment total' => 110, 'grand total' => 105,
            'order total' => 100, 'amount due' => 95, 'balance due' => 95, 'total due' => 95,
            'total' => 80, 'payment' => 60,
        ];
        foreach ($lines as $index => $line) {
            $normalized = preg_replace('/(?<=\d)\s+(?=\d{3}(?:[,.]\d{2})\b)/', '', $line);
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
        if ($best === null) {
            return ['value' => '', 'confidence' => 0.0];
        }
        $confidence = $best_score >= 100 ? 0.97 : ($best_score >= 80 ? 0.92 : ($best_score >= 50 ? 0.80 : 0.55));
        return ['value' => number_format($best, 2, '.', ''), 'confidence' => $confidence];
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

    private function parse_date(array $lines): string
    {
        $iso = '/\b(20\d{2})[-\/.](0?[1-9]|1[0-2])[-\/.](0?[1-9]|[12]\d|3[01])\b/';
        $dmy = '/\b(0?[1-9]|[12]\d|3[01])[-\/.](0?[1-9]|1[0-2])[-\/.](20\d{2})\b/';
        $words = '/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2}),?\s+(20\d{2})\b/i';
        foreach ($lines as $line) {
            if (preg_match($iso, $line, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return sprintf('%02d-%02d-%04d', $m[3], $m[2], $m[1]);
            }
            if (preg_match($dmy, $line, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
                return sprintf('%02d-%02d-%04d', $m[1], $m[2], $m[3]);
            }
            if (preg_match($words, $line, $m)) {
                $dt = DateTime::createFromFormat('!M j Y', substr($m[1], 0, 3) . ' ' . $m[2] . ' ' . $m[3]);
                if ($dt) {
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

    private function run(array $args, int $timeout): void
    {
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/usr/local/bin:/usr/bin:/bin']);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start OCR process.');
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $start = time();
        $err = '';
        while (true) {
            $err .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($process, 9);
                throw new RuntimeException('OCR process timed out.');
            }
            usleep(100000);
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($process);
        if ($exit !== 0 && $exit !== -1) {
            throw new RuntimeException('OCR command failed: ' . trim($err));
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
