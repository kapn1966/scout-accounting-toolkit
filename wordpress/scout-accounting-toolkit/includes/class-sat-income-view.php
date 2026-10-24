<?php
/**
 * Read-only projection of Interac income and incoming OFX observations.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Income_View
{
    private static ?self $instance = null;

    public const PARAMS = [
        'income_month',
        'income_account',
        'income_section',
        'income_evidence',
        'income_sort',
        'income_order',
    ];

    public const EVIDENCE = [
        'interac_ofx' => 'Interac + OFX',
        'interac' => 'Interac',
        'ofx' => 'OFX',
    ];

    public const SORTS = [
        'date' => 'Date',
        'account' => 'Account',
        'amount' => 'Amount',
        'payer' => 'Payer',
    ];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Derive logical income rows without changing or linking source observations.
     *
     * @param array<int,array<string,mixed>> $interac
     * @param array<int,array<string,mixed>> $ofx
     * @param array<int,bool|int> $excluded_ofx_ids
     * @param array<string,array{label?:string}> $account_labels
     * @return array<int,array<string,mixed>>
     */
    public static function project(
        array $interac,
        array $ofx,
        array $excluded_ofx_ids = [],
        array $account_labels = []
    ): array {
        $interac_rows = [];
        foreach ($interac as $observation) {
            $row = self::observation_row($observation, 'interac', $account_labels);
            if ($row !== null) {
                $interac_rows[] = $row;
            }
        }

        $ofx_rows = [];
        foreach ($ofx as $observation) {
            $id = (int)($observation['observation_id'] ?? 0);
            if (isset($excluded_ofx_ids[$id])) {
                continue;
            }
            $row = self::observation_row($observation, 'ofx', $account_labels);
            if ($row !== null) {
                $ofx_rows[] = $row;
            }
        }

        self::sort_observations($interac_rows);
        self::sort_observations($ofx_rows);
        $ofx_by_date_amount = [];
        foreach ($ofx_rows as $index => $row) {
            $key = $row['date'] . '|' . $row['amount_cents'];
            $ofx_by_date_amount[$key][] = $index;
        }

        $used_ofx = [];
        $rows = [];
        foreach ($interac_rows as $interac_row) {
            $key = $interac_row['date'] . '|' . $interac_row['amount_cents'];
            $paired_index = null;
            foreach ($ofx_by_date_amount[$key] ?? [] as $index) {
                if (!isset($used_ofx[$index])
                    && self::same_payer($interac_row['payer'], $ofx_rows[$index]['payer'])) {
                    $paired_index = $index;
                    break;
                }
            }

            if ($paired_index === null) {
                $interac_row['evidence'] = 'interac';
                $rows[] = $interac_row;
                continue;
            }

            $used_ofx[$paired_index] = true;
            $ofx_row = $ofx_rows[$paired_index];
            $rows[] = [
                'date' => $ofx_row['date'],
                'account' => $ofx_row['account'],
                'amount_cents' => $ofx_row['amount_cents'],
                'payer' => $interac_row['payer'] !== '' ? $interac_row['payer'] : $ofx_row['payer'],
                'description' => $interac_row['description'] !== '' ? $interac_row['description'] : $ofx_row['description'],
                'reference' => $interac_row['reference'],
                'section' => $interac_row['section'],
                'evidence' => 'interac_ofx',
                '_stable_id' => $ofx_row['_stable_id'],
            ];
        }

        foreach ($ofx_rows as $index => $row) {
            if (!isset($used_ofx[$index])) {
                $row['evidence'] = 'ofx';
                $rows[] = $row;
            }
        }

        return self::sort_rows($rows, ['sort' => 'date', 'order' => 'desc']);
    }

    /** Normalize request values without relying on mutable WordPress query state. */
    public static function filters(array $query): array
    {
        $value = static function (string $name) use ($query): string {
            $item = $query[$name] ?? '';
            return is_scalar($item) ? trim((string)$item) : '';
        };
        $month = $value('income_month');
        if (!self::valid_month($month)) {
            $month = '';
        }
        $evidence = $value('income_evidence');
        $sort = $value('income_sort');
        $order = $value('income_order');

        return [
            'month' => $month,
            'account' => $value('income_account'),
            'section' => $value('income_section'),
            'evidence' => isset(self::EVIDENCE[$evidence]) ? $evidence : '',
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'date',
            'order' => in_array($order, ['asc', 'desc'], true) ? $order : 'desc',
        ];
    }

    /** Apply filters and sorting only after evidence has been paired. */
    public static function select(array $rows, array $filters): array
    {
        $selected = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            if ($filters['month'] !== '' && !str_starts_with((string)$row['date'], $filters['month'] . '-')) {
                return false;
            }
            if ($filters['account'] === '__unconfirmed__' && $row['account'] !== '') {
                return false;
            }
            if ($filters['account'] !== '' && $filters['account'] !== '__unconfirmed__' && $row['account'] !== $filters['account']) {
                return false;
            }
            if ($filters['section'] === '__no_section__' && $row['section'] !== '') {
                return false;
            }
            if ($filters['section'] !== '' && $filters['section'] !== '__no_section__' && $row['section'] !== $filters['section']) {
                return false;
            }
            return $filters['evidence'] === '' || $row['evidence'] === $filters['evidence'];
        }));
        return self::sort_rows($selected, $filters);
    }

    /** @return array{transactions:int,total_cents:int} */
    public static function summary(array $rows): array
    {
        return [
            'transactions' => count($rows),
            'total_cents' => array_sum(array_column($rows, 'amount_cents')),
        ];
    }

    /** Retrieve source observations and build their transient projection. */
    public function rows(array $query): array
    {
        return self::select($this->unfiltered_rows(), self::filters($query));
    }

    private function unfiltered_rows(): array
    {
        $db = SAT_DB::pdo();
        $interac = $db->query(
            "SELECT * FROM inbox_observations
             WHERE source_system = 'interac_email' AND direction = 'income' AND amount > 0
             ORDER BY transaction_date, observation_id"
        )->fetchAll();
        $ofx = $db->query(
            "SELECT * FROM inbox_observations
             WHERE source_system LIKE 'ofx\\_%' AND amount > 0
             ORDER BY transaction_date, observation_id"
        )->fetchAll();
        $accounts = SAT_Settings::banks();
        $transfer_ids = SAT_Transfer_OFX::instance()->matched_transfer_observation_ids();
        return self::project($interac, $ofx, $transfer_ids, $accounts);
    }

    public function render(): void
    {
        $all = $this->unfiltered_rows();
        $filters = self::filters((array)wp_unslash($_GET));
        $rows = self::select($all, $filters);
        $summary = self::summary($rows);
        $this->render_controls($filters, $all, $rows, $summary);
    }

    /**
     * Render filters and a read-only logical-row table.
     *
     * $all supplies the available filter options; totals use only $rows.
     */
    private function render_controls(array $filters, array $all, array $rows, array $summary): void
    {
        $accounts = [];
        $sections = [];
        $months = [];
        foreach ($all as $row) {
            $account_key = $row['account'] === '' ? '__unconfirmed__' : $row['account'];
            $section_key = $row['section'] === '' ? '__no_section__' : $row['section'];
            $accounts[$account_key] = $row['account'] === '' ? 'Not yet bank-confirmed' : $row['account'];
            $sections[$section_key] = $row['section'] === '' ? 'No section recorded' : $row['section'];
            if (preg_match('/^[12]\d{3}-\d{2}-\d{2}$/D', $row['date'])) {
                $months[substr($row['date'], 0, 7)] = substr($row['date'], 0, 7);
            }
        }
        krsort($months, SORT_STRING);
        asort($accounts, SORT_NATURAL | SORT_FLAG_CASE);
        asort($sections, SORT_NATURAL | SORT_FLAG_CASE);

        echo '<p class="help">Read-only income observations. Interac and incoming OFX evidence are paired for display only.</p>';
        echo '<dl class="income-summary"><div><dt>Transactions</dt><dd>' . (int)$summary['transactions'] . '</dd></div>';
        echo '<div><dt>Total</dt><dd>$' . esc_html(self::format_cents($summary['total_cents'])) . '</dd></div></dl>';
        echo '<form method="get" class="filters">';
        foreach (['page', 'sat_view'] as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
                echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string)$_GET[$key]) . '">';
            }
        }
        $this->select_control('income_month', 'Month', $months, $filters['month'], 'All months');
        $this->select_control('income_account', 'Account', $accounts, $filters['account'], 'All accounts');
        $this->select_control('income_section', 'Section', $sections, $filters['section'], 'All sections');
        $this->select_control('income_evidence', 'Evidence', self::EVIDENCE, $filters['evidence'], 'All evidence');
        $this->select_control('income_sort', 'Sort by', self::SORTS, $filters['sort'], null);
        $this->select_control('income_order', 'Order', ['desc' => 'Descending', 'asc' => 'Ascending'], $filters['order'], null);
        echo '<button type="submit">Filter</button> ';
        echo '<a class="clear-filters" href="' . esc_url(remove_query_arg(self::PARAMS)) . '">Clear filters</a></form>';
        echo '<div class="scroll"><table class="income"><thead><tr>';
        echo '<th>Date</th><th>Account</th><th class="money">Amount</th><th>Payer</th><th>Description</th><th>Reference</th><th>Section</th><th>Evidence</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['date']) . '</td>';
            echo '<td>' . esc_html($row['account'] !== '' ? $row['account'] : '—') . '</td>';
            echo '<td class="money">$' . esc_html(self::format_cents($row['amount_cents'])) . '</td>';
            echo '<td>' . esc_html($row['payer'] !== '' ? $row['payer'] : '—') . '</td>';
            echo '<td>' . esc_html($row['description'] !== '' ? $row['description'] : '—') . '</td>';
            echo '<td>' . esc_html($row['reference'] !== '' ? $row['reference'] : '—') . '</td>';
            echo '<td>' . esc_html($row['section'] !== '' ? $row['section'] : '—') . '</td>';
            echo '<td><span class="pill income-evidence">' . esc_html(self::EVIDENCE[$row['evidence']]) . '</span></td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="8" class="empty">' . ($all ? 'No income transactions match these filters.' : 'No income observations yet.') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function select_control(string $name, string $label, array $options, string $selected, ?string $all_label): void
    {
        echo '<label>' . esc_html($label) . '<select name="' . esc_attr($name) . '">';
        if ($all_label !== null) {
            echo '<option value="">' . esc_html($all_label) . '</option>';
        }
        foreach ($options as $value => $text) {
            echo '<option value="' . esc_attr((string)$value) . '"' . selected($selected, (string)$value, false) . '>'
                . esc_html((string)$text) . '</option>';
        }
        echo '</select></label>';
    }

    private static function observation_row(array $observation, string $type, array $account_labels): ?array
    {
        $payload = json_decode((string)($observation['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $amount = $type === 'interac'
            ? ($payload['amount'] ?? $observation['amount'] ?? '')
            : ($observation['amount'] ?? $payload['amount'] ?? '');
        $cents = self::amount_cents($amount);
        $date = $type === 'interac'
            ? (string)($payload['payment_date'] ?? $observation['transaction_date'] ?? '')
            : (string)($observation['transaction_date'] ?? '');
        if ($cents === null || $cents <= 0 || !self::valid_date($date)) {
            return null;
        }

        $source = (string)($observation['source_system'] ?? '');
        $account = '';
        if ($type === 'ofx') {
            $account = (string)($account_labels[$source]['label'] ?? '');
            if ($account === '' || $account === $source) {
                $account = (string)($payload['account_name'] ?? 'Unconfigured account');
            }
            if ($account === '') {
                $account = 'Unconfigured account';
            }
        }
        if ($type === 'interac') {
            $payer = (string)($payload['payer_name'] ?? $payload['payer'] ?? '');
            $description = (string)($payload['payment_message'] ?? '');
            $reference = (string)($payload['reference_number'] ?? $observation['payments_reference_number'] ?? '');
            $section = (string)($payload['section'] ?? '');
        } else {
            $name = (string)($payload['name'] ?? '');
            $memo = (string)($payload['memo'] ?? '');
            $payer = self::ofx_payer($name, $memo);
            $description = $memo;
            if ($description === '') {
                $description = (string)($observation['description'] ?? $payer);
            }
            $reference = (string)($payload['reference_number'] ?? $payload['refnum'] ?? '');
            $section = '';
        }
        if ($type === 'interac' && $description === '') {
            $description = (string)($observation['description'] ?? '');
        }
        return [
            'date' => $date,
            'account' => $account,
            'amount_cents' => $cents,
            'payer' => trim($payer),
            'description' => trim($description),
            'reference' => trim($reference),
            'section' => trim($section),
            '_stable_id' => (int)($observation['observation_id'] ?? 0),
        ];
    }

    private static function ofx_payer(string $name, string $memo): string
    {
        if (preg_match('/^\s*E-TRANSFER\b/i', $name) === 1
            && preg_match('/^\s*FROM\s+(.+?)\s*$/iu', $memo, $match) === 1) {
            return trim($match[1]);
        }
        return trim($name);
    }

    private static function amount_cents(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string)$value);
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d{1,2}))?$/D', $value, $match)) {
            return null;
        }
        $whole = ltrim($match[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($match[3] ?? '', 2, '0');
        $digits = $whole . $fraction;
        if (strlen($digits) > 15) {
            return null;
        }
        $cents = (int)$digits;
        return ($match[1] ?? '') === '-' ? -$cents : $cents;
    }

    private static function same_payer(string $left, string $right): bool
    {
        $left = self::normalize_payer($left);
        $right = self::normalize_payer($right);
        if ($left === '' || $right === '') {
            return false;
        }
        $matched = preg_match('/\A' . preg_quote($left, '/') . '\z/iu', $right);
        return $matched === 1 || ($matched === false && strcasecmp($left, $right) === 0);
    }

    private static function normalize_payer(string $payer): string
    {
        $payer = trim($payer);
        $collapsed = preg_replace('/\s+/u', ' ', $payer);
        if ($collapsed === null) {
            $collapsed = preg_replace('/\s+/', ' ', $payer);
        }
        return trim($collapsed ?? $payer);
    }

    private static function sort_observations(array &$rows): void
    {
        usort($rows, static fn (array $left, array $right): int =>
            ($left['_stable_id'] <=> $right['_stable_id'])
        );
    }

    private static function sort_rows(array $rows, array $filters): array
    {
        $sort = $filters['sort'] ?? 'date';
        $direction = ($filters['order'] ?? 'desc') === 'asc' ? 1 : -1;
        usort($rows, static function (array $left, array $right) use ($sort, $direction): int {
            $comparison = match ($sort) {
                'account' => strnatcasecmp($left['account'], $right['account']),
                'amount' => $left['amount_cents'] <=> $right['amount_cents'],
                'payer' => strnatcasecmp($left['payer'], $right['payer']),
                default => strcmp($left['date'], $right['date']),
            };
            if ($comparison !== 0) {
                return $comparison * $direction;
            }
            foreach (['date', 'account', 'payer', 'evidence', '_stable_id'] as $key) {
                $tie = $key === '_stable_id'
                    ? ($left[$key] <=> $right[$key])
                    : strnatcasecmp((string)$left[$key], (string)$right[$key]);
                if ($tie !== 0) {
                    return $key === 'date' ? -$tie : $tie;
                }
            }
            return 0;
        });
        return $rows;
    }

    private static function valid_month(string $month): bool
    {
        return preg_match('/^[12]\d{3}-(0[1-9]|1[0-2])$/D', $month) === 1;
    }

    private static function valid_date(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)) {
            return false;
        }
        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
    }

    private static function format_cents(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        return ($negative ? '-' : '')
            . number_format(intdiv($absolute, 100), 0, '.', ',')
            . '.'
            . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
