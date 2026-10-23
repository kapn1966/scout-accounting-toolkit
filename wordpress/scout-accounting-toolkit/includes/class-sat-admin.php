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
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_notices', [$this, 'notices']);
        foreach (array_keys(self::TABS) as $tab) {
            add_action('admin_post_sat_save_' . $tab, [$this, 'save_' . $tab]);
        }
    }

    public function menu(): void
    {
        $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 17.280771 17.96875">'
            . '<path fill="black" transform="translate(-106.89171,-106.36252)" d="m 115.01936,124.30864 c -0.66628,-0.0438 -1.19139,-0.14412 -1.60372,-0.30637 l -0.24658,-0.097 0.16454,-0.23346 c 0.61351,-0.87058 0.91671,-1.97306 0.91671,-3.33334 v -0.43206 h -0.29876 -0.29876 l -0.0169,0.14325 c -0.009,0.0788 -0.0256,0.23147 -0.0361,0.33929 -0.1311,1.33425 -0.6065,2.01817 -1.40714,2.02431 -0.27348,0.002 -0.46611,-0.0858 -0.66159,-0.30206 -0.33956,-0.37557 -0.58076,-1.13135 -0.47986,-1.50353 l 0.0347,-0.1281 0.11196,0.0502 c 0.63079,0.28253 1.06897,0.29516 1.28614,0.037 0.0831,-0.0987 0.18084,-0.34398 0.21216,-0.53218 l 0.0213,-0.12818 h -0.14976 c -0.17582,0 -0.31948,-0.0551 -0.43954,-0.16851 -0.25514,-0.24102 -0.26725,-0.70269 -0.0251,-0.95759 0.11353,-0.11953 0.21519,-0.16644 0.41048,-0.18939 0.0927,-0.0109 0.16831,-0.0281 0.16811,-0.0383 -0.001,-0.0729 -0.0975,-0.35677 -0.17547,-0.51805 -0.26267,-0.54369 -0.72806,-0.87263 -1.23461,-0.87263 -0.53616,0 -0.94203,0.21419 -1.10943,0.58548 -0.0946,0.2099 -0.14441,0.5753 -0.11839,0.8692 l 0.0212,0.23939 -0.17677,-0.0215 c -1.07182,-0.1302 -2.11692,-0.88766 -2.63138,-1.90716 -0.12944,-0.25652 -0.26922,-0.68291 -0.3218,-0.98167 -0.0594,-0.33735 -0.0573,-1.14675 0.004,-1.46839 0.25096,-1.32299 1.25857,-1.99053 3.01241,-1.99576 0.59413,-0.002 0.93836,0.0979 1.39297,0.40331 0.19827,0.1332 0.5979,0.53545 0.78857,0.79374 0.74641,1.01107 1.29861,2.69944 1.48308,4.53454 0.0173,0.17179 0.0314,0.32446 0.0314,0.33929 0,0.0148 0.1378,0.027 0.30622,0.027 h 0.30621 l -0.0207,-0.48349 c -0.046,-1.07521 -0.1979,-1.75317 -0.81939,-3.65657 -0.61968,-1.89783 -0.80195,-2.66964 -0.84755,-3.58896 -0.0418,-0.84315 0.13062,-1.55133 0.52062,-2.13798 0.27971,-0.42072 0.57667,-0.70874 1.4599,-1.41585 0.40263,-0.32235 0.93434,-0.83639 0.93434,-0.90328 0,-0.0706 0.0879,-0.0152 0.19147,0.12052 0.14354,0.18823 0.42742,0.45551 0.84967,0.80002 0.86321,0.70429 1.24462,1.1029 1.5168,1.58524 0.371,0.65747 0.52357,1.52847 0.42667,2.4359 -0.0788,0.73756 -0.21666,1.26368 -0.76844,2.93175 -0.7043,2.12916 -0.88918,2.94593 -0.88918,3.92809 v 0.38672 l 0.30912,-0.009 0.30913,-0.009 0.008,-0.13571 c 0.0152,-0.26237 0.10359,-0.92411 0.17715,-1.32698 0.4013,-2.19781 1.29317,-3.83397 2.39138,-4.38701 0.41991,-0.21147 0.78119,-0.2694 1.41162,-0.22635 0.94354,0.0644 1.55225,0.27685 2.01953,0.70475 0.52223,0.4782 0.7318,1.05289 0.73248,2.00861 0,0.56203 -0.0407,0.85423 -0.17784,1.26348 -0.17935,0.53532 -0.44427,0.96193 -0.8452,1.3611 -0.58236,0.57977 -1.25834,0.92245 -1.9924,1.01001 l -0.16587,0.0198 0.009,-0.38711 c 0.007,-0.3175 -10e-4,-0.42115 -0.0499,-0.57639 -0.10008,-0.32137 -0.31234,-0.53915 -0.64021,-0.65683 -0.21032,-0.0755 -0.6422,-0.0907 -0.84916,-0.03 -0.36663,0.10763 -0.70511,0.41834 -0.90298,0.82891 -0.0778,0.16134 -0.17383,0.44536 -0.17525,0.51805 0,0.0102 0.0755,0.0274 0.16812,0.0383 0.275,0.0323 0.409,0.1299 0.52265,0.38058 0.0819,0.18076 0.0791,0.37109 -0.009,0.55917 -0.11663,0.25046 -0.31661,0.37516 -0.6023,0.37558 l -0.11574,9e-5 0.0214,0.12817 c 0.0271,0.16192 0.11947,0.39909 0.1991,0.51092 0.17478,0.24545 0.58252,0.27358 1.06654,0.0736 0.14928,-0.0617 0.28659,-0.1191 0.30512,-0.1276 0.0649,-0.0298 0.10355,0.23553 0.0714,0.48998 -0.058,0.45831 -0.24114,0.87101 -0.51199,1.15351 -0.19507,0.20345 -0.36766,0.28025 -0.6253,0.27828 -0.82066,-0.006 -1.27188,-0.68493 -1.40615,-2.11482 l -0.0354,-0.37698 -0.31351,-0.009 -0.31352,-0.009 0.0192,0.56659 c 0.0217,0.64116 0.0559,0.9758 0.14399,1.41102 0.1236,0.6106 0.37904,1.22567 0.71892,1.73116 0.10335,0.15371 0.18341,0.28346 0.1779,0.28831 -0.0474,0.0418 -0.29738,0.14443 -0.50816,0.20871 -0.32896,0.10032 -0.7425,0.1632 -1.31139,0.1994 -0.49367,0.0314 -0.53716,0.0314 -1.02539,-8e-4 l -1e-5,2e-4 v 1e-5 z"/>'
            . '</svg>';
        add_menu_page('Inbox', 'Scout Accounting', SAT_CAP_REVIEW, 'sat-inbox', [SAT_Inbox::instance(), 'admin_page'], 'data:image/svg+xml;base64,' . base64_encode($icon));
        add_submenu_page('sat-inbox', 'Inbox', 'Inbox', SAT_CAP_REVIEW, 'sat-inbox', [SAT_Inbox::instance(), 'admin_page']);
        add_submenu_page('sat-inbox', 'Tools', 'Tools', 'manage_options', 'sat-tools', [SAT_Tools::instance(), 'admin_page']);
        add_submenu_page('sat-inbox', 'Settings', 'Settings', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function assets(string $hook_suffix): void
    {
        if (in_array($hook_suffix, ['toplevel_page_sat-inbox', 'scout-accounting_page_sat-tools', 'scout-accounting_page_sat-settings'], true)) {
            wp_enqueue_style('sat-admin', SAT_URL . 'assets/admin.css', [], SAT_VERSION);
        }
    }

    public static function heading(string $title): void
    {
        echo '<h1 class="sat-admin-heading"><span>' . esc_html($title) . '</span>';
        echo '<span class="sat-admin-version">SAT ' . esc_html(SAT_VERSION) . '</span></h1>';
    }

    /** Redirect back to a settings tab with a message. */
    public static function back(string $tab, string $type, string $message): void
    {
        set_transient('sat_admin_notice_' . get_current_user_id(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&tab=' . $tab));
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
                    $problems[] = 'The database user cannot see these tables: <code>' . esc_html(implode(', ', $tables)) . '</code>. For a fresh install, run <code>database/migrations/001-install.sql</code>; for a database created from the original 001 schema, apply <code>database/migrations/002-transfer-ofx-batches.sql</code>. Also check the grants.';
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
        echo '<div class="wrap">';
        self::heading('Scout Accounting Settings');
        $notice = get_transient('sat_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('sat_admin_notice_' . get_current_user_id());
            echo '<div class="notice notice-' . esc_attr($notice['type']) . '"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach (self::TABS as $key => $label) {
            echo '<a class="nav-tab' . ($key === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE . '&tab=' . $key)) . '">' . esc_html($label) . '</a>';
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
        $this->textarea_row('Expense category aliases', 'category_aliases', SAT_Settings::pairs_text((array)($s['category_aliases'] ?? [])), 'One per line: form label | Wave prefix, e.g. Venturers | Sea Venturers. Venturers - Camp food becomes Sea Venturers - Camp food.');
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
            'category_aliases' => SAT_Settings::parse_pairs((string)wp_unslash($_POST['category_aliases'] ?? '')),
        ]);
        self::back('inbox', 'success', 'Inbox rules saved.');
    }
}
