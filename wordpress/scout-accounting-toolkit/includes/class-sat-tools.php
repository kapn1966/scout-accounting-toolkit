<?php
/**
 * Tools tab of the inbox (administrators): importer status, cut-over dates, OFX import,
 * expense-claim backfill and inbox reset.
 *
 * Actions that change data run in two steps: a preview opens in a card at the top of the tab with
 * the button that completes it.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Tools
{
    private const MAX_UPLOAD_BYTES = 5242880;
    private const STATUS_ROWS = 6;
    private const STATUS_MORE = 20;
    private const STALE_HOURS = 3;
    private const SOURCE_LABELS = [
        'interac_email' => 'Interac e-Transfers',
        'gravity_forms' => 'Expense claims',
    ];
    private const ACTIONS = [
        'save_status', 'save_cutover', 'cutover_preview', 'cutover_apply',
        'ofx_preview', 'ofx_import', 'gf_preview', 'gf_import',
        'reset_preview', 'reset', 'cancel_pending',
    ];

    private static ?self $instance = null;

    private ?array $batchCache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        foreach (self::ACTIONS as $action) {
            add_action('admin_post_sat_tools_' . $action, [$this, 'action_' . $action]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Plumbing                                                            */
    /* ------------------------------------------------------------------ */

    private function db(): PDO
    {
        return SAT_DB::pdo();
    }

    private function settings(): array
    {
        return SAT_Settings::get('tools');
    }

    /** Every inbox source with a display label: our bank accounts, then evidence sources. */
    private function sources(): array
    {
        $out = [];
        foreach (SAT_Settings::banks() as $source => $bank) {
            $out[$source] = $bank['label'];
        }
        return $out + self::SOURCE_LABELS;
    }

    private function label(string $source): string
    {
        return $this->sources()[$source] ?? $source;
    }

    private function cutover(string $source): string
    {
        $date = (string)($this->settings()['cutover'][$source] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    private function guard(string $action): void
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_die('You do not have permission to use the inbox tools.', 'Scout Accounting', ['response' => 403]);
        }
        check_admin_referer('sat_tools_' . $action);
    }

    private function return_url(): string
    {
        $url = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
        return add_query_arg('sat_view', 'tools', $url);
    }

    private function form_open(string $action, bool $multipart = false): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($multipart ? ' enctype="multipart/form-data"' : '') . '>';
        echo '<input type="hidden" name="action" value="sat_tools_' . esc_attr($action) . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_url($this->return_url()) . '">';
        wp_nonce_field('sat_tools_' . $action);
    }

    private function button(string $label, string $class = 'primary'): void
    {
        echo '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button>';
    }

    private function back(string $anchor): void
    {
        $back = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : home_url('/');
        wp_safe_redirect(add_query_arg('sat_view', 'tools', $back) . '#' . $anchor);
        exit;
    }

    /** Store a result for this user and return to the Tools tab. */
    private function done(string $type, string $message, array $tables = []): void
    {
        set_transient($this->key('result'), ['type' => $type, 'message' => $message, 'tables' => $tables], 600);
        $this->back($this->pending() ? 'sat-pending' : 'sat-result');
    }

    private function fail(Throwable $e): void
    {
        try {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
        } catch (Throwable $ignored) {
            // No connection: nothing to roll back.
        }
        $this->done('error', $e->getMessage());
    }

    private function key(string $name): string
    {
        return 'sat_tools_' . $name . '_' . get_current_user_id();
    }

    private function posted_date(string $name): string
    {
        $value = sanitize_text_field(wp_unslash($_POST[$name] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('A valid date is required.');
        }
        return $value;
    }

    /* ------------------------------------------------------------------ */
    /* Pending previews                                                    */
    /* ------------------------------------------------------------------ */

    private function pending(): ?array
    {
        $pending = get_transient($this->key('pending'));
        return is_array($pending) ? $pending : null;
    }

    /**
     * $confirm: action, label, fields (hidden name => value), danger, checkbox [label, value],
     * typed (phrase to type), disabled (reason there is nothing to do).
     */
    private function set_pending(string $kind, string $title, array $tables, array $confirm): void
    {
        if ($kind !== 'ofx') {
            delete_transient($this->key('ofx'));
        }
        set_transient($this->key('pending'), ['kind' => $kind, 'title' => $title, 'tables' => $tables, 'confirm' => $confirm], 30 * MINUTE_IN_SECONDS);
        $this->back('sat-pending');
    }

    private function clear_pending(): void
    {
        delete_transient($this->key('pending'));
        delete_transient($this->key('ofx'));
    }

    public function action_cancel_pending(): void
    {
        $this->guard('cancel_pending');
        $this->clear_pending();
        $this->done('success', 'Preview cancelled. Nothing was changed.');
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    public function render(): void
    {
        $pending = $this->pending();
        $busy = $pending || get_transient($this->key('result'));
        $this->show_result();
        if ($pending) {
            $this->render_pending($pending);
        }

        $badge = null;
        try {
            $health = $this->interac_health();
            if ($health['verdict'] === 'bad') {
                $badge = ['bad', 'Interac importer failing'];
            } elseif ($health['verdict'] === 'warn') {
                $badge = ['warn', 'Interac importer quiet'];
            } elseif ($this->recent_failed_batches() > 0) {
                $badge = ['warn', 'Recent import failed'];
            }
        } catch (Throwable $e) {
            $badge = ['bad', 'Database unavailable'];
        }

        $this->fold('1. Inbox summary', '', !$busy, [$this, 'render_summary']);
        $this->fold('2. Importer status', '', false, [$this, 'render_status'], $badge);
        $this->fold('3. Cut-over dates', '', false, [$this, 'render_cutover']);
        echo '<div class="sat-tools-grid">';
        $this->card('4. OFX import', '', [$this, 'render_ofx']);
        $this->card('5. Expense claim backfill', '', [$this, 'render_backfill']);
        $this->card('Wave export', '', function () {
            $url = add_query_arg('sat_view', 'wave', remove_query_arg(['sat_view', 'sat_notice', 'sat_message']));
            echo '<p>Select transactions in Ready for Wave to create a persistent frozen preview.</p>';
            echo '<p>Wave export validates splits and account mappings, groups matched transfers, sends one transaction at a time, preserves partial failures and provides preview/result CSV files.</p>';
            echo '<p><a class="button" href="' . esc_url($url) . '">Open Wave export</a></p>';
        });
        echo '</div>';
        $this->fold('Reset inbox', 'danger-zone', false, [$this, 'render_reset']);
    }

    private function card(string $title, string $class, callable $body): void
    {
        echo '<section class="sat-card ' . esc_attr($class) . '"><h3>' . esc_html($title) . '</h3>';
        try {
            $body();
        } catch (Throwable $e) {
            echo '<p class="sat-error">' . esc_html($e->getMessage()) . '</p>';
        }
        echo '</section>';
    }

    /** A card that can be rolled up, with an optional [type, text] badge visible while closed. */
    private function fold(string $title, string $class, bool $open, callable $body, ?array $badge = null): void
    {
        echo '<details class="sat-card sat-fold ' . esc_attr($class) . '"' . ($open ? ' open' : '') . '><summary><h3>' . esc_html($title) . '</h3>';
        if ($badge) {
            echo '<span class="sat-badge ' . esc_attr($badge[0]) . '">' . esc_html($badge[1]) . '</span>';
        }
        echo '<span class="sat-fold-hint" aria-hidden="true"></span></summary><div>';
        try {
            $body();
        } catch (Throwable $e) {
            echo '<p class="sat-error">' . esc_html($e->getMessage()) . '</p>';
        }
        echo '</div></details>';
    }

    private function table(array $headers, array $rows, string $class = ''): void
    {
        echo '<table class="sat-summary ' . esc_attr($class) . '"><thead><tr>';
        foreach ($headers as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="' . count($headers) . '">Nothing to show.</td></tr>';
        }
        foreach ($rows as $row) {
            $rowClass = $row['_class'] ?? '';
            unset($row['_class']);
            echo '<tr class="' . esc_attr($rowClass) . '">';
            foreach (array_values($row) as $cell) {
                echo '<td>' . esc_html((string)$cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function show_result(): void
    {
        $result = get_transient($this->key('result'));
        if (!$result) {
            return;
        }
        delete_transient($this->key('result'));
        echo '<div id="sat-result" class="sat-tools-notice ' . esc_attr($result['type']) . '"><strong>' . esc_html($result['message']) . '</strong>';
        foreach ((array)($result['tables'] ?? []) as $t) {
            if (!empty($t['title'])) {
                echo '<h4>' . esc_html($t['title']) . '</h4>';
            }
            $this->table($t['headers'], $t['rows']);
        }
        echo '</div>';
    }

    private function render_pending(array $p): void
    {
        $c = $p['confirm'];
        echo '<section id="sat-pending" class="sat-card sat-pending' . (!empty($c['danger']) ? ' danger' : '') . '">';
        echo '<h3>Preview: ' . esc_html($p['title']) . '</h3><p class="sat-help">Nothing has been written yet. Check the figures, then confirm or cancel below.</p>';
        foreach ((array)$p['tables'] as $t) {
            if (!empty($t['title'])) {
                echo '<h4>' . esc_html($t['title']) . '</h4>';
            }
            $this->table($t['headers'], $t['rows']);
        }
        echo '<div class="sat-pending-actions">';
        if (!empty($c['disabled'])) {
            echo '<p class="sat-status-warn">' . esc_html($c['disabled']) . '</p>';
        } else {
            $this->form_open($c['action']);
            foreach ((array)($c['fields'] ?? []) as $name => $value) {
                echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
            }
            if (!empty($c['checkbox'])) {
                echo '<label class="confirm"><input type="checkbox" name="confirm" value="' . esc_attr($c['checkbox'][1]) . '" required> ' . esc_html($c['checkbox'][0]) . '</label>';
            }
            if (!empty($c['typed'])) {
                echo '<label>Type <code>' . esc_html($c['typed']) . '</code> <input type="text" name="confirmation_text" autocomplete="off" required></label>';
            }
            $this->button($c['label'], !empty($c['danger']) ? 'button-danger' : 'primary');
            echo '</form>';
        }
        $this->form_open('cancel_pending');
        $this->button(!empty($c['disabled']) ? 'Close' : 'Cancel', '');
        echo '</form></div></section>';
    }

    public function render_summary(): void
    {
        $rows = $this->db()->query('SELECT source_system, COUNT(*) n, MIN(transaction_date) oldest, MAX(transaction_date) newest FROM inbox_observations GROUP BY source_system ORDER BY source_system')->fetchAll();
        $out = [];
        $total = 0;
        foreach ($rows as $r) {
            $total += (int)$r['n'];
            $cut = $this->cutover($r['source_system']);
            $out[] = [
                '_class' => ($cut && $r['oldest'] < $cut) ? 'before-cutover' : '',
                $this->label($r['source_system']),
                number_format_i18n((int)$r['n']),
                $r['oldest'],
                $r['newest'],
                $cut ?: '—',
            ];
        }
        $out[] = ['_class' => 'total', 'Total', number_format_i18n($total), '', '', ''];
        $this->table(['Source', 'Count', 'Oldest', 'Newest', 'Cut-over'], $out);
        echo '<p class="sat-help">Highlighted rows still contain records dated before their cut-over date.</p>';
    }

    /* ------------------------------------------------------------------ */
    /* Importer status                                                     */
    /* ------------------------------------------------------------------ */

    private function recent_batches(): array
    {
        if ($this->batchCache === null) {
            $this->batchCache = $this->db()->query('SELECT * FROM inbox_import_batches ORDER BY batch_id DESC LIMIT ' . self::STATUS_MORE)->fetchAll();
        }
        return $this->batchCache;
    }

    private function recent_failed_batches(): int
    {
        $count = 0;
        foreach (array_slice($this->recent_batches(), 0, self::STATUS_ROWS) as $r) {
            if (stripos((string)$r['notes'], 'FAILED') === 0) {
                $count++;
            }
        }
        return $count;
    }

    /** End of the Interac cron log, or null if not readable. Catches failures before the database is reached. */
    private function cron_log_state(): ?array
    {
        $path = (string)$this->settings()['cron_log'];
        if ($path === '' || !@is_readable($path)) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return null;
        }
        if ((int)@filesize($path) > 65536) {
            fseek($handle, -65536, SEEK_END);
        }
        $text = (string)stream_get_contents($handle);
        fclose($handle);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text)), 'strlen'));
        $summary = '/^\[[0-9: -]{19}\] Processed=/';
        $state = ['mtime' => (int)@filemtime($path), 'last_ok' => '', 'last_error' => '', 'failing' => false];
        foreach (array_reverse($lines) as $line) {
            if ($state['last_ok'] === '' && preg_match($summary, $line)) {
                $state['last_ok'] = $line;
            }
            if ($state['last_error'] === '' && preg_match('/FATAL|Fatal error|Missing configuration|failed|PHP Warning|Permission denied|Uncaught/i', $line)) {
                $state['last_error'] = $line;
            }
        }
        foreach (array_reverse($lines) as $line) {
            if (preg_match($summary, $line)) {
                break;
            }
            if (preg_match('/FATAL|Fatal error|Missing configuration|Uncaught/i', $line)) {
                $state['failing'] = true;
                break;
            }
        }
        return $state;
    }

    private function interac_health(): array
    {
        $uid = false;
        try {
            $q = $this->db()->prepare('SELECT state_value FROM processor_state WHERE state_key = ?');
            $q->execute(['last_processed_uid']);
            $uid = $q->fetchColumn();
        } catch (Throwable $e) {
            $uid = false;
        }
        $last = null;
        foreach ($this->recent_batches() as $r) {
            if ($r['source_system'] === 'interac_email') {
                $last = $r;
                break;
            }
        }
        $log = $this->cron_log_state();
        $verdict = 'ok';
        $why = '';
        if ($log === null) {
            if ($last && stripos((string)$last['notes'], 'FAILED') === 0) {
                $verdict = 'bad';
                $why = (string)$last['notes'];
            } elseif ($last && (time() - strtotime($last['imported_at'] . ' ' . wp_timezone_string())) > self::STALE_HOURS * HOUR_IN_SECONDS) {
                $verdict = 'warn';
                $why = 'No run recorded in the last ' . self::STALE_HOURS . ' hours.';
            }
        } elseif ($log['failing']) {
            $verdict = 'bad';
            $why = $log['last_error'];
        } elseif (time() - $log['mtime'] > self::STALE_HOURS * HOUR_IN_SECONDS) {
            $verdict = 'warn';
            $why = 'The cron log has not been written for ' . human_time_diff($log['mtime']) . '. Check that the cron job is still scheduled.';
        }
        return compact('uid', 'last', 'log', 'verdict', 'why');
    }

    /** One readable line from a batch's notes; the full text goes in a tooltip. */
    private function short_note(string $notes): string
    {
        if (stripos($notes, 'FAILED') === 0) {
            return wp_trim_words($notes, 14, '…');
        }
        $bits = [];
        foreach (['inserted', 'refreshed', 'skipped_before_cutover', 'rejected', 'Observations imported', 'duplicates', 'skipped', 'last UID'] as $key) {
            if (preg_match('/' . preg_quote($key, '/') . '=(\d+)/i', $notes, $m)) {
                $bits[] = str_replace('_', ' ', strtolower($key)) . ' ' . $m[1];
            }
        }
        if (preg_match('/statement=([0-9-]+) to ([0-9-]+)/', $notes, $m)) {
            $bits[] = $m[1] . ' → ' . $m[2];
        }
        return $bits ? implode(' · ', $bits) : wp_trim_words($notes, 14, '…');
    }

    public function render_status(): void
    {
        $h = $this->interac_health();
        echo '<h4>Interac e-Transfer importer <span class="sat-help">(hourly cron on the server)</span></h4><ul class="sat-health">';
        echo '<li>Last processed IMAP UID: <strong>' . esc_html($h['uid'] !== false ? (string)$h['uid'] : 'not readable') . '</strong></li>';
        if ($h['last']) {
            echo '<li>Last run recorded in the database: ' . esc_html($h['last']['imported_at'] . ' — ' . $this->short_note((string)$h['last']['notes'])) . '</li>';
        }
        if ($h['log'] === null) {
            echo '<li class="sat-help">Cron log not readable or not set. Failures before the database is reached (such as a missing config file) cannot be detected.</li>';
        } else {
            echo '<li>Cron log last written ' . esc_html(human_time_diff($h['log']['mtime'])) . ' ago.</li>';
            if ($h['log']['last_ok'] !== '') {
                echo '<li>Last successful run: <code>' . esc_html($h['log']['last_ok']) . '</code></li>';
            }
            if ($h['verdict'] === 'ok' && $h['log']['last_error'] !== '') {
                echo '<li class="sat-help">Earlier warning in the log (resolved since): <code>' . esc_html($h['log']['last_error']) . '</code></li>';
            }
        }
        echo '</ul>';
        if ($h['verdict'] === 'bad') {
            echo '<p class="sat-status-bad">Interac importer is failing: ' . esc_html($h['why']) . '</p>';
        } elseif ($h['verdict'] === 'warn') {
            echo '<p class="sat-status-warn">' . esc_html($h['why']) . '</p>';
        } else {
            echo '<p class="sat-status-ok">Interac importer looks healthy.</p>';
        }

        echo '<details><summary>Cron log location</summary>';
        $this->form_open('save_status');
        echo '<label>Path to the Interac cron log <input type="text" class="wide" name="cron_log" value="' . esc_attr((string)$this->settings()['cron_log']) . '"></label>';
        $this->button('Save', '');
        echo '</form><p class="sat-help">The web server needs read access to this file.</p></details>';

        $rows = $this->recent_batches();
        echo '<h4>Recent imports</h4>';
        $this->batch_table(array_slice($rows, 0, self::STATUS_ROWS));
        if (count($rows) > self::STATUS_ROWS) {
            echo '<details><summary>Show ' . (count($rows) - self::STATUS_ROWS) . ' more</summary>';
            $this->batch_table(array_slice($rows, self::STATUS_ROWS));
            echo '</details>';
        }
        echo '<p class="sat-help">Hover over a summary to see the full notes.</p>';
    }

    private function batch_table(array $rows): void
    {
        echo '<table class="sat-summary compact"><thead><tr><th>Batch</th><th>When</th><th>Source</th><th>File</th><th>Rows</th><th>Rejected</th><th>Summary</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="7">No imports yet.</td></tr>';
        }
        foreach ($rows as $r) {
            $failed = stripos((string)$r['notes'], 'FAILED') === 0;
            echo '<tr class="' . ($failed ? 'failed' : '') . '">';
            echo '<td>' . (int)$r['batch_id'] . '</td>';
            echo '<td class="nowrap">' . esc_html((string)$r['imported_at']) . '</td>';
            echo '<td class="nowrap">' . esc_html($this->label((string)$r['source_system'])) . '</td>';
            echo '<td class="file" title="' . esc_attr((string)$r['source_filename']) . '">' . esc_html((string)$r['source_filename']) . '</td>';
            echo '<td>' . (int)$r['rows_imported'] . '</td><td>' . (int)$r['rows_rejected'] . '</td>';
            echo '<td title="' . esc_attr((string)$r['notes']) . '">' . esc_html($this->short_note((string)$r['notes'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public function action_save_status(): void
    {
        try {
            $this->guard('save_status');
            $path = trim(sanitize_text_field(wp_unslash($_POST['cron_log'] ?? '')));
            if ($path !== '' && $path[0] !== '/') {
                throw new RuntimeException('Enter an absolute path starting with /.');
            }
            $s = $this->settings();
            $s['cron_log'] = $path;
            SAT_Settings::update('tools', $s);
            $this->done('success', 'Cron log location saved.' . ($path !== '' && !@is_readable($path) ? ' Warning: the web server cannot read that file.' : ''));
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Cut-over                                                            */
    /* ------------------------------------------------------------------ */

    public function render_cutover(): void
    {
        $saved = (array)$this->settings()['cutover'];
        echo '<p>The first date each source is trusted from. For bank accounts, use the day after the last Wave reconciliation. Leave blank for none. OFX uploads skip transactions before the cut-over date.</p>';
        $this->form_open('save_cutover');
        echo '<div class="sat-cutover-grid">';
        foreach ($this->sources() as $source => $label) {
            echo '<label>' . esc_html($label) . ' <input type="date" name="cutover[' . esc_attr($source) . ']" value="' . esc_attr($saved[$source] ?? '') . '"></label>';
        }
        echo '</div>';
        $this->button('Save cut-over dates', '');
        echo '</form><div class="sat-inline-forms">';
        $this->form_open('cutover_preview');
        $this->button('Preview cleanup', '');
        echo '</form></div><p class="sat-help">The preview opens at the top of the page, where you confirm the deletion.</p>';
    }

    public function action_save_cutover(): void
    {
        try {
            $this->guard('save_cutover');
            $input = (array)($_POST['cutover'] ?? []);
            $cutover = [];
            foreach ($this->sources() as $source => $label) {
                $value = sanitize_text_field(wp_unslash($input[$source] ?? ''));
                if ($value === '') {
                    continue;
                }
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    throw new RuntimeException('Invalid date for ' . $label . '.');
                }
                $cutover[$source] = $value;
            }
            $s = $this->settings();
            $s['cutover'] = $cutover;
            SAT_Settings::update('tools', $s);
            $this->done('success', 'Cut-over dates saved.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    private function cutover_plan(): array
    {
        $plan = [];
        foreach ($this->sources() as $source => $label) {
            $cut = $this->cutover($source);
            if ($cut) {
                $plan[$source] = ['label' => $label, 'cutover' => $cut, 'ids' => $this->ids_where('source_system = ? AND transaction_date < ?', [$source, $cut])];
            }
        }
        return $plan;
    }

    public function action_cutover_preview(): void
    {
        try {
            $this->guard('cutover_preview');
            $plan = $this->cutover_plan();
            if (!$plan) {
                throw new RuntimeException('No cut-over dates are set.');
            }
            $rows = [];
            $all = [];
            foreach ($plan as $p) {
                $rows[] = [$p['label'], $p['cutover'], count($p['ids'])];
                $all = array_merge($all, $p['ids']);
            }
            $rows[] = ['_class' => 'total', 'Total', '', count($all)];
            $linked = [
                ['Allocations', $this->count_in('SELECT COUNT(*) FROM inbox_allocations WHERE bank_observation_id IN (%IDS%)', $all)],
                ['Matches', $this->count_in('SELECT COUNT(*) FROM inbox_matches WHERE left_observation_id IN (%IDS%) OR right_observation_id IN (%IDS%)', $all, true)],
            ];
            $n = count($all);
            $this->set_pending('cutover', 'Cut-over cleanup', [
                ['title' => 'Records before cut-over', 'headers' => ['Source', 'Cut-over', 'To delete'], 'rows' => $rows],
                ['title' => 'Linked records also deleted', 'headers' => ['Type', 'Count'], 'rows' => $linked],
            ], [
                'action' => 'cutover_apply',
                'label' => 'Delete ' . $n . ' record' . ($n === 1 ? '' : 's'),
                'danger' => true,
                'checkbox' => ['I understand these records will be deleted.', 'yes'],
                'disabled' => $n === 0 ? 'Nothing is dated before the cut-over dates.' : '',
            ]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function action_cutover_apply(): void
    {
        try {
            $this->guard('cutover_apply');
            if (($_POST['confirm'] ?? '') !== 'yes') {
                throw new RuntimeException('Tick the confirmation box first.');
            }
            $plan = $this->cutover_plan();
            $rows = [];
            $this->db()->beginTransaction();
            foreach ($plan as $p) {
                $this->delete_ids($p['ids']);
                $rows[] = [$p['label'], $p['cutover'], count($p['ids'])];
            }
            $this->db()->commit();
            $this->clear_pending();
            $this->done('success', 'Cut-over cleanup complete.', [['headers' => ['Source', 'Cut-over', 'Deleted'], 'rows' => $rows]]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* OFX import                                                          */
    /* ------------------------------------------------------------------ */

    public function render_ofx(): void
    {
        $pending = $this->pending();
        if ($pending && $pending['kind'] === 'ofx') {
            echo '<p><strong>A preview is waiting.</strong> <a href="#sat-pending">Finish or cancel it at the top of the page</a> before uploading more files.</p>';
        } else {
            echo '<p>Upload OFX or QFX statements for the bank and card accounts (a <code>.txt</code> suffix is fine). The preview opens at the top of the page, where you confirm the import. Re-importing a file is safe.</p>';
            $this->form_open('ofx_preview', true);
            echo '<input type="file" name="ofx_files[]" multiple accept=".ofx,.qfx,.txt" required>';
            $this->button('Preview');
            echo '</form>';
        }
        $count = count(SAT_Settings::get('accounts'));
        echo '<p class="sat-help">' . (int)$count . ' account' . ($count === 1 ? '' : 's') . ' set up. ';
        echo '<a href="' . esc_url(admin_url('options-general.php?page=sat-settings&tab=accounts')) . '">Edit bank and card accounts</a></p>';
    }

    public function action_ofx_preview(): void
    {
        try {
            $this->guard('ofx_preview');
            $files = $_FILES['ofx_files'] ?? null;
            if (!$files || empty($files['name'])) {
                throw new RuntimeException('Choose at least one file.');
            }
            $stored = [];
            $bytes = 0;
            foreach ((array)$files['name'] as $i => $name) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Upload failed for ' . $name . '.');
                }
                $raw = (string)file_get_contents($files['tmp_name'][$i]);
                $bytes += strlen($raw);
                if ($bytes > self::MAX_UPLOAD_BYTES) {
                    throw new RuntimeException('Files are larger than 5 MB in total.');
                }
                $stored[] = ['name' => sanitize_file_name($name), 'raw' => $raw];
            }
            $summary = [];
            $detail = [];
            $new = 0;
            $existing = 0;
            $good = 0;
            foreach ($stored as $f) {
                try {
                    $plan = $this->ofx_plan($f['raw']);
                    $new += $plan['counts']['new'];
                    $existing += $plan['counts']['existing'];
                    $good++;
                    $summary[] = [$f['name'], $plan['account_name'], $plan['start'] . ' → ' . $plan['end'], $plan['ledger'], $plan['counts']['new'], $plan['counts']['existing'], $plan['counts']['before_cutover'], $plan['counts']['rejected']];
                    foreach ($plan['rejections'] as $message) {
                        $detail[] = [$f['name'], $message];
                    }
                } catch (Throwable $e) {
                    $summary[] = ['_class' => 'failed', $f['name'], 'FAILED', $e->getMessage(), '', '', '', '', ''];
                }
            }
            set_transient($this->key('ofx'), $stored, 30 * MINUTE_IN_SECONDS);
            $tables = [['headers' => ['File', 'Account', 'Statement', 'Ledger balance', 'New', 'Already imported', 'Before cut-over (skipped)', 'Rejected'], 'rows' => $summary]];
            if ($detail) {
                $tables[] = ['title' => 'Rejected transactions', 'headers' => ['File', 'Reason'], 'rows' => $detail];
            }
            $label = $new > 0
                ? 'Import ' . $new . ' new transaction' . ($new === 1 ? '' : 's') . ($existing ? ' (and refresh ' . $existing . ')' : '')
                : 'Refresh ' . $existing . ' existing transaction' . ($existing === 1 ? '' : 's');
            $disabled = $good === 0 ? 'None of the files can be imported. See the reasons above.' : (($new + $existing) === 0 ? 'Every transaction is before the cut-over date.' : '');
            $this->set_pending('ofx', 'OFX import (' . count($stored) . ' file' . (count($stored) === 1 ? '' : 's') . ')', $tables, [
                'action' => 'ofx_import',
                'label' => $label,
                'disabled' => $disabled,
            ]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function action_ofx_import(): void
    {
        try {
            $this->guard('ofx_import');
            $stored = get_transient($this->key('ofx'));
            $this->clear_pending();
            if (!$stored) {
                throw new RuntimeException('The preview has expired. Upload the files again.');
            }
            $rows = [];
            foreach ($stored as $f) {
                try {
                    $r = $this->ofx_import_file($f['name'], $f['raw']);
                    $rows[] = [$f['name'], $r['account_name'], $r['inserted'], $r['refreshed'], $r['skipped'], $r['rejected'], $r['batch_id']];
                } catch (Throwable $e) {
                    $rows[] = ['_class' => 'failed', $f['name'], 'FAILED: ' . $e->getMessage(), '', '', '', '', ''];
                }
            }
            $this->done('success', 'OFX import finished.', [['headers' => ['File', 'Account', 'Inserted', 'Refreshed', 'Skipped (cut-over)', 'Rejected', 'Batch'], 'rows' => $rows]]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /** Parse a file and classify each transaction without writing anything. */
    private function ofx_plan(string $raw): array
    {
        $st = SAT_OFX_Parser::parse($raw);
        $account = SAT_Settings::get('accounts')[$st['account_id']] ?? null;
        if (!$account) {
            throw new RuntimeException('Account ' . $st['account_id'] . ' is not set up. Add it under Settings → Scout Accounting → Accounts.');
        }
        if (($account['statement_type'] ?? '') !== $st['statement_type']) {
            throw new RuntimeException(sprintf('Account %s is set up as %s but the file is a %s statement.', $st['account_id'], $account['statement_type'], $st['statement_type']));
        }
        $source = (string)$account['source_system'];
        $cut = $this->cutover($source);
        $keys = [];
        foreach ($st['transactions'] as $t) {
            if ($t['error'] === '') {
                $keys[] = $source . ':' . $st['account_id'] . ':' . $t['fitid'];
            }
        }
        $existing = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db()->prepare("SELECT source_key FROM inbox_observations WHERE source_system = ? AND source_key IN ($in)");
            $q->execute(array_merge([$source], $chunk));
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $key) {
                $existing[$key] = true;
            }
        }
        $counts = ['new' => 0, 'existing' => 0, 'before_cutover' => 0, 'rejected' => 0];
        $rejections = [];
        foreach ($st['transactions'] as $i => $t) {
            if ($t['error'] !== '') {
                $counts['rejected']++;
                $rejections[] = 'Transaction ' . ($i + 1) . ': ' . $t['error'];
            } elseif ($cut && $t['date'] < $cut) {
                $counts['before_cutover']++;
            } elseif (isset($existing[$source . ':' . $st['account_id'] . ':' . $t['fitid']])) {
                $counts['existing']++;
            } else {
                $counts['new']++;
            }
        }
        return [
            'statement' => $st,
            'source_system' => $source,
            'account_name' => (string)($account['account_name'] ?? $source),
            'cutover' => $cut,
            'start' => $st['statement_start'] ?: '?',
            'end' => $st['statement_end'] ?: '?',
            'ledger' => ($st['ledger_balance'] !== '' ? $st['ledger_balance'] : '?') . ' (' . ($st['ledger_date'] ?: '?') . ')',
            'counts' => $counts,
            'rejections' => $rejections,
        ];
    }

    private function ofx_import_file(string $filename, string $raw): array
    {
        $plan = $this->ofx_plan($raw);
        $st = $plan['statement'];
        $source = $plan['source_system'];
        $db = $this->db();
        $db->prepare('INSERT INTO inbox_import_batches (source_system, source_filename, rows_imported, rows_rejected, notes) VALUES (?, ?, 0, 0, ?)')
            ->execute([$source, $filename, sprintf('OFX import started. Account=%s; type=%s; ACCTID=%s; transactions=%d.', $plan['account_name'], $st['statement_type'], $st['account_id'], count($st['transactions']))]);
        $batch = (int)$db->lastInsertId();
        $insert = $db->prepare(
            'INSERT INTO inbox_observations (import_batch_id, source_system, source_key, transaction_date, amount, direction, description, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                import_batch_id = VALUES(import_batch_id),
                transaction_date = VALUES(transaction_date),
                amount = VALUES(amount),
                direction = VALUES(direction),
                description = VALUES(description),
                payload_json = VALUES(payload_json)'
        );
        $n = ['inserted' => 0, 'refreshed' => 0, 'skipped' => 0, 'rejected' => 0];
        $messages = [];
        foreach ($st['transactions'] as $i => $t) {
            if ($t['error'] !== '') {
                $n['rejected']++;
                $messages[] = 'Transaction ' . ($i + 1) . ' rejected: ' . $t['error'];
                continue;
            }
            if ($plan['cutover'] && $t['date'] < $plan['cutover']) {
                $n['skipped']++;
                continue;
            }
            $payload = wp_json_encode([
                'format' => $st['format'],
                'ofx_version' => $st['version'],
                'source_filename' => $filename,
                'statement_type' => $st['statement_type'],
                'account_name' => $plan['account_name'],
                'account_id' => $st['account_id'],
                'account_type' => $st['account_type'],
                'bank_id' => $st['bank_id'],
                'branch_id' => $st['branch_id'],
                'currency' => $st['currency'],
                'statement_start' => $st['statement_start'],
                'statement_end' => $st['statement_end'],
                'ledger_balance' => $st['ledger_balance'],
                'ledger_date' => $st['ledger_date'],
                'transaction_type' => $t['trntype'],
                'posted_date_raw' => $t['dtposted'],
                'amount' => number_format($t['amount'], 2, '.', ''),
                'fitid' => $t['fitid'],
                'name' => $t['name'],
                'memo' => $t['memo'],
                'check_number' => $t['checknum'],
                'reference_number' => $t['refnum'],
                'raw_transaction' => $t['raw'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $insert->execute([
                $batch,
                $source,
                $source . ':' . $st['account_id'] . ':' . $t['fitid'],
                $t['date'],
                number_format($t['amount'], 2, '.', ''),
                $t['amount'] < 0 ? 'expense' : 'income',
                SAT_OFX_Parser::description($t['name'], $t['memo']),
                $payload,
            ]);
            $insert->rowCount() === 1 ? $n['inserted']++ : $n['refreshed']++;
        }
        $notes = sprintf(
            'Completed. Account=%s; type=%s; ACCTID=%s; inserted=%d; refreshed=%d; skipped_before_cutover=%d; rejected=%d; statement=%s to %s; ledger=%s %s.',
            $plan['account_name'], $st['statement_type'], $st['account_id'], $n['inserted'], $n['refreshed'], $n['skipped'], $n['rejected'],
            $st['statement_start'] ?: 'unknown', $st['statement_end'] ?: 'unknown', $st['currency'] ?: 'unknown currency', $st['ledger_balance'] !== '' ? $st['ledger_balance'] : 'unknown balance'
        );
        if ($messages) {
            $notes .= ' ' . implode(' | ', $messages);
        }
        $db->prepare('UPDATE inbox_import_batches SET rows_imported = ?, rows_rejected = ?, notes = ? WHERE batch_id = ?')
            ->execute([$n['inserted'] + $n['refreshed'], $n['rejected'], $notes, $batch]);
        return $n + ['account_name' => $plan['account_name'], 'batch_id' => $batch];
    }

    /* ------------------------------------------------------------------ */
    /* Expense claim backfill                                              */
    /* ------------------------------------------------------------------ */

    public function render_backfill(): void
    {
        echo '<p>Imports or refreshes expense claims from the expense form, from a chosen date. Only inbox records are updated.</p>';
        echo '<div class="sat-inline-forms">';
        $this->form_open('gf_preview');
        echo '<label>Entries created on or after <input type="date" name="from_date" required value="' . esc_attr(wp_date('Y-m-d', strtotime('-30 days'))) . '"></label>';
        $this->button('Preview', '');
        echo '</form></div><p class="sat-help">The preview opens at the top of the page, where you confirm the import.</p>';
    }

    public function action_gf_preview(): void
    {
        try {
            $this->guard('gf_preview');
            $from = $this->posted_date('from_date');
            [$form, $entries] = SAT_Expense_Form::instance()->entries_since($from);
            $ids = array_map(function ($entry) {
                return (int)$entry['id'];
            }, $entries);
            $existing = $this->count_in("SELECT COUNT(*) FROM inbox_observations WHERE source_system = 'gravity_forms' AND gf_entry_id IN (%IDS%)", $ids);
            $count = count($entries);
            $missing = max(0, $count - $existing);
            $this->set_pending('gf', 'Expense claim backfill from ' . $from, [[
                'headers' => ['Item', 'Value'],
                'rows' => [
                    ['Form', $form['title'] . ' (#' . $form['id'] . ')'],
                    ['From date', $from],
                    ['Entries found', $count],
                    ['Already in inbox (will be refreshed)', $existing],
                    ['Missing from inbox (will be added)', $missing],
                ],
            ]], [
                'action' => 'gf_import',
                'fields' => ['from_date' => $from],
                'label' => $missing ? 'Add ' . $missing . ' and refresh ' . $existing : 'Refresh ' . $existing . ' entr' . ($existing === 1 ? 'y' : 'ies'),
                'disabled' => $count === 0 ? 'No active entries on or after ' . $from . '.' : '',
            ]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function action_gf_import(): void
    {
        try {
            $this->guard('gf_import');
            $from = $this->posted_date('from_date');
            [$form, $entries] = SAT_Expense_Form::instance()->entries_since($from);
            $ok = 0;
            $failed = [];
            foreach ($entries as $entry) {
                $result = SAT_Expense_Form::instance()->import_entry($entry, $form);
                if (is_wp_error($result)) {
                    $failed[] = ['#' . $entry['id'], $result->get_error_message()];
                } else {
                    $ok++;
                }
            }
            $tables = [['headers' => ['Item', 'Value'], 'rows' => [['From date', $from], ['Imported or refreshed', $ok], ['Failed', count($failed)]]]];
            if ($failed) {
                $tables[] = ['title' => 'Failures', 'headers' => ['Entry', 'Reason'], 'rows' => $failed];
            }
            $this->clear_pending();
            $this->done($failed ? 'warning' : 'success', 'Expense claim backfill finished.', $tables);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Reset                                                               */
    /* ------------------------------------------------------------------ */

    public function render_reset(): void
    {
        echo '<p>Deletes every inbox record with its categories, matches and reviews. Import history is kept. Gravity Forms entries, emails, OFX files and Wave are not touched. Back up the database first.</p>';
        echo '<div class="sat-inline-forms">';
        $this->form_open('reset_preview');
        $this->button('Preview reset', '');
        echo '</form></div><p class="sat-help">The preview opens at the top of the page, where you confirm the reset.</p>';
    }

    public function action_reset_preview(): void
    {
        try {
            $this->guard('reset_preview');
            $rows = [];
            foreach ($this->db()->query('SELECT source_system, COUNT(*) n FROM inbox_observations GROUP BY source_system')->fetchAll() as $r) {
                $rows[] = [$this->label($r['source_system']), $r['n']];
            }
            $rows[] = ['Allocations', $this->db()->query('SELECT COUNT(*) FROM inbox_allocations')->fetchColumn()];
            $rows[] = ['Matches', $this->db()->query('SELECT COUNT(*) FROM inbox_matches')->fetchColumn()];
            $this->set_pending('reset', 'Reset inbox', [['headers' => ['Records', 'Count'], 'rows' => $rows]], [
                'action' => 'reset',
                'label' => 'Reset inbox',
                'danger' => true,
                'checkbox' => ['I understand all inbox data will be deleted.', 'RESET'],
                'typed' => 'RESET INBOX',
            ]);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function action_reset(): void
    {
        try {
            $this->guard('reset');
            if (($_POST['confirm'] ?? '') !== 'RESET' || trim((string)wp_unslash($_POST['confirmation_text'] ?? '')) !== 'RESET INBOX') {
                throw new RuntimeException('Reset confirmation did not match.');
            }
            $ids = $this->ids_where('1 = 1', []);
            $this->db()->beginTransaction();
            $this->delete_ids($ids);
            $this->db()->commit();
            $this->clear_pending();
            $this->done('success', 'Inbox reset complete. Deleted ' . count($ids) . ' records.');
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Deletion helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function ids_where(string $where, array $params): array
    {
        $q = $this->db()->prepare('SELECT observation_id FROM inbox_observations WHERE ' . $where);
        $q->execute($params);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    private function count_in(string $sql, array $ids, bool $twice = false): int
    {
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db()->prepare(str_replace('%IDS%', $in, $sql));
        $q->execute($twice ? array_merge($ids, $ids) : $ids);
        return (int)$q->fetchColumn();
    }

    private function delete_ids(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $this->db()->prepare("DELETE FROM inbox_matches WHERE left_observation_id IN ($in) OR right_observation_id IN ($in)")->execute(array_merge($chunk, $chunk));
            $this->db()->prepare("DELETE FROM inbox_allocations WHERE bank_observation_id IN ($in)")->execute($chunk);
            $this->db()->prepare("DELETE FROM inbox_reviews WHERE bank_observation_id IN ($in)")->execute($chunk);
            $this->db()->prepare("DELETE FROM inbox_observations WHERE observation_id IN ($in)")->execute($chunk);
        }
    }
}
