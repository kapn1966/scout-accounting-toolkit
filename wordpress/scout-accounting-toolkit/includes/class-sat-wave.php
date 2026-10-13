<?php
/**
 * Wave Accounting connection: GraphQL requests, business and chart-of-accounts discovery.
 *
 * wp-config.php: define('SAT_WAVE_TOKEN', '...');
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Wave
{
    private const ENDPOINT = 'https://gql.waveapps.com/graphql/public';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('admin_post_sat_wave_test', [$this, 'action_test']);
        add_action('admin_post_sat_wave_discover', [$this, 'action_discover']);
        add_action('admin_post_sat_wave_discover_accounts', [$this, 'action_discover_accounts']);
    }

    /** @return array|WP_Error */
    public function request(string $query, array $variables = [])
    {
        $token = SAT_Settings::wave_token();
        if ($token === '') {
            return new WP_Error('sat_wave_token', 'Define SAT_WAVE_TOKEN in wp-config.php.');
        }
        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'query' => $query,
                'variables' => $variables ? (object)$variables : new stdClass(),
            ]),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('sat_wave_http', 'Wave returned HTTP ' . (int)$code . '.', $body);
        }
        if (!is_array($body)) {
            return new WP_Error('sat_wave_json', 'Wave returned invalid JSON.');
        }
        if (!empty($body['errors'])) {
            return new WP_Error('sat_wave_graphql', wp_json_encode($body['errors']), $body);
        }
        return $body;
    }

    /** @return array|WP_Error */
    public function businesses()
    {
        $result = $this->request('query { businesses(page: 1, pageSize: 100) { edges { node { id name isPersonal } } } }');
        if (is_wp_error($result)) {
            return $result;
        }
        $out = [];
        foreach (($result['data']['businesses']['edges'] ?? []) as $edge) {
            $node = $edge['node'] ?? [];
            if (empty($node['id']) || empty($node['name'])) {
                continue;
            }
            $out[] = [
                'id' => sanitize_text_field($node['id']),
                'name' => sanitize_text_field($node['name']),
                'is_personal' => !empty($node['isPersonal']),
            ];
        }
        return $out;
    }

    /** Every account in the business, all pages. @return array|WP_Error */
    public function accounts(string $business_id)
    {
        if ($business_id === '') {
            return new WP_Error('sat_wave_business', 'Select and save a Wave business first.');
        }
        $query = 'query ($businessId: ID!, $page: Int!, $pageSize: Int!) { business(id: $businessId) { accounts(page: $page, pageSize: $pageSize, isArchived: null) { pageInfo { totalPages } edges { node { id name isArchived type { name value } subtype { name value } } } } } }';
        $page = 1;
        $pages = 1;
        $by_id = [];
        do {
            $result = $this->request($query, ['businessId' => $business_id, 'page' => $page, 'pageSize' => 100]);
            if (is_wp_error($result)) {
                return $result;
            }
            $accounts = $result['data']['business']['accounts'] ?? [];
            $pages = max(1, (int)($accounts['pageInfo']['totalPages'] ?? 1));
            foreach (($accounts['edges'] ?? []) as $edge) {
                $node = $edge['node'] ?? [];
                if (empty($node['id']) || empty($node['name'])) {
                    continue;
                }
                $id = sanitize_text_field($node['id']);
                $by_id[$id] = [
                    'id' => $id,
                    'name' => sanitize_text_field($node['name']),
                    'is_archived' => !empty($node['isArchived']),
                    'type' => sanitize_text_field($node['type']['name'] ?? ''),
                    'type_value' => sanitize_text_field($node['type']['value'] ?? ''),
                    'subtype' => sanitize_text_field($node['subtype']['name'] ?? ''),
                    'subtype_value' => sanitize_text_field($node['subtype']['value'] ?? ''),
                ];
            }
            $page++;
        } while ($page <= $pages);
        $out = array_values($by_id);
        usort($out, function ($a, $b) {
            return strcmp($a['type'], $b['type']) ?: strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /**
     * Create one standard Wave money transaction.
     *
     * The export preview service deliberately calls only this singular
     * mutation. It does not use Wave's bulk mutation or update/delete APIs.
     *
     * @return array|WP_Error Array containing the new Wave transaction ID.
     */
    public function create_money_transaction(array $input)
    {
        $mutation = 'mutation ($input: MoneyTransactionCreateInput!) {
            moneyTransactionCreate(input: $input) {
                didSucceed
                inputErrors { path message code }
                transaction { id }
            }
        }';
        $result = $this->request($mutation, ['input' => $input]);
        if (is_wp_error($result)) {
            return $result;
        }
        $created = $result['data']['moneyTransactionCreate'] ?? [];
        if (empty($created['didSucceed'])) {
            $messages = [];
            foreach (($created['inputErrors'] ?? []) as $error) {
                $path = implode('.', (array)($error['path'] ?? []));
                $message = (string)($error['message'] ?? 'Unknown input error');
                $messages[] = trim($path . ': ' . $message, ' :');
            }
            return new WP_Error(
                'sat_wave_transaction_failed',
                $messages ? implode('; ', $messages) : 'Wave did not create the transaction.',
                $created
            );
        }
        $id = sanitize_text_field($created['transaction']['id'] ?? '');
        if ($id === '') {
            return new WP_Error('sat_wave_transaction_missing_id', 'Wave reported success but did not return a transaction ID.', $created);
        }
        return ['id' => $id];
    }

    /* ------------------------------------------------------------------ */
    /* Admin actions (buttons on the Wave connection settings tab)         */
    /* ------------------------------------------------------------------ */

    private function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer($action);
    }

    public function action_test(): void
    {
        $this->guard('sat_wave_test');
        $result = $this->request('query { user { id } }');
        SAT_Admin::back('wave', is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Connection successful.');
    }

    public function action_discover(): void
    {
        $this->guard('sat_wave_discover');
        $result = $this->businesses();
        if (is_wp_error($result)) {
            SAT_Admin::back('wave', 'error', 'Business discovery failed: ' . $result->get_error_message());
        }
        $wave = SAT_Settings::get('wave');
        $wave['discovered_businesses'] = $result;
        if (count($result) === 1) {
            $wave['business_id'] = $result[0]['id'];
            $wave['business_name'] = $result[0]['name'];
        } elseif ($wave['business_id'] && !in_array($wave['business_id'], wp_list_pluck($result, 'id'), true)) {
            $wave['business_id'] = '';
            $wave['business_name'] = '';
        }
        SAT_Settings::update('wave', $wave);
        SAT_Admin::back('wave', 'success', sprintf('Found %d Wave business(es). Select the right one and save.', count($result)));
    }

    public function action_discover_accounts(): void
    {
        $this->guard('sat_wave_discover_accounts');
        $wave = SAT_Settings::get('wave');
        $result = $this->accounts((string)$wave['business_id']);
        if (is_wp_error($result)) {
            SAT_Admin::back('wave', 'error', 'Account discovery failed: ' . $result->get_error_message());
        }
        $wave['discovered_accounts'] = $result;
        SAT_Settings::update('wave', $wave);
        $active = count(array_filter($result, function ($a) {
            return empty($a['is_archived']);
        }));
        SAT_Admin::back('wave', 'success', sprintf('Found %d Wave accounts, %d active.', count($result), $active));
    }
}
