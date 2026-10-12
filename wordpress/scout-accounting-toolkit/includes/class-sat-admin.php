<?php
/**
 * Settings → Scout Accounting: Wave connection, expense form, bank and card accounts,
 * receipt OCR and inbox rules. Also shows setup problems as admin notices.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Admin
{
    private const PAGE = 'sat-settings';
    private const TABS = [
        'wave' => 'Wave connection',
        'form' => 'Expense form',
        'accounts' => 'Bank and card accounts',
        'ocr' => 'Receipt OCR',
        'inbox' => 'Inbox rules',
    ];

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_notices', [$this, 'notices']);
        foreach (array_keys(self::TABS) as $tab) {
            add_action('admin_post_sat_save_' . $tab, [$this, 'save_' . $tab]);
        }
    }

    public function menu(): void
    {
        add_options_page('Scout Accounting', 'Scout Accounting', 'manage_options', self::PAGE, [$this, 'page']);
    }

    /** Redirect back to a settings tab with a message. */
    public static function back(string $tab, string $type, string $message): void
    {
        set_transient('sat_admin_notice_' . get_current_user_id(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(admin_url('options-general.php?page=' . self::PAGE . '&tab=' . $tab));
        exit;
    }

    private function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer($action);
    }

    /* ------------------------------------------------------------------ */
    /* Setup notices                                                       */
    /* ------------------------------------------------------------------ */

    public function notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && !in_array($screen->id, ['dashboard', 'plugins', 'settings_page_' . self::PAGE], true)) {
            return;
        }
        $problems = [];
        $missing = SAT_DB::missing_constants();
        if ($missing) {
            $problems[] = 'Add the inbox database settings to wp-config.php: <code>' . esc_html(implode(', ', $missing)) . '</code>.';
        } else {
            try {
                $tables = SAT_DB::missing_tables();
                if ($tables) {
                    $problems[] = 'The database user cannot see these tables: <code>' . esc_html(implode(', ', $tables)) . '</code>. Run <code>database/migrations/001-install.sql</code> or check the grants.';
                }
            } catch (Throwable $e) {
                $problems[] = 'Cannot connect to the inbox database: ' . esc_html($e->getMessage());
            }
        }
        if (SAT_Settings::wave_token() === '') {
            $problems[] = 'Add <code>define(\'SAT_WAVE_TOKEN\', \'...\');</code> to wp-config.php to connect to Wave.';
        }
        $old_form = (int)get_option('sat_notice_ocr_form', 0);
        if ($old_form) {
            $problems[] = sprintf('Receipt OCR used form #%d but now uses the expense form (#%d). Check the Expense form tab.', $old_form, (int)SAT_Settings::get('form')['form_id']);
            delete_option('sat_notice_ocr_form');
        }
        if ($problems) {
            echo '<div class="notice notice-warning"><p><strong>Scout Accounting Toolkit:</strong></p><ul style="list-style:disc;margin-left:1.5em">';
            foreach ($problems as $problem) {
                echo '<li>' . wp_kses_post($problem) . '</li>';
            }
            echo '</ul></div>';
        }
    }

    /* ------------------------------------------------------------------ */
    /* Page                                                                */
    /* ------------------------------------------------------------------ */

    public function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tab = sanitize_key($_GET['tab'] ?? 'wave');
        if (!isset(self::TABS[$tab])) {
            $tab = 'wave';
        }
        echo '<div class="wrap"><h1>Scout Accounting Toolkit</h1>';
        $notice = get_transient('sat_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('sat_admin_notice_' . get_current_user_id());
            echo '<div class="notice notice-' . esc_attr($notice['type']) . '"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach (self::TABS as $key => $label) {
            echo '<a class="nav-tab' . ($key === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE . '&tab=' . $key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        $this->{'tab_' . $tab}();
        echo '</div>';
    }

    private function form_open(string $tab): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="sat_save_' . esc_attr($tab) . '">';
        wp_nonce_field('sat_save_' . $tab);
    }

    private function action_button(string $action, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:.5rem">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }

    private function text_row(string $label, string $name, string $value, string $help = '', string $type = 'text'): void
    {
        echo '<tr><th scope="row"><label for="sat-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text" type="' . esc_attr($type) . '" id="sat-' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    private function textarea_row(string $label, string $name, string $value, string $help = ''): void
    {
        echo '<tr><th scope="row"><label for="sat-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<textarea class="large-text code" rows="5" id="sat-' . esc_attr($name) . '" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea>';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        echo '</td></tr>';
    }

    private function checkbox_row(string $label, string $name, bool $checked): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . '> Yes</label></td></tr>';
    }

    /* ------------------------------------------------------------------ */
    /* Wave connection                                                     */
    /* ------------------------------------------------------------------ */

    private function tab_wave(): void
    {
        $wave = SAT_Settings::get('wave');
        echo '<h2>Connection</h2><p>Token: <strong>' . (SAT_Settings::wave_token() !== '' ? 'configured' : 'not configured') . '</strong> (set <code>SAT_WAVE_TOKEN</code> in wp-config.php).</p><p>';
        $this->action_button('sat_wave_test', 'Test connection');
        $this->action_button('sat_wave_discover', 'Discover businesses');
        $this->action_button('sat_wave_discover_accounts', 'Discover accounts');
        echo '</p>';

        $this->form_open('wave');
        echo '<h2>Business</h2><select name="business_id"><option value="">Discover and select a business</option>';
        foreach ((array)$wave['discovered_businesses'] as $business) {
            echo '<option value="' . esc_attr($business['id']) . '"' . selected($wave['business_id'], $business['id'], false) . '>' . esc_html($business['name'] . (!empty($business['is_personal']) ? ' (personal)' : '')) . '</option>';
        }
        echo '</select>';
        submit_button('Save business');
        echo '</form>';

        $accounts = (array)$wave['discovered_accounts'];
        $active = array_filter($accounts, function ($a) {
            return empty($a['is_archived']);
        });
        echo '<h2>Chart of accounts</h2><p>' . count($active) . ' active of ' . count($accounts) . ' accounts. The inbox category list and the expense form categories must use these exact names. Click Discover accounts after changing accounts in Wave.</p>';
        if ($accounts) {
            echo '<details><summary>Show accounts</summary><table class="widefat striped" style="margin-top:12px"><thead><tr><th>Account</th><th>Type</th><th>Subtype</th><th>Status</th></tr></thead><tbody>';
            foreach ($accounts as $a) {
                echo '<tr><td>' . esc_html($a['name']) . '</td><td>' . esc_html($a['type']) . '</td><td>' . esc_html($a['subtype']) . '</td><td>' . (!empty($a['is_archived']) ? 'Archived' : 'Active') . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }
    }

    public function save_wave(): void
    {
        $this->guard('sat_save_wave');
        $wave = SAT_Settings::get('wave');
        $wave['business_id'] = sanitize_text_field(wp_unslash($_POST['business_id'] ?? ''));
        $wave['business_name'] = '';
        foreach ((array)$wave['discovered_businesses'] as $business) {
            if ((string)$business['id'] === $wave['business_id']) {
                $wave['business_name'] = (string)$business['name'];
            }
        }
        SAT_Settings::update('wave', $wave);
        self::back('wave', 'success', 'Business saved.');
    }

    /* ------------------------------------------------------------------ */
    /* Expense form                                                        */
    /* ------------------------------------------------------------------ */

    private function tab_form(): void
    {
        $s = SAT_Settings::get('form');
        if (!class_exists('GFAPI')) {
            echo '<p>Gravity Forms is not active. The expense form and receipt OCR need it; the rest of the inbox works without it.</p>';
            return;
        }
        $form = $s['form_id'] ? GFAPI::get_form((int)$s['form_id']) : null;
        $this->form_open('form');
        echo '<p>Each submission of this form becomes an expense claim in the inbox. Category values must be exact Wave account names. Receipt OCR uses the same form and fields.</p>';
        echo '<table class="form-table"><tr><th scope="row">Expense form</th><td><select name="form_id"><option value="0">Select a form</option>';
        foreach (GFAPI::get_forms() as $f) {
            echo '<option value="' . (int)$f['id'] . '"' . selected((int)$s['form_id'], (int)$f['id'], false) . '>' . esc_html($f['title'] . ' (#' . $f['id'] . ')') . '</option>';
        }
        echo '</select><p class="description">Save after changing the form, then map its fields.</p></td></tr>';
        foreach (SAT_Settings::FORM_FIELDS as $key => $label) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td><select name="field_' . esc_attr($key) . '"><option value="">Not mapped</option>';
            if ($form && !is_wp_error($form)) {
                foreach ($form['fields'] as $field) {
                    $inputs = !empty($field->inputs) ? $field->inputs : [['id' => $field->id, 'label' => '']];
                    foreach ($inputs as $input) {
                        $id = (string)$input['id'];
                        $text = $field->label . (!empty($input['label']) ? ' - ' . $input['label'] : '') . ' (' . $id . ')';
                        echo '<option value="' . esc_attr($id) . '"' . selected((string)$s[$key], $id, false) . '>' . esc_html($text) . '</option>';
                    }
                }
            }
            echo '</select></td></tr>';
        }
        echo '</table>';
        submit_button('Save expense form');
        echo '</form>';
    }

    public function save_form(): void
    {
        $this->guard('sat_save_form');
        $s = ['form_id' => absint($_POST['form_id'] ?? 0)];
        foreach (array_keys(SAT_Settings::FORM_FIELDS) as $key) {
            $s[$key] = sanitize_text_field(wp_unslash($_POST['field_' . $key] ?? ''));
        }
        SAT_Settings::update('form', $s);
        self::back('form', 'success', 'Expense form saved.');
    }

    /* ------------------------------------------------------------------ */
    /* Bank and card accounts                                              */
    /* ------------------------------------------------------------------ */

    private function tab_accounts(): void
    {
        $lines = [];
        foreach (SAT_Settings::get('accounts') as $acctid => $a) {
            $lines[] = implode(' | ', [$acctid, $a['source_system'] ?? '', $a['statement_type'] ?? '', $a['account_name'] ?? '', $a['wave_account'] ?? '']);
        }
        $this->form_open('accounts');
        echo '<p>The bank and card accounts whose OFX statements are imported. One account per line:</p>';
        echo '<p><code>ACCTID | source key | bank or credit_card | display name | Wave account name</code></p>';
        echo '<ul style="list-style:disc;margin-left:1.5em">';
        echo '<li><strong>ACCTID</strong> is the account number inside the OFX file.</li>';
        echo '<li><strong>Source key</strong> identifies the account in the inbox and must start with <code>ofx_</code>, for example <code>ofx_operating</code>. Do not change it once transactions are imported.</li>';
        echo '<li><strong>Wave account name</strong> is used as the category for transfers to or from this account.</li></ul>';
        echo '<textarea name="accounts" rows="6" class="large-text code">' . esc_textarea(implode("\n", $lines)) . '</textarea>';
        submit_button('Save accounts');
        echo '</form>';
    }

    public function save_accounts(): void
    {
        $this->guard('sat_save_accounts');
        $accounts = [];
        foreach (preg_split('/\R/', (string)wp_unslash($_POST['accounts'] ?? '')) as $n => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $p = array_map('trim', explode('|', $line));
            if (count($p) < 4) {
                self::back('accounts', 'error', 'Line ' . ($n + 1) . ' needs at least ACCTID | source key | type | display name.');
            }
            if (!preg_match('/^ofx_[a-z0-9_]+$/', $p[1])) {
                self::back('accounts', 'error', 'Line ' . ($n + 1) . ': the source key must start with ofx_ and use lowercase letters, numbers and underscores.');
            }
            if (!in_array($p[2], ['bank', 'credit_card'], true)) {
                self::back('accounts', 'error', 'Line ' . ($n + 1) . ': the type must be bank or credit_card.');
            }
            $accounts[sanitize_text_field($p[0])] = [
                'source_system' => $p[1],
                'statement_type' => $p[2],
                'account_name' => sanitize_text_field($p[3]),
                'wave_account' => sanitize_text_field($p[4] ?? ''),
            ];
        }
        SAT_Settings::update('accounts', $accounts);
        self::back('accounts', 'success', count($accounts) . ' account(s) saved.');
    }

    /* ------------------------------------------------------------------ */
    /* Receipt OCR                                                         */
    /* ------------------------------------------------------------------ */

    private function tab_ocr(): void
    {
        $s = SAT_Settings::get('ocr');
        $this->form_open('ocr');
        echo '<p>Reads uploaded receipts on the expense form and suggests payee, date, total and payment method. Suggestions stay editable and never overwrite what the user entered. Uses the form and fields from the Expense form tab.</p><table class="form-table">';
        $this->checkbox_row('Enabled', 'enabled', $s['enabled'] === '1');
        $this->text_row('Group card choice', 'credit_card_choice', $s['credit_card_choice'], 'Payment-method choice (value or label) selected when a group card is detected.');
        $this->text_row('Reimbursement choice', 'reimbursement_choice', $s['reimbursement_choice'], 'Payment-method choice selected when a card that is not a group card is detected.');
        $this->textarea_row('Group card numbers', 'card_mappings', $s['card_mappings'], 'One per line: last four digits | label. Labels are only shown here.');
        $this->textarea_row('Known merchants', 'known_merchants', $s['known_merchants'], 'One per line: website name | payee name, e.g. tshirtelephant | T-Shirt Elephant.');
        $this->text_row('Card search distance (characters)', 'card_context_chars', $s['card_context_chars'], '', 'number');
        $this->text_row('Tesseract path', 'tesseract_path', $s['tesseract_path']);
        $this->text_row('pdftoppm path', 'pdftoppm_path', $s['pdftoppm_path']);
        $this->text_row('Tesseract language', 'language', $s['language']);
        $this->text_row('Maximum files per upload', 'max_files', $s['max_files'], '', 'number');
        $this->text_row('Maximum file size (MB)', 'max_file_mb', $s['max_file_mb'], '', 'number');
        $this->text_row('Requests per visitor per 10 minutes', 'rate_limit', $s['rate_limit'], '', 'number');
        $this->checkbox_row('Write diagnostics to the PHP error log', 'debug_log', $s['debug_log'] === '1');
        echo '</table>';
        submit_button('Save receipt OCR');
        echo '</form><h2>Server checks</h2><ul>';
        foreach (SAT_Receipt_OCR::server_checks() as $label => $ok) {
            echo '<li>' . esc_html($label) . ': <strong>' . ($ok ? 'available' : 'not available') . '</strong></li>';
        }
        echo '</ul>';
    }

    public function save_ocr(): void
    {
        $this->guard('sat_save_ocr');
        $old = SAT_Settings::get('ocr');
        $s = $old;
        foreach (['enabled', 'debug_log'] as $key) {
            $s[$key] = empty($_POST[$key]) ? '0' : '1';
        }
        foreach (['card_context_chars', 'max_files', 'max_file_mb', 'rate_limit'] as $key) {
            $s[$key] = (string)max(1, absint($_POST[$key] ?? $old[$key]));
        }
        foreach (['credit_card_choice', 'reimbursement_choice', 'tesseract_path', 'pdftoppm_path'] as $key) {
            $s[$key] = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
        }
        $s['language'] = preg_replace('/[^a-zA-Z0-9_+\-]/', '', (string)($_POST['language'] ?? 'eng')) ?: 'eng';
        $cards = [];
        foreach (preg_split('/\R/', (string)wp_unslash($_POST['card_mappings'] ?? '')) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            $suffix = preg_replace('/\D/', '', $parts[0] ?? '');
            if (strlen($suffix) === 4) {
                $label = sanitize_text_field($parts[1] ?? '');
                $cards[] = $suffix . ($label !== '' ? ' | ' . $label : '');
            }
        }
        $s['card_mappings'] = implode("\n", array_values(array_unique($cards)));
        $s['known_merchants'] = SAT_Settings::pairs_text(SAT_Settings::parse_pairs((string)wp_unslash($_POST['known_merchants'] ?? '')));
        SAT_Settings::update('ocr', $s);
        self::back('ocr', 'success', 'Receipt OCR saved.');
    }

    /* ------------------------------------------------------------------ */
    /* Inbox rules                                                         */
    /* ------------------------------------------------------------------ */

    private function tab_inbox(): void
    {
        $s = SAT_Settings::get('inbox');
        $this->form_open('inbox');
        echo '<p>Rules for suggesting categories on deposits. Expense claims and transfers set their own categories.</p><table class="form-table">';
        $this->text_row('Uniform sales account', 'uniform_account', $s['uniform_account'], 'Wave income account suggested when a deposit mentions a uniform item. Leave blank to turn off.');
        $this->text_row('Uniform words', 'uniform_keywords', $s['uniform_keywords'], 'Comma-separated words that mark a uniform purchase.');
        $this->textarea_row('Section income accounts', 'section_map', SAT_Settings::pairs_text((array)$s['section_map']), 'One per line: section | Wave income account, e.g. Cubs | Program Income - Cubs. Matched against the Interac routing section and the bank description.');
        echo '</table>';
        submit_button('Save inbox rules');
        echo '</form>';
    }

    public function save_inbox(): void
    {
        $this->guard('sat_save_inbox');
        SAT_Settings::update('inbox', [
            'uniform_account' => sanitize_text_field(wp_unslash($_POST['uniform_account'] ?? '')),
            'uniform_keywords' => sanitize_text_field(wp_unslash($_POST['uniform_keywords'] ?? '')),
            'section_map' => SAT_Settings::parse_pairs((string)wp_unslash($_POST['section_map'] ?? '')),
        ]);
        self::back('inbox', 'success', 'Inbox rules saved.');
    }
}
