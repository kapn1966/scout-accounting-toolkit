<?php

/**
 * Plugin Name: Gravity Forms Wave Sync
 * Description: Configurable Wave Accounting workflow metadata for Gravity Forms expense submissions.
 * Version: 0.7.1
 * Author: 403TO
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (! defined('ABSPATH')) exit;
define('GFWAVE_VERSION', '0.7.1');

final class GF_Wave_Settings
{
    const OPTION = 'gf_wave_settings';
    public static function defaults()
    {
        return array(
            'form_id' => 0,
            'business_id' => '',
            'business_name' => '',
            'discovered_businesses' => array(),
            'discovered_accounts' => array(),
            'category_id_map' => array(),
            'anchor_id_map' => array(),
            'field_name' => '',
            'field_email' => '',
            'field_amount' => '',
            'field_date' => '',
            'field_category' => '',
            'field_payee' => '',
            'field_description' => '',
            'field_approver' => '',
            'field_payment' => '',
            'field_receipt' => '',
            'field_external_id' => '',
            'category_map' => array(
                'Beavers' => 'program - Beavers',
                'Cubs' => 'program - Cubs',
                'Sea Scouts' => 'program - Sea Scouts',
                'Sea Venturers' => 'program - Sea Venturers',
                'Uniforms' => 'Uniform Items - all sections',
                'Program Equipment' => 'Program equipment and misc program expense',
                'Training & Certification' => 'Training and certification',
                'Storage' => 'Storage costs',
                'Boating' => 'Boating - all except keelboat expenses',
                'Keelboat' => 'Keelboat related equipment, maintenance and storage',
                'Facility Rental' => 'facility or hall rental',
                'Group Activity' => 'Group activities and events',
                'National / International Event' => 'National & International Scouting Events',
                'Administration' => 'Admin & operations',
                'Printing' => 'Printing and Reproduction',
                'Travel' => 'Travel Expense',
                'Fundraising' => 'Fundraising Costs',
                'Direct Program Subsidy' => 'Direct Program Subsidy'
            ),
            'payment_map' => array(
                'Charge on 403TO credit card' => array('status' => 'ready', 'anchor' => 'GC Visa'),
                'Invoice requiring payment' => array('status' => 'awaiting_payment', 'anchor' => 'Operating Acct'),
                'reimbursement of Scouter (please avoid this)' => array('status' => 'awaiting_reimbursement', 'anchor' => 'Operating Acct')
            )
        );
    }
    public static function get()
    {
        $saved = get_option(self::OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
    }
    public static function update($s)
    {
        return update_option(self::OPTION, $s, false);
    }
    public static function activate()
    {
        if (false === get_option(self::OPTION, false)) add_option(self::OPTION, self::defaults(), '', 'no');
    }
    public static function token()
    {
        return defined('GFWAVE_ACCESS_TOKEN') && GFWAVE_ACCESS_TOKEN ? (string)GFWAVE_ACCESS_TOKEN : '';
    }
}

final class GF_Wave_API
{
    const ENDPOINT = 'https://gql.waveapps.com/graphql/public';
    public function request($query, $variables = array())
    {
        $token = GF_Wave_Settings::token();
        if (!$token) return new WP_Error('gf_wave_missing_token', 'Define GFWAVE_ACCESS_TOKEN in wp-config.php.');
        $r = wp_remote_post(self::ENDPOINT, array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'), 'body' => wp_json_encode(array('query' => $query, 'variables' => empty($variables) ? new stdClass() : (object) $variables,))));
        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        $body = json_decode(wp_remote_retrieve_body($r), true);
        if ($code < 200 || $code >= 300) return new WP_Error('gf_wave_http', 'Wave returned HTTP ' . intval($code) . '.', $body);
        if (!is_array($body)) return new WP_Error('gf_wave_json', 'Wave returned invalid JSON.');
        if (!empty($body['errors'])) return new WP_Error('gf_wave_graphql', wp_json_encode($body['errors']), $body);
        return $body;
    }
    public function test()
    {
        return $this->request('query { user { id defaultEmail } }');
    }
    public function businesses()
    {
        $r = $this->request('query { businesses(page: 1, pageSize: 100) { pageInfo { currentPage totalPages totalCount } edges { node { id name isPersonal } } } }');
        if (is_wp_error($r)) return $r;
        $edges = $r['data']['businesses']['edges'] ?? array();
        $out = array();
        foreach ($edges as $edge) {
            $n = $edge['node'] ?? array();
            if (empty($n['id']) || empty($n['name'])) continue;
            $out[] = array('id' => sanitize_text_field($n['id']), 'name' => sanitize_text_field($n['name']), 'is_personal' => !empty($n['isPersonal']));
        }
        return $out;
    }
    public function accounts($business_id)
    {
        if (!$business_id) return new WP_Error('gf_wave_missing_business', 'Select and save a Wave business before discovering accounts.');
        $query = 'query ($businessId: ID!, $page: Int!, $pageSize: Int!) { business(id: $businessId) { id name accounts(page: $page, pageSize: $pageSize, isArchived: null) { pageInfo { currentPage totalPages totalCount } edges { node { id name isArchived type { name value } subtype { name value } } } } } }';
        $page = 1;
        $page_size = 100;
        $total_pages = 1;
        $by_id = array();
        do {
            $r = $this->request($query, array('businessId' => $business_id, 'page' => $page, 'pageSize' => $page_size));
            if (is_wp_error($r)) return $r;
            $accounts = $r['data']['business']['accounts'] ?? array();
            if (empty($accounts) && 1 === $page) return new WP_Error('gf_wave_accounts_missing', 'Wave returned no account collection for the selected business.');
            $page_info = $accounts['pageInfo'] ?? array();
            $total_pages = max(1, (int)($page_info['totalPages'] ?? 1));
            foreach (($accounts['edges'] ?? array()) as $edge) {
                $n = $edge['node'] ?? array();
                if (empty($n['id']) || empty($n['name'])) continue;
                $id = sanitize_text_field($n['id']);
                $by_id[$id] = array('id' => $id, 'name' => sanitize_text_field($n['name']), 'is_archived' => !empty($n['isArchived']), 'type' => sanitize_text_field($n['type']['name'] ?? ''), 'type_value' => sanitize_text_field($n['type']['value'] ?? ''), 'subtype' => sanitize_text_field($n['subtype']['name'] ?? ''), 'subtype_value' => sanitize_text_field($n['subtype']['value'] ?? ''));
            }
            $page++;
        } while ($page <= $total_pages);
        $out = array_values($by_id);
        usort($out, function ($a, $b) {
            $t = strcmp($a['type'], $b['type']);
            return $t ?: strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }
    public function create_money_transaction($input)
    {
        $mutation = 'mutation ($input: MoneyTransactionCreateInput!) { moneyTransactionCreate(input: $input) { didSucceed inputErrors { path message code } transaction { id } } }';
        $r = $this->request($mutation, array('input' => $input));
        if (is_wp_error($r)) return $r;
        $result = $r['data']['moneyTransactionCreate'] ?? array();
        if (empty($result['didSucceed'])) {
            $messages = array();
            foreach (($result['inputErrors'] ?? array()) as $error) {
                $messages[] = trim(implode('.', (array)($error['path'] ?? array())) . ': ' . ($error['message'] ?? 'Unknown input error'), ' :');
            }
            return new WP_Error('gf_wave_test_transaction_failed', $messages ? implode('; ', $messages) : 'Wave did not create the test transaction.', $result);
        }
        $transaction_id = $result['transaction']['id'] ?? '';
        if (!$transaction_id) return new WP_Error('gf_wave_test_transaction_missing_id', 'Wave reported success but did not return a transaction ID.', $result);
        return array('id' => sanitize_text_field($transaction_id));
    }
}

final class GF_Wave_Sync
{
    private static $instance;
    public static function instance()
    {
        if (!self::$instance) self::$instance = new self;
        return self::$instance;
    }
    private function __construct()
    {
        add_action('gform_after_submission', array($this, 'after_submission'), 10, 2);
        if (is_admin()) GF_Wave_Admin::instance();
    }
    public function after_submission($entry, $form)
    {
        $s = GF_Wave_Settings::get();
        if (empty($s['form_id']) || (int)$form['id'] !== (int)$s['form_id']) return;
        $id = (int)rgar($entry, 'id');
        $payment = $this->entry_value($entry, $s['field_payment']);
        $category = $this->entry_value($entry, $s['field_category']);
        $account = trim($category);
        $meta = array('wave_sync_status' => 'inbox_pending', 'wave_external_id' => '403TO-GF-' . $id, 'wave_business_id' => $s['business_id'], 'wave_business_name' => $s['business_name'], 'wave_account_name' => $account, 'wave_anchor_account' => '', 'wave_transaction_id' => '', 'wave_error' => '');
        foreach ($meta as $k => $v) gform_update_meta($id, $k, $v);
        $result = $this->send_entry_to_inbox($entry, $form, $s, $account);
        if (is_wp_error($result)) {
            gform_update_meta($id, 'wave_sync_status', 'inbox_failed');
            gform_update_meta($id, 'wave_error', $result->get_error_message());
            if (class_exists('GFFormsModel')) GFFormsModel::add_note($id, 0, 'Wave Sync', 'Transaction Inbox import failed: ' . $result->get_error_message());
            return;
        }
        if (class_exists('GFFormsModel')) GFFormsModel::add_note($id, 0, 'Wave Sync', sprintf('Added to Transaction Inbox as expense evidence. Observation ID: %d; category: %s; payment: %s.', (int)$result, $account ?: '(unmapped)', $payment ?: '(blank)'));
    }
    private function receipt_urls($entry, $field_id)
    {
        $raw = $this->entry_value($entry, $field_id);
        if ($raw === '') return array();
        $decoded = json_decode($raw, true);
        $items = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $raw);
        $urls = array();
        foreach ((array)$items as $item) {
            if (is_array($item)) $item = $item['url'] ?? $item['file'] ?? '';
            $url = esc_url_raw(trim((string)$item));
            if ($url !== '') $urls[] = $url;
        }
        return array_values(array_unique($urls));
    }
    private function inbox_database()
    {
        foreach (array('TO403_PAYMENTS_DB_HOST', 'TO403_PAYMENTS_DB_NAME', 'TO403_PAYMENTS_DB_USER', 'TO403_PAYMENTS_DB_PASSWORD') as $c) if (!defined($c) || constant($c) === '') throw new RuntimeException('Missing database constant ' . $c . '.');
        return new PDO('mysql:host=' . TO403_PAYMENTS_DB_HOST . ';dbname=' . TO403_PAYMENTS_DB_NAME . ';charset=utf8mb4', TO403_PAYMENTS_DB_USER, TO403_PAYMENTS_DB_PASSWORD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
    }
    private function send_entry_to_inbox($entry, $form, $s, $account)
    {
        try {
            $db = $this->inbox_database();
        } catch (Throwable $e) {
            return new WP_Error('gf_wave_inbox_database', $e->getMessage());
        }
        $id = (int)rgar($entry, 'id');
        $amount_raw = preg_replace('/[^0-9.\-]/', '', $this->entry_value($entry, $s['field_amount']));
        if ($amount_raw === '' || !is_numeric($amount_raw) || (float)$amount_raw <= 0) return new WP_Error('gf_wave_inbox_amount', 'The mapped amount is missing or invalid.');
        $date_raw = $this->entry_value($entry, $s['field_date']);
        $timestamp = strtotime(str_replace('/', '-', $date_raw));
        $date = $timestamp ? gmdate('Y-m-d', $timestamp) : '';
        if ($date === '') return new WP_Error('gf_wave_inbox_date', 'The mapped date is missing or invalid.');
        $payee = $this->entry_value($entry, $s['field_payee']);
        $description = $this->entry_value($entry, $s['field_description']);
        $name = $this->entry_value($entry, $s['field_name']);
        $email = $this->entry_value($entry, $s['field_email']);
        $payment = $this->entry_value($entry, $s['field_payment']);
        $category = $this->entry_value($entry, $s['field_category']);
        $external = $this->entry_value($entry, $s['field_external_id']);
        $payload = array('format' => 'gravity_forms', 'form_id' => (int)$form['id'], 'entry_id' => $id, 'submitter_name' => $name, 'submitter_email' => $email, 'payee' => $payee, 'description' => $description, 'payment_method' => $payment, 'submitted_category' => $category, 'export_account' => $account, 'external_id' => $external, 'receipt_urls' => $this->receipt_urls($entry, $s['field_receipt']));
        $source_key = 'gravity_forms:' . (int)$form['id'] . ':' . $id;
        $text = trim(implode(' | ', array_filter(array($payee, $description, $name))));
        if ($text === '') $text = 'Gravity Forms entry ' . $id;
        try {
            $db->beginTransaction();
            $q = $db->prepare("INSERT INTO inbox_observations (source_system,source_key,transaction_date,amount,direction,description,gf_entry_id,payload_json) VALUES ('gravity_forms',?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE transaction_date=VALUES(transaction_date),amount=VALUES(amount),direction=VALUES(direction),description=VALUES(description),gf_entry_id=VALUES(gf_entry_id),payload_json=VALUES(payload_json)");
            $q->execute(array($source_key, $date, number_format(abs((float)$amount_raw), 2, '.', ''), 'expense', $text, $id, wp_json_encode($payload)));
            $f = $db->prepare("SELECT observation_id FROM inbox_observations WHERE source_system='gravity_forms' AND source_key=? LIMIT 1");
            $f->execute(array($source_key));
            $oid = (int)$f->fetchColumn();
            if (!$oid) throw new RuntimeException('The saved Transaction Inbox observation could not be found.');
            $a = $db->prepare("INSERT INTO inbox_allocations (bank_observation_id,line_number,export_account,amount,allocation_note) VALUES (?,1,?,?,?) ON DUPLICATE KEY UPDATE export_account=VALUES(export_account),amount=VALUES(amount),allocation_note=VALUES(allocation_note)");
            $a->execute(array($oid, $account, number_format(abs((float)$amount_raw), 2, '.', ''), 'Submitted with Gravity Forms'));
            $db->commit();
            return $oid;
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            return new WP_Error('gf_wave_inbox_database', $e->getMessage());
        }
    }
    private function entry_value($entry, $field_id)
    {
        return '' === (string)$field_id ? '' : trim((string)rgar($entry, (string)$field_id));
    }
    public function export_entry_to_wave($entry, $s, $anchor_override = '')
    {
        $entry_id = (int)rgar($entry, 'id');
        if (gform_get_meta($entry_id, 'wave_transaction_id')) return new WP_Error('gf_wave_duplicate', 'This entry already has a Wave transaction ID.');
        $payment = $this->entry_value($entry, $s['field_payment']);
        $category = $this->entry_value($entry, $s['field_category']);
        $amount_raw = $this->entry_value($entry, $s['field_amount']);
        $date_raw = $this->entry_value($entry, $s['field_date']);
        $payee = $this->entry_value($entry, $s['field_payee']);
        $detail = $this->entry_value($entry, $s['field_description']);
        $external_id = $this->entry_value($entry, $s['field_external_id']);
        if (!$external_id) return new WP_Error('gf_wave_external_id', 'The mapped Wave external ID field is blank.');
        $description = trim($payee . ' (' . $external_id . ')' . ($detail ? ' - ' . $detail : ''));
        $amount = round((float)preg_replace('/[^0-9.\-]/', '', $amount_raw), 2);
        $timestamp = strtotime(str_replace('/', '-', $date_raw));
        $date = $timestamp ? gmdate('Y-m-d', $timestamp) : '';
        $category_id = $s['category_id_map'][$category] ?? '';
        $anchor_id = $anchor_override ?: ($s['anchor_id_map'][$payment] ?? '');
        $active = array();
        foreach ((array)$s['discovered_accounts'] as $a) if (empty($a['is_archived'])) $active[(string)$a['id']] = $a['name'];
        if (!$s['business_id']) return new WP_Error('gf_wave_business', 'No Wave business is selected.');
        if (!$category_id || !isset($active[$category_id])) return new WP_Error('gf_wave_category', 'The category does not resolve to an active Wave account.');
        if (!$anchor_id || !isset($active[$anchor_id])) return new WP_Error('gf_wave_anchor', 'The payment method does not resolve to an active Wave anchor account.');
        if ($amount <= 0) return new WP_Error('gf_wave_amount', 'The amount is not valid.');
        if (!$date) return new WP_Error('gf_wave_date', 'The date is not valid.');
        if (!$description) return new WP_Error('gf_wave_description', 'Payee and description are blank.');
        $input = array('businessId' => $s['business_id'], 'externalId' => $external_id, 'date' => $date, 'description' => $description, 'anchor' => array('accountId' => $anchor_id, 'amount' => $amount, 'direction' => 'WITHDRAWAL'), 'lineItems' => array(array('accountId' => $category_id, 'amount' => $amount, 'balance' => 'INCREASE')));
        gform_update_meta($entry_id, 'wave_sync_status', 'exporting');
        $r = (new GF_Wave_API())->create_money_transaction($input);
        if (is_wp_error($r)) return $r;
        gform_update_meta($entry_id, 'wave_sync_status', 'exported');
        gform_update_meta($entry_id, 'wave_transaction_id', $r['id']);
        gform_update_meta($entry_id, 'wave_external_id', $external_id);
        gform_update_meta($entry_id, 'wave_account_id', $category_id);
        gform_update_meta($entry_id, 'wave_anchor_account_id', $anchor_id);
        gform_update_meta($entry_id, 'wave_exported_at', current_time('mysql'));
        gform_update_meta($entry_id, 'wave_error', '');
        return array('id' => $r['id'], 'external_id' => $external_id);
    }
}

final class GF_Wave_Admin
{
    private static $instance;
    public static function instance()
    {
        if (!self::$instance) self::$instance = new self;
        return self::$instance;
    }
    private function __construct()
    {
        add_action('admin_menu', array($this, 'menu'), 20);
        add_action('admin_post_gf_wave_save', array($this, 'save'));
        add_action('admin_post_gf_wave_test', array($this, 'test'));
        add_action('admin_post_gf_wave_discover', array($this, 'discover'));
        add_action('admin_post_gf_wave_discover_accounts', array($this, 'discover_accounts'));
        add_action('admin_post_gf_wave_test_transaction', array($this, 'test_transaction'));
        add_filter('gform_entry_detail_meta_boxes', array($this, 'entry_meta_box'), 10, 3);
        add_action('admin_post_gf_wave_export_entry', array($this, 'export_entry'));
    }
    public function menu()
    {
        add_submenu_page(class_exists('GFForms') ? 'gf_edit_forms' : 'options-general.php', 'Wave Sync', 'Wave Sync', 'manage_options', 'gf-wave-sync', array($this, 'page'));
    }
    private function guard($a)
    {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer($a);
    }
    private static function parse_map($text, $payment = false)
    {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
            $line = trim($line);
            if (!$line || strpos($line, '#') === 0) continue;
            $p = array_map('trim', explode('|', $line));
            if (count($p) < 2) continue;
            if ($payment) $out[sanitize_text_field($p[0])] = array('status' => sanitize_key($p[1]), 'anchor' => sanitize_text_field($p[2] ?? ''));
            else $out[sanitize_text_field($p[0])] = sanitize_text_field($p[1]);
        }
        return $out;
    }
    private static function map_text($map, $payment = false)
    {
        $a = array();
        foreach ($map as $k => $v) $a[] = $payment ? $k . ' | ' . $v['status'] . ' | ' . ($v['anchor'] ?? '') : $k . ' | ' . $v;
        return implode("\n", $a);
    }
    public function save()
    {
        $this->guard('gf_wave_save');
        $old = GF_Wave_Settings::get();
        $s = $old;
        $s['form_id'] = absint($_POST['form_id'] ?? 0);
        $s['business_id'] = sanitize_text_field(wp_unslash($_POST['business_id'] ?? ''));
        $s['business_name'] = '';
        foreach ((array)$old['discovered_businesses'] as $b) if (isset($b['id']) && hash_equals((string)$b['id'], (string)$s['business_id'])) {
            $s['business_name'] = sanitize_text_field($b['name'] ?? '');
            break;
        }
        foreach (array('name', 'email', 'amount', 'date', 'category', 'payee', 'description', 'approver', 'payment', 'receipt', 'external_id') as $f) $s['field_' . $f] = sanitize_text_field(wp_unslash($_POST['field_' . $f] ?? ''));
        $s['category_map'] = self::parse_map(wp_unslash($_POST['category_map'] ?? ''));
        $s['payment_map'] = self::parse_map(wp_unslash($_POST['payment_map'] ?? ''), true);
        GF_Wave_Settings::update($s);
        wp_safe_redirect(add_query_arg(array('page' => 'gf-wave-sync', 'updated' => '1'), admin_url('admin.php')));
        exit;
    }
    public function test()
    {
        $this->guard('gf_wave_test');
        $r = (new GF_Wave_API)->test();
        $m = is_wp_error($r) ? $r->get_error_message() : 'Connection successful.';
        set_transient('gf_wave_notice_' . get_current_user_id(), array('type' => is_wp_error($r) ? 'error' : 'success', 'message' => $m), 60);
        $this->back();
    }
    public function discover()
    {
        $this->guard('gf_wave_discover');
        $r = (new GF_Wave_API)->businesses();
        if (is_wp_error($r)) $n = array('type' => 'error', 'message' => 'Business discovery failed: ' . $r->get_error_message());
        else {
            $s = GF_Wave_Settings::get();
            $s['discovered_businesses'] = $r;
            if (count($r) === 1) {
                $s['business_id'] = $r[0]['id'];
                $s['business_name'] = $r[0]['name'];
            } elseif ($s['business_id'] && !in_array($s['business_id'], wp_list_pluck($r, 'id'), true)) {
                $s['business_id'] = '';
                $s['business_name'] = '';
            }
            GF_Wave_Settings::update($s);
            $n = array('type' => $r ? 'success' : 'warning', 'message' => $r ? sprintf('Discovered %d Wave business(es). Select the correct business and save.', count($r)) : 'No Wave businesses were returned for this token.');
        }
        set_transient('gf_wave_notice_' . get_current_user_id(), $n, 60);
        $this->back();
    }
    public function discover_accounts()
    {
        $this->guard('gf_wave_discover_accounts');
        $s = GF_Wave_Settings::get();
        $r = (new GF_Wave_API())->accounts($s['business_id']);
        if (is_wp_error($r)) {
            $n = array('type' => 'error', 'message' => 'Account discovery failed: ' . $r->get_error_message());
        } else {
            $s['discovered_accounts'] = $r;
            $by_name = array();
            foreach ($r as $a) {
                if (empty($a['is_archived'])) $by_name[$a['name']] = $a['id'];
            }
            $s['category_id_map'] = $by_name;
            $s['anchor_id_map'] = array();
            foreach ($s['payment_map'] as $form_value => $rule) {
                $name = $rule['anchor'] ?? '';
                if ($name && isset($by_name[$name])) $s['anchor_id_map'][$form_value] = $by_name[$name];
            }
            GF_Wave_Settings::update($s);
            $active = count(array_filter($r, function ($a) {
                return empty($a['is_archived']);
            }));
            $n = array('type' => $r ? 'success' : 'warning', 'message' => $r ? sprintf('Discovered %d Wave account(s), including %d active account(s). Exact-name mappings were resolved automatically.', count($r), $active) : 'No Wave accounts were returned for this business.');
        }
        set_transient('gf_wave_notice_' . get_current_user_id(), $n, 60);
        $this->back();
    }
    public function test_transaction()
    {
        $this->guard('gf_wave_test_transaction');
        $s = GF_Wave_Settings::get();
        $anchor_id = sanitize_text_field(wp_unslash($_POST['test_anchor_id'] ?? ''));
        $category_id = sanitize_text_field(wp_unslash($_POST['test_category_id'] ?? ''));
        $amount = round((float)($_POST['test_amount'] ?? 0), 2);
        $date = sanitize_text_field(wp_unslash($_POST['test_date'] ?? ''));
        $description = sanitize_text_field(wp_unslash($_POST['test_description'] ?? '403TO Wave integration test'));
        $confirmed = !empty($_POST['confirm_live_transaction']);
        $active_ids = array();
        foreach ((array)$s['discovered_accounts'] as $account) {
            if (empty($account['is_archived'])) $active_ids[(string)$account['id']] = true;
        }
        if (!$s['business_id']) {
            $n = array('type' => 'error', 'message' => 'Select and save a Wave business first.');
        } elseif (!$confirmed) {
            $n = array('type' => 'error', 'message' => 'Confirm that this creates a real transaction in Wave.');
        } elseif (!isset($active_ids[$anchor_id]) || !isset($active_ids[$category_id])) {
            $n = array('type' => 'error', 'message' => 'Select active discovered Wave accounts for both the anchor and category.');
        } elseif ($anchor_id === $category_id) {
            $n = array('type' => 'error', 'message' => 'The anchor and categorization accounts must be different.');
        } elseif ($amount < 0.01) {
            $n = array('type' => 'error', 'message' => 'The test amount must be at least 0.01.');
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $n = array('type' => 'error', 'message' => 'Enter a valid transaction date in YYYY-MM-DD format.');
        } else {
            $external_id = '403TO-WAVE-TEST-' . gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);
            $input = array('businessId' => $s['business_id'], 'externalId' => $external_id, 'date' => $date, 'description' => $description, 'anchor' => array('accountId' => $anchor_id, 'amount' => $amount, 'direction' => 'WITHDRAWAL'), 'lineItems' => array(array('accountId' => $category_id, 'amount' => $amount, 'balance' => 'INCREASE')));
            $r = (new GF_Wave_API())->create_money_transaction($input);
            if (is_wp_error($r)) {
                $n = array('type' => 'error', 'message' => 'Test transaction failed: ' . $r->get_error_message());
            } else {
                update_option('gf_wave_last_test_transaction', array('id' => $r['id'], 'external_id' => $external_id, 'date' => $date, 'amount' => $amount, 'created_at' => current_time('mysql')), false);
                $n = array('type' => 'success', 'message' => 'Test transaction created in Wave. Transaction ID: ' . $r['id'] . '; external ID: ' . $external_id . '.');
            }
        }
        set_transient('gf_wave_notice_' . get_current_user_id(), $n, 120);
        $this->back();
    }
    private function back()
    {
        wp_safe_redirect(add_query_arg('page', 'gf-wave-sync', admin_url('admin.php')));
        exit;
    }
    private function account_by_name($settings, $name)
    {
        foreach ((array)$settings['discovered_accounts'] as $account) {
            if (empty($account['is_archived']) && 0 === strcasecmp(trim($account['name']), trim($name))) return $account;
        }
        return array();
    }
    private function entry_value($entry, $field_id)
    {
        return '' === (string)$field_id ? '' : trim((string)rgar($entry, (string)$field_id));
    }
    public function entry_meta_box($meta_boxes, $entry, $form)
    {
        $s = GF_Wave_Settings::get();
        if ((int)$form['id'] !== (int)$s['form_id']) return $meta_boxes;
        $meta_boxes['gf_wave_export'] = array('title' => 'Wave Accounting Export', 'callback' => array($this, 'render_entry_export'), 'context' => 'side', 'priority' => 'high');
        return $meta_boxes;
    }
    public function render_entry_export($args)
    {
        $entry = $args['entry'];
        $s = GF_Wave_Settings::get();
        $id = (int)rgar($entry, 'id');
        $payment = $this->entry_value($entry, $s['field_payment']);
        $category = $this->entry_value($entry, $s['field_category']);
        $amount = $this->entry_value($entry, $s['field_amount']);
        $date = $this->entry_value($entry, $s['field_date']);
        $payee = $this->entry_value($entry, $s['field_payee']);
        $detail = $this->entry_value($entry, $s['field_description']);
        $external_id = $this->entry_value($entry, $s['field_external_id']);
        $description = trim($payee . ($external_id ? ' (' . $external_id . ')' : '') . ($detail ? ' - ' . $detail : ''));
        $transaction_id = gform_get_meta($id, 'wave_transaction_id');
        $status = gform_get_meta($id, 'wave_sync_status') ?: 'not_processed';
        $category_id = $s['category_id_map'][$category] ?? '';
        $is_invoice = false !== stripos($payment, 'invoice requiring payment');
        $anchor_id = $s['anchor_id_map'][$payment] ?? '';
        $anchor_name = $s['payment_map'][$payment]['anchor'] ?? '';
        echo '<p><strong>Status:</strong> ' . esc_html($status) . '</p>';
        if ($transaction_id) {
            echo '<p><strong>Exported.</strong></p><p>Wave transaction ID:<br><code>' . esc_html($transaction_id) . '</code></p>';
            return;
        }
        echo '<p><strong>Payment:</strong><br>' . esc_html($payment ?: '(blank)') . '</p><p><strong>Category:</strong><br>' . esc_html($category ?: '(blank)') . '</p><p><strong>Amount:</strong> ' . esc_html($amount ?: '(blank)') . '</p><p><strong>Date:</strong> ' . esc_html($date ?: '(blank)') . '</p><p><strong>Description:</strong><br>' . esc_html($description ?: '(blank)') . '</p>';
        if (!$category_id) {
            echo '<p style="color:#b32d2e"><strong>Cannot export:</strong> the category is not mapped to a Wave account ID.</p>';
            return;
        }
        if (!$s['business_id']) {
            echo '<p style="color:#b32d2e"><strong>Cannot export:</strong> no Wave business is selected.</p>';
            return;
        }
        $operating = $this->account_by_name($s, 'Operating Acct');
        $cg = $this->account_by_name($s, 'CG Funds');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="gf_wave_export_entry"><input type="hidden" name="entry_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('gf_wave_export_entry_' . $id);
        if ($is_invoice) {
            echo '<p><label for="gf_wave_invoice_anchor"><strong>Pay invoice from</strong></label><br><select id="gf_wave_invoice_anchor" name="anchor_id" required><option value="">Select funding account</option>';
            foreach (array($operating, $cg) as $a) {
                if (!empty($a['id'])) echo '<option value="' . esc_attr($a['id']) . '">' . esc_html($a['name']) . '</option>';
            }
            echo '</select></p>';
            if (empty($operating['id']) || empty($cg['id'])) echo '<p style="color:#b32d2e">Run Discover Accounts again. Both Operating Acct and CG Funds are required for invoices.</p>';
        } else {
            echo '<input type="hidden" name="anchor_id" value="' . esc_attr($anchor_id) . '"><p><strong>Anchor:</strong><br>' . esc_html($anchor_name ?: '(unmapped)') . '</p>';
            if (!$anchor_id) {
                echo '<p style="color:#b32d2e"><strong>Cannot export:</strong> payment method is not mapped to an anchor account ID.</p></form>';
                return;
            }
        }
        echo '<p><label><input type="checkbox" name="confirm_export" value="1" required> Create this real Wave transaction.</label></p>';
        submit_button('Export Entry to Wave', 'primary', 'submit', false, array('onclick' => "return confirm('Create this transaction in Wave?');"));
        echo '</form>';
    }
    public function export_entry()
    {
        if (!current_user_can('gravityforms_edit_entries') && !current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $entry_id = absint($_POST['entry_id'] ?? 0);
        check_admin_referer('gf_wave_export_entry_' . $entry_id);
        $entry = GFAPI::get_entry($entry_id);
        $form_id = is_wp_error($entry) ? 0 : (int)$entry['form_id'];
        $return = admin_url('admin.php?page=gf_entries&view=entry&id=' . $form_id . '&lid=' . $entry_id);
        if (!$entry_id || empty($_POST['confirm_export'])) {
            wp_safe_redirect(add_query_arg('gfw_export', 'not_confirmed', $return));
            exit;
        }
        if (is_wp_error($entry)) wp_die(esc_html($entry->get_error_message()));
        $s = GF_Wave_Settings::get();
        if ($form_id !== (int)$s['form_id']) wp_die('This entry is not from the configured expense form.');
        $payment = $this->entry_value($entry, $s['field_payment']);
        $anchor_id = sanitize_text_field(wp_unslash($_POST['anchor_id'] ?? ''));
        $is_invoice = false !== stripos($payment, 'invoice requiring payment');
        if ($is_invoice) {
            $operating = $this->account_by_name($s, 'Operating Acct');
            $cg = $this->account_by_name($s, 'CG Funds');
            $allowed = array_filter(array($operating['id'] ?? '', $cg['id'] ?? ''));
            if (!in_array($anchor_id, $allowed, true)) {
                GFFormsModel::add_note($entry_id, 0, 'Wave Sync', 'Manual export failed: invoices must use Operating Acct or CG Funds.');
                wp_safe_redirect(add_query_arg('gfw_export', 'failed', $return));
                exit;
            }
        } else {
            $expected = $s['anchor_id_map'][$payment] ?? '';
            if ($anchor_id !== $expected) {
                GFFormsModel::add_note($entry_id, 0, 'Wave Sync', 'Manual export failed: anchor did not match payment rule.');
                wp_safe_redirect(add_query_arg('gfw_export', 'failed', $return));
                exit;
            }
        }
        $r = GF_Wave_Sync::instance()->export_entry_to_wave($entry, $s, $anchor_id);
        if (is_wp_error($r)) {
            gform_update_meta($entry_id, 'wave_sync_status', 'export_failed');
            gform_update_meta($entry_id, 'wave_error', $r->get_error_message());
            GFFormsModel::add_note($entry_id, 0, 'Wave Sync', 'Manual export failed: ' . $r->get_error_message());
            wp_safe_redirect(add_query_arg('gfw_export', 'failed', $return));
            exit;
        }
        GFFormsModel::add_note($entry_id, 0, 'Wave Sync', 'Manually exported to Wave. Transaction ID: ' . $r['id'] . '; external ID: ' . $r['external_id'] . '.');
        wp_safe_redirect(add_query_arg('gfw_export', 'success', $return));
        exit;
    }
    private function field_select($form, $key, $label, $selected)
    {
        echo '<tr><th><label for="field_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><select id="field_' . esc_attr($key) . '" name="field_' . esc_attr($key) . '"><option value="">Not mapped</option>';
        if ($form && !is_wp_error($form)) foreach ($form['fields'] as $field) {
            if (!empty($field->inputs)) foreach ($field->inputs as $i) {
                $id = (string)$i['id'];
                echo '<option value="' . esc_attr($id) . '" ' . selected($selected, $id, false) . '>' . esc_html($field->label . ' - ' . ($i['label'] ?? $id) . ' (' . $id . ')') . '</option>';
            }
            else {
                $id = (string)$field->id;
                echo '<option value="' . esc_attr($id) . '" ' . selected($selected, $id, false) . '>' . esc_html($field->label . ' (' . $id . ')') . '</option>';
            }
        }
        echo '</select></td></tr>';
    }
    public function page()
    {
        $s = GF_Wave_Settings::get();
        $forms = class_exists('GFAPI') ? GFAPI::get_forms() : array();
        $form = $s['form_id'] && class_exists('GFAPI') ? GFAPI::get_form($s['form_id']) : null;
        $n = get_transient('gf_wave_notice_' . get_current_user_id());
        if ($n) delete_transient('gf_wave_notice_' . get_current_user_id());
?><div class="wrap">
            <h1>Gravity Forms Wave Sync</h1><?php if (isset($_GET['updated'])): ?><div class="notice notice-success">
                    <p>Settings saved.</p>
                </div><?php endif; ?><?php if ($n): ?><div class="notice notice-<?php echo esc_attr($n['type']); ?>">
                    <p><?php echo esc_html($n['message']); ?></p>
                </div><?php endif; ?>
            <style>
                .gfw-card {
                    background: #fff;
                    border: 1px solid #dcdcde;
                    padding: 18px 22px;
                    margin: 18px 0;
                    max-width: 1050px
                }

                .gfw-actions {
                    display: flex;
                    gap: 10px
                }

                .gfw-actions form {
                    margin: 0
                }

                .gfw-ok {
                    color: #087f23;
                    font-weight: 600
                }

                .gfw-bad {
                    color: #b32d2e;
                    font-weight: 600
                }
            </style>
            <div class="gfw-card">
                <h2>Wave connection</h2>
                <p>Add <code>define( 'GFWAVE_ACCESS_TOKEN', 'your-token' );</code> to <code>wp-config.php</code>.</p>
                <p><strong>Token:</strong> <?php echo GF_Wave_Settings::token() ? '<span class="gfw-ok">configured</span>' : '<span class="gfw-bad">not configured</span>'; ?></p>
                <div class="gfw-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="gf_wave_test"><?php wp_nonce_field('gf_wave_test');
                                                                                                                                                            submit_button('Test Wave Connection', 'secondary', 'submit', false); ?></form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="gf_wave_discover"><?php wp_nonce_field('gf_wave_discover');
                                                                                                                                                                submit_button('Discover Businesses', 'secondary', 'submit', false); ?></form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="gf_wave_discover_accounts"><?php wp_nonce_field('gf_wave_discover_accounts');
                                                                                                                                                                            submit_button('Discover Accounts', 'secondary', 'submit', false); ?></form>
                </div>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="gf_wave_save"><?php wp_nonce_field('gf_wave_save'); ?>
                <div class="gfw-card">
                    <h2>Wave business</h2><select name="business_id">
                        <option value="">Discover and select a business</option><?php foreach ((array)$s['discovered_businesses'] as $b): ?><option value="<?php echo esc_attr($b['id']); ?>" <?php selected($s['business_id'], $b['id']); ?>><?php echo esc_html($b['name'] . (!empty($b['is_personal']) ? ' (personal)' : '')); ?></option><?php endforeach; ?>
                    </select><?php if ($s['business_id']): ?><p class="description">API ID: <code><?php echo esc_html($s['business_id']); ?></code></p><?php endif; ?>
                </div>
                <?php if (!empty($s['discovered_accounts'])): $active_accounts = array_filter($s['discovered_accounts'], function ($a) {
                        return empty($a['is_archived']);
                    }); ?>
                    <div class="gfw-card">
                        <h2>Discovered Wave accounts</h2>
                        <p><?php echo esc_html(count($active_accounts)); ?> active account(s) available. Archived accounts are retained for reference but will not be auto-mapped.</p>
                        <details>
                            <summary>Show account details and API IDs</summary>
                            <table class="widefat striped" style="margin-top:12px">
                                <thead>
                                    <tr>
                                        <th>Account</th>
                                        <th>Type</th>
                                        <th>Subtype</th>
                                        <th>Status</th>
                                        <th>API ID</th>
                                    </tr>
                                </thead>
                                <tbody><?php foreach ($s['discovered_accounts'] as $a): ?><tr>
                                            <td><?php echo esc_html($a['name']); ?></td>
                                            <td><?php echo esc_html($a['type']); ?></td>
                                            <td><?php echo esc_html($a['subtype']); ?></td>
                                            <td><?php echo !empty($a['is_archived']) ? 'Archived' : 'Active'; ?></td>
                                            <td><code><?php echo esc_html($a['id']); ?></code></td>
                                        </tr><?php endforeach; ?></tbody>
                            </table>
                        </details>
                        <p><strong>Resolved mappings:</strong> <?php echo esc_html(count($s['category_id_map'])); ?> category account(s), <?php echo esc_html(count($s['anchor_id_map'])); ?> anchor account(s).</p>
                    </div><?php endif; ?>
                <div class="gfw-card">
                    <h2>Expense form and field mapping</h2>
                    <table class="form-table">
                        <tr>
                            <th>Gravity Form</th>
                            <td><select name="form_id">
                                    <option value="0">Select a form</option><?php foreach ($forms as $f): ?><option value="<?php echo (int)$f['id']; ?>" <?php selected($s['form_id'], $f['id']); ?>><?php echo esc_html($f['title'] . ' (#' . $f['id'] . ')'); ?></option><?php endforeach; ?>
                                </select>
                                <p class="description">Save after changing the form, then map its fields.</p>
                            </td>
                        </tr><?php $labels = array('name' => 'Submitter name', 'email' => 'Email', 'amount' => 'Total amount', 'date' => 'Invoice or receipt date', 'category' => 'Program/expense category', 'payee' => 'Payee name', 'description' => 'Brief description', 'approver' => 'Approving Scouter', 'payment' => 'Payment information', 'receipt' => 'Receipt upload', 'external_id' => 'Wave external ID');
                                foreach ($labels as $k => $v) $this->field_select($form, $k, $v, $s['field_' . $k]); ?>
                    </table>
                </div>
                <div class="gfw-card">
                    <h2>Expense categories</h2>
                    <p>Gravity Forms category values are used directly as exact Wave account names. No translation table is required.</p><input type="hidden" name="category_map" value="">
                </div>
                <div class="gfw-card">
                    <h2>Payment workflow mapping</h2>
                    <p>One per line: <code>form value | status | Wave anchor account</code></p><textarea name="payment_map" rows="6" class="large-text code"><?php echo esc_textarea(self::map_text($s['payment_map'], true)); ?></textarea>
                </div><?php submit_button('Save Wave Sync Settings'); ?>
            </form>
            <?php if (!empty($s['discovered_accounts']) && !empty($s['business_id'])): $active_accounts = array_values(array_filter($s['discovered_accounts'], function ($a) {
                    return empty($a['is_archived']);
                }));
                $default_anchor = reset($s['anchor_id_map']) ?: '';
                $default_category = reset($s['category_id_map']) ?: '';
                $last_test = get_option('gf_wave_last_test_transaction', array()); ?>
                <div class="gfw-card">
                    <h2>Test Wave transaction</h2>
                    <p><strong>Warning:</strong> This creates a real transaction in the selected Wave business. Use a small amount and delete or reverse the transaction in Wave after verification.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="gf_wave_test_transaction"><?php wp_nonce_field('gf_wave_test_transaction'); ?><table class="form-table">
                            <tr>
                                <th><label for="test_anchor_id">Anchor account</label></th>
                                <td><select id="test_anchor_id" name="test_anchor_id" required>
                                        <option value="">Select an active account</option><?php foreach ($active_accounts as $a): ?><option value="<?php echo esc_attr($a['id']); ?>" <?php selected($default_anchor, $a['id']); ?>><?php echo esc_html($a['name'] . ' — ' . $a['type'] . ' / ' . $a['subtype']); ?></option><?php endforeach; ?>
                                    </select>
                                    <p class="description">For a card purchase, select GC Visa.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="test_category_id">Categorization account</label></th>
                                <td><select id="test_category_id" name="test_category_id" required>
                                        <option value="">Select an active account</option><?php foreach ($active_accounts as $a): ?><option value="<?php echo esc_attr($a['id']); ?>" <?php selected($default_category, $a['id']); ?>><?php echo esc_html($a['name'] . ' — ' . $a['type'] . ' / ' . $a['subtype']); ?></option><?php endforeach; ?>
                                    </select>
                                    <p class="description">Select the expense or Cost of Goods Sold account to increase.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="test_amount">Amount</label></th>
                                <td><input id="test_amount" name="test_amount" type="number" min="0.01" step="0.01" value="0.01" required></td>
                            </tr>
                            <tr>
                                <th><label for="test_date">Date</label></th>
                                <td><input id="test_date" name="test_date" type="date" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>" required></td>
                            </tr>
                            <tr>
                                <th><label for="test_description">Description</label></th>
                                <td><input id="test_description" name="test_description" class="regular-text" value="403TO Wave integration test" required></td>
                            </tr>
                            <tr>
                                <th>Confirmation</th>
                                <td><label><input type="checkbox" name="confirm_live_transaction" value="1" required> I understand this creates a real transaction in Wave.</label></td>
                            </tr>
                        </table><?php submit_button('Create Test Transaction', 'secondary'); ?></form>
                    <?php if (!empty($last_test['id'])): ?><p><strong>Last test:</strong> <?php echo esc_html(($last_test['created_at'] ?? '') . ' — ' . ($last_test['amount'] ?? '') . ' — Transaction ID ' . ($last_test['id'] ?? '')); ?></p><?php endif; ?>
                </div><?php endif; ?>
            <div class="gfw-card">
                <h2>Version 0.7.0 behaviour</h2>
                <ul>
                    <li>Discovers authorized Wave businesses and stores the selected API business ID.</li>
                    <li>Discovers the selected business chart of accounts, including account IDs, types, subtypes, and archived status.</li>
                    <li>Resolves exact category and payment-anchor account names to stable Wave account IDs.</li>
                    <li>Creates workflow metadata and an entry note after submission.</li>
                    <li>Can create a manually confirmed test transaction in Wave.</li>
                    <li>Adds a duplicate-protected manual export panel to configured Gravity Forms entries.</li>
                    <li>Credit-card entries use GC Visa; reimbursements use Operating Acct; invoices require Operating Acct or CG Funds selection.</li>
                    <li>Adds configured submissions to Transaction Inbox as expense evidence.</li>
                    <li>Keeps Wave export under human review; the existing manual per-entry export remains available.</li>
                    <li>Stores the mapped category, submitter details, request ID, and receipt URLs in the evidence payload.</li>
                    <li>Uses the mapped form field as the Wave external ID.</li>
                    <li>Formats transaction descriptions as Payee (request ID) - Brief description.</li>
                    <li>Does not upload receipts to Wave.</li>
                </ul>
            </div>
        </div><?php
            }
        }
        register_activation_hook(__FILE__, array('GF_Wave_Settings', 'activate'));
        add_action('plugins_loaded', array('GF_Wave_Sync', 'instance'));
