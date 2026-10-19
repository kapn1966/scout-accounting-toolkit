<?php
/**
 * Gravity Forms expense form: every submission becomes an expense-claim observation in the inbox.
 * The form's category values must be exact Wave account names.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Expense_Form
{
    public const ONE_RECEIPT_MESSAGE = 'Invoice and Credit Card expenses require exactly one receipt. Remove the additional receipt before submitting.';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('gform_after_submission', [$this, 'after_submission'], 10, 2);
        add_filter('gform_pre_render', [$this, 'prefill_current_user']);

        $settings = SAT_Settings::get('form');
        $form_id = (int)($settings['form_id'] ?? 0);
        $receipt_field = self::field_number((string)($settings['receipt'] ?? ''));
        if ($form_id > 0 && $receipt_field > 0 && self::field_number((string)($settings['payment'] ?? '')) > 0) {
            add_filter('gform_field_validation_' . $form_id . '_' . $receipt_field, [$this, 'validate_receipt_count'], 10, 4);
            add_action('gform_enqueue_scripts_' . $form_id, [$this, 'enqueue_receipt_rule']);
        }
    }

    /**
     * card | reimburse | invoice | other, from a stored Payment Method value.
     * Substring rules accept current and older form wording, e.g. "Charge on ... credit card".
     */
    public static function payment_kind(string $method): string
    {
        $method = strtolower($method);
        if (strpos($method, 'card') !== false) {
            return 'card';
        }
        if (strpos($method, 'reimburs') !== false) {
            return 'reimburse';
        }
        if (strpos($method, 'invoice') !== false) {
            return 'invoice';
        }
        return 'other';
    }

    /** Invoice and Credit Card claims carry exactly one receipt; Reimburse a Scouter may carry several. */
    public static function requires_single_receipt(string $method): bool
    {
        return in_array(self::payment_kind($method), ['card', 'invoice'], true);
    }

    /** Field number of a mapped field or sub-input ("6" or "1.3" -> 6, 1). */
    private static function field_number(string $mapped): int
    {
        $mapped = trim($mapped);
        return preg_match('/^(\d+)(?:\.\d+)?$/', $mapped, $m) ? (int)$m[1] : 0;
    }

    /**
     * Files Gravity Forms counts for an upload field during validation: files already uploaded
     * (multi-file temporary uploads), new $_FILES uploads, and files saved on an existing entry.
     */
    public static function submitted_receipt_count($field, $value): int
    {
        $count = 0;
        if (is_object($field) && method_exists($field, 'get_submission_files')) {
            $files = (array)$field->get_submission_files();
            $count += count((array)($files['existing'] ?? [])) + count((array)($files['new'] ?? []));
        } elseif (is_object($field) && class_exists('GFFormsModel')) {
            $uploaded = GFFormsModel::$uploaded_files[(int)($field->formId ?? 0)]['input_' . (int)($field->id ?? 0)] ?? [];
            $count += is_array($uploaded) ? count($uploaded) : ((string)$uploaded !== '' ? 1 : 0);
        }

        if (!empty($value)) {
            $saved = is_array($value) ? $value : json_decode((string)$value, true);
            $count += is_array($saved) ? count(array_filter($saved, static fn ($file) => $file !== '' && $file !== null)) : 1;
        }
        return $count;
    }

    /** Authoritative server rule for the receipt field; JavaScript only mirrors it. */
    public function validate_receipt_count($result, $value, $form, $field)
    {
        if (!is_array($result) || empty($result['is_valid'])) {
            // Keep Gravity Forms' own result, including required-field failures for zero receipts.
            return $result;
        }
        $payment_field = self::field_number((string)(SAT_Settings::get('form')['payment'] ?? ''));
        if ($payment_field < 1 || !function_exists('rgpost')) {
            return $result;
        }
        $method = rgpost('input_' . $payment_field);
        if (!is_string($method) || !self::requires_single_receipt($method)) {
            return $result;
        }
        if (self::submitted_receipt_count($field, $value) > 1) {
            $result['is_valid'] = false;
            $result['message'] = self::ONE_RECEIPT_MESSAGE;
        }
        return $result;
    }

    /** Runs only when Gravity Forms renders the configured expense form. */
    public function enqueue_receipt_rule(): void
    {
        $settings = SAT_Settings::get('form');
        $version = (string)(@filemtime(SAT_DIR . '/assets/expense-form.js') ?: SAT_VERSION);
        wp_enqueue_script('sat-expense-form', SAT_URL . 'assets/expense-form.js', [], $version, true);
        wp_localize_script('sat-expense-form', 'satExpenseForm', [
            'formId' => (int)$settings['form_id'],
            'receiptFieldId' => self::field_number((string)$settings['receipt']),
            'paymentFieldId' => self::field_number((string)$settings['payment']),
            'message' => self::ONE_RECEIPT_MESSAGE,
        ]);
    }

    /** Prefill the configured claimant name and email without overwriting entered values. */
    public function prefill_current_user($form)
    {
        if (!is_user_logged_in()) {
            return $form;
        }

        $settings = SAT_Settings::get('form');
        if (empty($settings['form_id']) || (int)($form['id'] ?? 0) !== (int)$settings['form_id']) {
            return $form;
        }

        $user = wp_get_current_user();
        $nameInput = (string)($settings['name'] ?? '');
        $emailInput = (string)($settings['email'] ?? '');

        foreach ($form['fields'] as $field) {
            $fieldId = (string)$field->id;

            if ($this->mapped_field_matches($nameInput, $fieldId)) {
                if ($field->type === 'name' && !empty($field->inputs) && is_array($field->inputs)) {
                    $first = trim((string)$user->first_name);
                    $last = trim((string)$user->last_name);

                    // If no separate profile names exist, put the display name
                    // in First rather than misleadingly in Last.
                    if ($first === '' && $last === '') {
                        $first = trim((string)$user->display_name);
                    }

                    foreach ($field->inputs as &$input) {
                        $inputId = (string)($input['id'] ?? '');
                        if ($this->input_suffix($inputId) === '3' && (string)($input['defaultValue'] ?? '') === '') {
                            $input['defaultValue'] = $first;
                        }
                        if ($this->input_suffix($inputId) === '6' && (string)($input['defaultValue'] ?? '') === '') {
                            $input['defaultValue'] = $last;
                        }
                    }
                    unset($input);
                } elseif ((string)($field->defaultValue ?? '') === '') {
                    $fullName = trim((string)$user->first_name . ' ' . (string)$user->last_name);
                    $field->defaultValue = $fullName !== '' ? $fullName : (string)$user->display_name;
                }
            }

            if ($this->mapped_field_matches($emailInput, $fieldId) && (string)($field->defaultValue ?? '') === '') {
                $field->defaultValue = (string)$user->user_email;
            }
        }

        return $form;
    }

    /** A mapped compound input such as 17.6 belongs to field 17. */
    private function mapped_field_matches(string $mappedInput, string $fieldId): bool
    {
        if ($mappedInput === '') {
            return false;
        }

        return $mappedInput === $fieldId || strtok($mappedInput, '.') === $fieldId;
    }

    /** Gravity Forms name inputs use .3 for first and .6 for last. */
    private function input_suffix(string $inputId): string
    {
        $parts = explode('.', $inputId, 2);
        return $parts[1] ?? '';
    }

    public function after_submission($entry, $form): void
    {
        $settings = SAT_Settings::get('form');
        if (empty($settings['form_id']) || (int)$form['id'] !== (int)$settings['form_id']) {
            return;
        }
        $entry_id = (int)rgar($entry, 'id');
        $result = $this->import_entry($entry, $form);
        if (!class_exists('GFFormsModel')) {
            return;
        }
        if (is_wp_error($result)) {
            GFFormsModel::add_note($entry_id, 0, 'Scout Accounting', 'Inbox import failed: ' . $result->get_error_message());
            return;
        }
        GFFormsModel::add_note($entry_id, 0, 'Scout Accounting', sprintf(
            'Added to the inbox as an expense claim. Observation %d; category: %s.',
            (int)$result,
            $this->value($entry, $settings['category']) ?: '(blank)'
        ));
    }

    /**
     * Create or refresh the claim observation and its single allocation line.
     * Safe to repeat: keyed on form and entry ID. Used by submissions and the Tools backfill.
     *
     * @return int|WP_Error Observation ID.
     */
    public function import_entry(array $entry, array $form)
    {
        $s = SAT_Settings::get('form');
        $entry_id = (int)rgar($entry, 'id');

        $amount = preg_replace('/[^0-9.\-]/', '', $this->value($entry, $s['amount']));
        if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
            return new WP_Error('sat_amount', 'The mapped amount is missing or invalid.');
        }
        $amount = number_format(abs((float)$amount), 2, '.', '');

        $timestamp = strtotime(str_replace('/', '-', $this->value($entry, $s['date'])));
        if (!$timestamp) {
            return new WP_Error('sat_date', 'The mapped date is missing or invalid.');
        }
        $date = gmdate('Y-m-d', $timestamp);

        $payee = $this->value($entry, $s['payee']);
        $description = $this->value($entry, $s['description']);
        $name = $this->value($entry, $s['name']);
        $submitted_category = trim($this->value($entry, $s['category']));
        $category = SAT_Settings::canonical_category($submitted_category);
        $payload = [
            'format' => 'gravity_forms',
            'form_id' => (int)$form['id'],
            'entry_id' => $entry_id,
            'submitter_name' => $name,
            'submitter_email' => $this->value($entry, $s['email']),
            'payee' => $payee,
            'description' => $description,
            'payment_method' => $this->value($entry, $s['payment']),
            'submitted_category' => $submitted_category,
            'export_account' => $category,
            'external_id' => $this->value($entry, $s['external_id']),
            'receipt_urls' => $this->receipt_urls($this->value($entry, $s['receipt'])),
            'submitted_at_gmt' => self::gmt_datetime((string)rgar($entry, 'date_created')),
        ];
        $source_key = 'gravity_forms:' . (int)$form['id'] . ':' . $entry_id;
        $text = trim(implode(' | ', array_filter([$payee, $description, $name])));
        if ($text === '') {
            $text = 'Gravity Forms entry ' . $entry_id;
        }

        try {
            $db = SAT_DB::pdo();
            $db->beginTransaction();
            $db->prepare(
                "INSERT INTO inbox_observations
                    (source_system, source_key, transaction_date, amount, direction, description, gf_entry_id, payload_json)
                 VALUES ('gravity_forms', ?, ?, ?, 'expense', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    transaction_date = VALUES(transaction_date),
                    amount = VALUES(amount),
                    direction = VALUES(direction),
                    description = VALUES(description),
                    gf_entry_id = VALUES(gf_entry_id),
                    payload_json = VALUES(payload_json)"
            )->execute([$source_key, $date, $amount, $text, $entry_id, wp_json_encode($payload)]);

            $find = $db->prepare("SELECT observation_id FROM inbox_observations WHERE source_system = 'gravity_forms' AND source_key = ?");
            $find->execute([$source_key]);
            $observation_id = (int)$find->fetchColumn();
            if (!$observation_id) {
                throw new RuntimeException('The saved inbox observation could not be found.');
            }

            if ($category !== '') {
                $db->prepare(
                    'INSERT INTO inbox_allocations (bank_observation_id, line_number, export_account, amount, allocation_note)
                     VALUES (?, 1, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        export_account = VALUES(export_account),
                        amount = VALUES(amount),
                        allocation_note = VALUES(allocation_note)'
                )->execute([$observation_id, $category, $amount, 'Submitted with Gravity Forms']);
            }
            $db->commit();
            return $observation_id;
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            return new WP_Error('sat_database', $e->getMessage());
        }
    }

    /** Active entries on the configured form created on or after a date. @return array [form, entries] */
    public function entries_since(string $from): array
    {
        if (!class_exists('GFAPI')) {
            throw new RuntimeException('Gravity Forms is not active.');
        }
        $form_id = (int)(SAT_Settings::get('form')['form_id'] ?? 0);
        if (!$form_id) {
            throw new RuntimeException('No expense form is selected in Scout Accounting settings.');
        }
        $form = GFAPI::get_form($form_id);
        if (!$form) {
            throw new RuntimeException('The expense form could not be loaded.');
        }
        $entries = [];
        $offset = 0;
        do {
            $page = GFAPI::get_entries($form_id, ['status' => 'active', 'start_date' => $from . ' 00:00:00'], null, ['offset' => $offset, 'page_size' => 200]);
            if (is_wp_error($page)) {
                throw new RuntimeException($page->get_error_message());
            }
            $entries = array_merge($entries, $page);
            $offset += count($page);
        } while (count($page) === 200);
        return [$form, $entries];
    }

    private function value($entry, $field_id): string
    {
        return (string)$field_id === '' ? '' : trim((string)rgar($entry, (string)$field_id));
    }

    /** Gravity Forms stores date_created in UTC as Y-m-d H:i:s; anything else is treated as unknown. */
    public static function gmt_datetime(string $value): string
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $value ? $value : '';
    }

    /** Submission time of an entry from Gravity Forms itself (read-only), for claims imported before SAT saved it. */
    public static function entry_submitted_at_gmt(int $entry_id): string
    {
        static $cache = [];
        if ($entry_id < 1 || !class_exists('GFAPI')) {
            return '';
        }
        if (!array_key_exists($entry_id, $cache)) {
            $entry = GFAPI::get_entry($entry_id);
            $cache[$entry_id] = is_array($entry) ? self::gmt_datetime((string)($entry['date_created'] ?? '')) : '';
        }
        return $cache[$entry_id];
    }

    private function receipt_urls(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $items = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $raw);
        $urls = [];
        foreach ((array)$items as $item) {
            if (is_array($item)) {
                $item = $item['url'] ?? $item['file'] ?? '';
            }
            $url = esc_url_raw(trim((string)$item));
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }
}
