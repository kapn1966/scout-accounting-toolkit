<?php
/**
 * Plugin Name: Transaction Inbox Tools
 * Description: Tools tab for the Transaction Inbox: importer status, per-account cut-over, OFX upload, Wave balance check, Gravity Forms backfill, and inbox reset.
 * Version: 0.2.2
 * Author: Scout Accounting Toolkit
 * License: GPL-3.0-or-later
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

final class TO403_Inbox_Tools {
    const VERSION = '0.2.2';
    const VIEW = 'tools';
    const OPTION = 'to403_tools_settings';
    const MAX_UPLOAD_BYTES = 5242880; // 5 MB total per preview
    const STATUS_ROWS = 6;            // import batches shown before "Show more"
    const STATUS_MORE = 20;           // total batches available under "Show more"
    const STALE_HOURS = 3;            // hourly Interac cron: warn after this many hours of silence
    const DEFAULT_CRON_LOG = '/opt/scout-accounting-toolkit/ingest/interac-email/logs/cron.log';

    /** Inbox source systems this toolkit knows about. */
    const SOURCES = [
        'ofx_operating' => 'Operating (OFX)',
        'ofx_cg_funds'  => 'CG Funds (OFX)',
        'ofx_visa'      => 'GC Visa (OFX)',
        'interac_email' => 'Interac e-Transfers',
        'gravity_forms' => 'Expense claims (Gravity Forms)',
    ];
    const BANK_SOURCES = ['ofx_operating', 'ofx_cg_funds', 'ofx_visa'];

    private static $instance;
    private $pdo;

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_filter('pre_do_shortcode_tag', [$this, 'intercept'], 10, 4);
        add_action('wp_footer', [$this, 'tab_script'], 99);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        $actions = [
            'save_status',
            'save_cutover', 'cutover_preview', 'cutover_apply',
            'ofx_preview', 'ofx_import', 'ofx_cancel', 'save_ofx_accounts',
            'save_wave_accounts', 'wave_check',
            'gf_preview', 'gf_import',
            'reset_preview', 'reset',
        ];
        foreach ($actions as $a) add_action('admin_post_to403_tools_' . $a, [$this, 'action_' . $a]);
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    private function settings() {
        $defaults = [
            'cutover' => [],
            'ofx_accounts' => [
                '952161CHEQCAD2' => ['source_system' => 'ofx_operating', 'statement_type' => 'bank', 'account_name' => 'Operating'],
                '952161CHEQCAD1' => ['source_system' => 'ofx_cg_funds', 'statement_type' => 'bank', 'account_name' => 'CG Funds'],
            ],
            'wave_accounts' => [],
            'cron_log' => self::DEFAULT_CRON_LOG,
        ];
        $saved = get_option(self::OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    private function save_settings($s) { update_option(self::OPTION, $s, false); }

    private function cutover($source) {
        $s = $this->settings();
        $d = $s['cutover'][$source] ?? '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : '';
    }

    /* ------------------------------------------------------------------ */
    /* Plumbing                                                            */
    /* ------------------------------------------------------------------ */

    public function assets() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;
        wp_enqueue_style('to403-inbox-tools', plugins_url('assets/tools.css', __FILE__), [], @filemtime(__DIR__ . '/assets/tools.css') ?: self::VERSION);
    }

    public function intercept($return, $tag, $attr, $m) {
        if (!in_array($tag, ['403to_transaction_inbox', '403to_payment_review', '403to_inbox_review'], true)) return $return;
        if ($this->view() !== self::VIEW) return $return;
        return $this->render();
    }

    private function view() {
        return isset($_GET['to403_view']) ? sanitize_key(wp_unslash($_GET['to403_view'])) : 'transactions';
    }

    /** Adds a Tools link to the tab bar rendered by the Transaction Inbox plugin. */
    public function tab_script() {
        if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options')) return;
        ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.to403-tabs').forEach(function(nav){
    if(nav.querySelector('[data-to403-tools]'))return;
    var u=new URL(window.location.href);u.searchParams.set('to403_view','tools');
    var a=document.createElement('a');a.href=u.toString();a.textContent='Tools';a.dataset.to403Tools='1';
    nav.appendChild(a);
  });
});
</script>
        <?php
    }

    private function access() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_die('You do not have permission to use the Transaction Inbox tools.', 'Transaction Inbox Tools', ['response' => 403]);
        }
    }

    private function guard($action) {
        $this->access();
        check_admin_referer('to403_tools_' . $action);
    }

    private function db() {
        if ($this->pdo) return $this->pdo;
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        if (defined('TO403_PAYMENTS_DB_HOST') && defined('TO403_PAYMENTS_DB_NAME') && defined('TO403_PAYMENTS_DB_USER') && defined('TO403_PAYMENTS_DB_PASSWORD')) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', TO403_PAYMENTS_DB_HOST, TO403_PAYMENTS_DB_NAME);
            return $this->pdo = new PDO($dsn, TO403_PAYMENTS_DB_USER, TO403_PAYMENTS_DB_PASSWORD, $opts);
        }
        if (defined('TO403_INBOX_DSN') && defined('TO403_INBOX_DB_USER') && defined('TO403_INBOX_DB_PASSWORD')) {
            return $this->pdo = new PDO(TO403_INBOX_DSN, TO403_INBOX_DB_USER, TO403_INBOX_DB_PASSWORD, $opts);
        }
        throw new RuntimeException('Transaction Inbox database settings are missing. Define the TO403_PAYMENTS_DB_* constants in wp-config.php.');
    }

    private function form_open($action, $multipart = false) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($multipart ? ' enctype="multipart/form-data"' : '') . '>';
        echo '<input type="hidden" name="action" value="to403_tools_' . esc_attr($action) . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_url($this->return_url()) . '">';
        wp_nonce_field('to403_tools_' . $action);
    }

    private function return_url() {
        $url = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
        return add_query_arg('to403_view', 'tools', $url);
    }

    private function button($label, $class = 'button button-primary') {
        echo '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button>';
    }

    /** Store a result for the current user and return to the Tools tab. */
    private function done($type, $message, $tables = []) {
        set_transient('to403_tools_result_' . get_current_user_id(), ['type' => $type, 'message' => $message, 'tables' => $tables], 600);
        $back = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : home_url('/');
        wp_safe_redirect(add_query_arg('to403_view', 'tools', $back) . '#to403-tools-result');
        exit;
    }

    private function fail(Throwable $e) {
        if ($this->pdo && $this->pdo->inTransaction()) $this->pdo->rollBack();
        $this->done('error', $e->getMessage());
    }

    private function posted_date($name, $required = true) {
        $v = isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
        if ($v === '' && !$required) return '';
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) throw new RuntimeException('A valid date is required.');
        return $v;
    }

    private function money($v) {
        $v = (float)$v;
        return ($v < 0 ? '−' : '') . '$' . number_format(abs($v), 2);
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    private function tabs() {
        $base = remove_query_arg(['to403_view']);
        echo '<nav class="to403-tabs" aria-label="Transaction Inbox views">';
        echo '<a href="' . esc_url($base) . '">Transactions</a>';
        echo '<a href="' . esc_url(add_query_arg('to403_view', 'claims', $base)) . '">Expense Claims</a>';
        echo '<a class="active" data-to403-tools="1" href="' . esc_url(add_query_arg('to403_view', 'tools', $base)) . '">Tools</a>';
        echo '</nav>';
    }

    private function card($title, $class, callable $body) {
        echo '<section class="to403-tool-card ' . esc_attr($class) . '"><h3>' . esc_html($title) . '</h3>';
        try { $body(); } catch (Throwable $e) { echo '<p class="to403-tools-error">' . esc_html($e->getMessage()) . '</p>'; }
        echo '</section>';
    }

    /**
     * A card that can be rolled up. $badge is ['type' => 'bad'|'warn'|'ok', 'text' => '...'] and is shown
     * in the title bar, so problems are visible while the card is closed.
     */
    private function fold_card($title, $class, $open, callable $body, $badge = null) {
        echo '<details class="to403-tool-card to403-fold ' . esc_attr($class) . '"' . ($open ? ' open' : '') . '><summary><h3>' . esc_html($title) . '</h3>';
        if ($badge) echo '<span class="to403-badge ' . esc_attr($badge['type']) . '">' . esc_html($badge['text']) . '</span>';
        echo '<span class="to403-fold-hint" aria-hidden="true"></span></summary><div class="to403-fold-body">';
        try { $body(); } catch (Throwable $e) { echo '<p class="to403-tools-error">' . esc_html($e->getMessage()) . '</p>'; }
        echo '</div></details>';
    }

    private function table($headers, $rows, $class = '') {
        echo '<table class="to403-summary ' . esc_attr($class) . '"><thead><tr>';
        foreach ($headers as $h) echo '<th>' . esc_html($h) . '</th>';
        echo '</tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="' . count($headers) . '">Nothing to show.</td></tr>';
        foreach ($rows as $r) {
            $cls = '';
            if (isset($r['_class'])) { $cls = $r['_class']; unset($r['_class']); }
            echo '<tr class="' . esc_attr($cls) . '">';
            foreach (array_values($r) as $c) echo '<td>' . esc_html((string)$c) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function show_result() {
        $key = 'to403_tools_result_' . get_current_user_id();
        $r = get_transient($key);
        if (!$r) return;
        delete_transient($key);
        echo '<div id="to403-tools-result" class="to403-tools-notice ' . esc_attr($r['type']) . '"><strong>' . esc_html($r['message']) . '</strong>';
        foreach ((array)($r['tables'] ?? []) as $t) {
            if (!empty($t['title'])) echo '<h4>' . esc_html($t['title']) . '</h4>';
            $this->table($t['headers'], $t['rows']);
        }
        echo '</div>';
    }

    public function render() {
        try {
            $this->access();
            ob_start();
            echo '<div class="to403-register to403-tools"><h2>Transaction Inbox</h2>';
            $this->tabs();
            $this->show_result();

            $health = null;
            try { $health = $this->interac_health(); } catch (Throwable $e) { $health = ['verdict' => 'bad', 'why' => $e->getMessage()]; }
            $badge = null;
            if ($health['verdict'] === 'bad') $badge = ['type' => 'bad', 'text' => 'Interac importer failing'];
            elseif ($health['verdict'] === 'warn') $badge = ['type' => 'warn', 'text' => 'Interac importer quiet'];
            elseif ($this->recent_failed_batches() > 0) $badge = ['type' => 'warn', 'text' => 'Recent import failed'];

            $this->fold_card('1. Inbox summary', '', true, [$this, 'render_summary']);
            $this->fold_card('2. Importer status', '', false, [$this, 'render_status'], $badge);
            $this->fold_card('3. Cut-over dates', '', false, [$this, 'render_cutover']);
            echo '<div class="to403-tools-grid">';
            $this->card('4. OFX import', '', [$this, 'render_ofx']);
            $this->card('5. Gravity Forms backfill', '', [$this, 'render_gf']);
            $this->card('6. Wave balance check', '', [$this, 'render_wave']);
            $this->card('Wave export', 'planned', function () {
                echo '<p><strong>Not yet implemented.</strong> Nothing can be sent to Wave from this page.</p>';
                echo '<p>Planned: export preview, selection by date and review status, allocation checks against the Wave chart of accounts, duplicate protection using stored Wave transaction IDs, export history, and explicit approval before each export.</p>';
            });
            echo '</div>';
            $this->fold_card('Reset inbox', 'danger-zone', false, [$this, 'render_reset']);
            echo '</div>';
            return ob_get_clean();
        } catch (Throwable $e) {
            return '<div class="msg error">' . esc_html($e->getMessage()) . '</div>';
        }
    }

    public function render_summary() {
        $rows = $this->db()->query('SELECT source_system, COUNT(*) n, MIN(transaction_date) oldest, MAX(transaction_date) newest FROM inbox_observations GROUP BY source_system ORDER BY source_system')->fetchAll();
        $out = []; $total = 0;
        foreach ($rows as $r) {
            $total += (int)$r['n'];
            $cut = $this->cutover($r['source_system']);
            $early = ($cut && $r['oldest'] < $cut) ? 'before-cutover' : '';
            $out[] = ['_class' => $early, self::SOURCES[$r['source_system']] ?? $r['source_system'], number_format_i18n((int)$r['n']), $r['oldest'], $r['newest'], $cut ?: '—'];
        }
        $out[] = ['_class' => 'total', 'Total', number_format_i18n($total), '', '', ''];
        $this->table(['Source', 'Count', 'Oldest', 'Newest', 'Cut-over'], $out);
        echo '<p class="help-text">Highlighted rows still contain records dated before their cut-over date.</p>';
    }

    /* ------------------------------------------------------------------ */
    /* Importer status                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Read the end of the Interac cron log. Returns null when the file is not readable.
     * Cron appends stdout and stderr here, so this also catches failures that happen
     * before the processor reaches the database (for example a missing config file).
     */
    private function cron_log_state() {
        $path = (string)$this->settings()['cron_log'];
        if ($path === '' || !@is_readable($path)) return null;
        $size = @filesize($path);
        $fh = @fopen($path, 'rb');
        if (!$fh) return null;
        if ($size > 65536) fseek($fh, -65536, SEEK_END);
        $text = (string)stream_get_contents($fh);
        fclose($fh);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)), 'strlen'));
        $state = ['path' => $path, 'mtime' => (int)@filemtime($path), 'last_line' => end($lines) ?: '', 'last_ok' => '', 'last_error' => '', 'failing' => false];
        foreach (array_reverse($lines) as $l) {
            $is_ok = (bool)preg_match('/^\[[0-9: -]{19}\] Processed=/', $l);
            $is_err = (bool)preg_match('/FATAL|Fatal error|Missing configuration|failed|PHP Warning|Permission denied|Uncaught/i', $l);
            if ($is_ok && $state['last_ok'] === '') $state['last_ok'] = $l;
            if ($is_err && $state['last_error'] === '') $state['last_error'] = $l;
            if ($state['last_ok'] !== '' && $state['last_error'] !== '') break;
        }
        // Failing when the newest meaningful line is an error rather than a run summary.
        foreach (array_reverse($lines) as $l) {
            if (preg_match('/^\[[0-9: -]{19}\] Processed=/', $l)) break;
            if (preg_match('/FATAL|Fatal error|Missing configuration|Uncaught/i', $l)) { $state['failing'] = true; break; }
        }
        return $state;
    }

    private function batch_time_col($rows) {
        if ($rows) foreach (array_keys($rows[0]) as $c) if (preg_match('/^(created|started|imported|run)_?(at|on)?$/i', $c)) return $c;
        return '';
    }

    /** One readable line from a batch's notes; the full text goes in a tooltip. */
    private function short_note($notes) {
        $notes = (string)$notes;
        if (stripos($notes, 'FAILED') === 0) return wp_trim_words($notes, 14, '…');
        $bits = [];
        foreach (['inserted', 'refreshed', 'skipped_before_cutover', 'rejected', 'Observations imported', 'duplicates', 'skipped', 'last UID'] as $k) {
            if (preg_match('/' . preg_quote($k, '/') . '=(\d+)/i', $notes, $m)) $bits[] = str_replace('_', ' ', strtolower($k)) . ' ' . $m[1];
        }
        if (preg_match('/statement=([0-9-]+) to ([0-9-]+)/', $notes, $m)) $bits[] = $m[1] . ' → ' . $m[2];
        return $bits ? implode(' · ', $bits) : wp_trim_words($notes, 14, '…');
    }

    private $batchCache = null;

    private function recent_batches() {
        if ($this->batchCache === null) $this->batchCache = $this->db()->query('SELECT * FROM inbox_import_batches ORDER BY batch_id DESC LIMIT ' . (int)self::STATUS_MORE)->fetchAll();
        return $this->batchCache;
    }

    /** Failed batches among those shown by default (newest first). */
    private function recent_failed_batches() {
        $n = 0;
        foreach (array_slice($this->recent_batches(), 0, self::STATUS_ROWS) as $r) if (stripos((string)$r['notes'], 'FAILED') === 0) $n++;
        return $n;
    }

    /** Interac importer health, used for the card body and for the badge on the closed card. */
    private function interac_health() {
        $db = $this->db();
        $rows = $this->recent_batches();
        $time_col = $this->batch_time_col($rows);
        $uid = null;
        try {
            $q = $db->prepare('SELECT state_value FROM processor_state WHERE state_key = ?');
            $q->execute(['last_processed_uid']);
            $uid = $q->fetchColumn();
        } catch (Throwable $e) { $uid = false; }
        $last_batch = null;
        foreach ($rows as $r) if ($r['source_system'] === 'interac_email') { $last_batch = $r; break; }
        $log = $this->cron_log_state();
        $verdict = 'ok'; $why = '';
        if ($log === null) {
            if ($last_batch && stripos((string)$last_batch['notes'], 'FAILED') === 0) { $verdict = 'bad'; $why = $last_batch['notes']; }
            elseif ($last_batch && $time_col && (time() - strtotime($last_batch[$time_col] . ' ' . wp_timezone_string())) > self::STALE_HOURS * HOUR_IN_SECONDS) { $verdict = 'warn'; $why = 'No run recorded in the last ' . self::STALE_HOURS . ' hours.'; }
        } else {
            if ($log['failing']) { $verdict = 'bad'; $why = $log['last_error']; }
            elseif (time() - $log['mtime'] > self::STALE_HOURS * HOUR_IN_SECONDS) { $verdict = 'warn'; $why = 'The cron log has not been written for ' . human_time_diff($log['mtime']) . '. Check that the cron job is still scheduled.'; }
        }
        return compact('uid', 'last_batch', 'log', 'verdict', 'why', 'time_col');
    }

    public function render_status() {
        $rows = $this->recent_batches();
        $h = $this->interac_health();
        $time_col = $h['time_col']; $log = $h['log']; $last_batch = $h['last_batch'];

        // ---- Interac e-Transfer importer health ----
        echo '<h4>Interac e-Transfer importer <span class="help-text">(hourly cron on the server)</span></h4><ul class="to403-health">';
        echo '<li>Last processed IMAP UID: <strong>' . esc_html($h['uid'] !== false && $h['uid'] !== null ? (string)$h['uid'] : 'not readable') . '</strong></li>';
        if ($last_batch) {
            $when = $time_col ? (string)$last_batch[$time_col] : '';
            echo '<li>Last run recorded in the database: ' . esc_html($when ?: 'batch ' . $last_batch['batch_id']) . ' — ' . esc_html($this->short_note($last_batch['notes'])) . '</li>';
        }
        if ($log === null) {
            echo '<li class="help-text">Cron log not readable at <code>' . esc_html((string)$this->settings()['cron_log']) . '</code>. Failures before the database is reached (such as a missing config file) cannot be detected.</li>';
        } else {
            echo '<li>Cron log last written ' . esc_html(human_time_diff($log['mtime'])) . ' ago.</li>';
            if ($log['last_ok'] !== '') echo '<li>Last successful run: <code>' . esc_html($log['last_ok']) . '</code></li>';
            if ($h['verdict'] === 'ok' && $log['last_error'] !== '') echo '<li class="help-text">Earlier warning in the log (resolved since): <code>' . esc_html($log['last_error']) . '</code></li>';
        }
        echo '</ul>';
        if ($h['verdict'] === 'bad') echo '<p class="status-bad">Interac importer is failing: ' . esc_html($h['why']) . '</p>';
        elseif ($h['verdict'] === 'warn') echo '<p class="status-warn">' . esc_html($h['why']) . '</p>';
        else echo '<p class="status-ok">Interac importer looks healthy.</p>';

        echo '<details class="to403-settings"><summary>Cron log location</summary>';
        $this->form_open('save_status');
        echo '<label>Path to the Interac cron log <input type="text" class="wide" name="cron_log" value="' . esc_attr((string)$this->settings()['cron_log']) . '"></label>';
        $this->button('Save', 'button');
        echo '</form><p class="help-text">The web server needs read access to this file and its folders.</p></details>';

        // ---- Recent import batches ----
        echo '<h4>Recent imports</h4>';
        $head = '<table class="to403-summary compact status"><thead><tr><th>Batch</th><th>When</th><th>Source</th><th>File</th><th>Rows</th><th>Rejected</th><th>Summary</th></tr></thead><tbody>';
        $render_rows = function ($list) use ($time_col) {
            foreach ($list as $r) {
                $failed = stripos((string)$r['notes'], 'FAILED') === 0;
                echo '<tr class="' . ($failed ? 'failed' : '') . '"><td>' . (int)$r['batch_id'] . '</td><td class="nowrap">' . esc_html($time_col ? (string)$r[$time_col] : '') . '</td><td class="nowrap">' . esc_html(self::SOURCES[$r['source_system']] ?? $r['source_system']) . '</td><td class="file" title="' . esc_attr((string)($r['source_filename'] ?? '')) . '">' . esc_html((string)($r['source_filename'] ?? '')) . '</td><td>' . (int)$r['rows_imported'] . '</td><td>' . (int)$r['rows_rejected'] . '</td><td title="' . esc_attr((string)$r['notes']) . '">' . esc_html($this->short_note($r['notes'])) . '</td></tr>';
            }
        };
        echo $head;
        $render_rows(array_slice($rows, 0, self::STATUS_ROWS));
        if (!$rows) echo '<tr><td colspan="7">No imports yet.</td></tr>';
        echo '</tbody></table>';
        if (count($rows) > self::STATUS_ROWS) {
            echo '<details><summary>Show ' . (count($rows) - self::STATUS_ROWS) . ' more</summary>' . $head;
            $render_rows(array_slice($rows, self::STATUS_ROWS));
            echo '</tbody></table></details>';
        }
        echo '<p class="help-text">Hover over a summary to see the full notes.</p>';
    }

    public function action_save_status() {
        try {
            $this->guard('save_status');
            $s = $this->settings();
            $path = trim(sanitize_text_field(wp_unslash($_POST['cron_log'] ?? '')));
            if ($path !== '' && $path[0] !== '/') throw new RuntimeException('Enter an absolute path starting with /.');
            $s['cron_log'] = $path;
            $this->save_settings($s);
            $this->done('success', 'Cron log location saved.' . ($path !== '' && !@is_readable($path) ? ' Warning: the web server cannot read that file.' : ''));
        } catch (Throwable $e) { $this->fail($e); }
    }

    /* ------------------------------------------------------------------ */
    /* Other cards                                                         */
    /* ------------------------------------------------------------------ */

    public function render_cutover() {
        $s = $this->settings();
        echo '<p>Set the first date each source should be trusted from. For bank accounts, use the day after the last Wave reconciliation. Leave blank for no cut-over. OFX uploads skip transactions before the cut-over date.</p>';
        $this->form_open('save_cutover');
        echo '<div class="to403-cutover-grid">';
        foreach (self::SOURCES as $key => $label) {
            echo '<label>' . esc_html($label) . ' <input type="date" name="cutover[' . esc_attr($key) . ']" value="' . esc_attr($s['cutover'][$key] ?? '') . '"></label>';
        }
        echo '</div>';
        $this->button('Save cut-over dates', 'button');
        echo '</form>';
        echo '<div class="to403-inline-forms">';
        $this->form_open('cutover_preview');
        $this->button('Preview cleanup', 'button');
        echo '</form>';
        $this->form_open('cutover_apply');
        echo '<label class="confirm"><input type="checkbox" name="confirm" value="yes" required> I understand records before each cut-over date will be deleted.</label>';
        $this->button('Delete records before cut-over', 'button button-danger');
        echo '</form></div>';
    }

    public function render_ofx() {
        $s = $this->settings();
        $pending = get_transient('to403_tools_ofx_' . get_current_user_id());
        if ($pending) {
            echo '<p><strong>' . count($pending) . ' file(s) previewed and ready.</strong> Review the preview above, then import or cancel.</p><div class="to403-inline-forms">';
            $this->form_open('ofx_import');
            $this->button('Import previewed files');
            echo '</form>';
            $this->form_open('ofx_cancel');
            $this->button('Cancel', 'button');
            echo '</form></div>';
        } else {
            echo '<p>Bank and credit-card statements are imported here. Upload OFX or QFX files downloaded from Alterna or the Visa site (a <code>.txt</code> suffix is fine). Preview shows what will be added before anything is written. Re-importing the same file is safe.</p>';
            $this->form_open('ofx_preview', true);
            echo '<input type="file" name="ofx_files[]" multiple accept=".ofx,.qfx,.txt" required>';
            $this->button('Preview');
            echo '</form>';
        }
        echo '<details><summary>OFX account mapping (' . count($s['ofx_accounts']) . ')</summary>';
        echo '<p>One account per line: <code>ACCTID | source | statement type | name</code>. Source is one of <code>' . esc_html(implode(', ', self::BANK_SOURCES)) . '</code>; statement type is <code>bank</code> or <code>credit_card</code>. Find the ACCTID inside the OFX file.</p>';
        $lines = [];
        foreach ($s['ofx_accounts'] as $id => $a) $lines[] = $id . ' | ' . $a['source_system'] . ' | ' . $a['statement_type'] . ' | ' . $a['account_name'];
        $this->form_open('save_ofx_accounts');
        echo '<textarea name="ofx_accounts" rows="4" class="code">' . esc_textarea(implode("\n", $lines)) . '</textarea>';
        $this->button('Save mapping', 'button');
        echo '</form></details>';
    }

    public function render_wave() {
        $s = $this->settings();
        $accounts = $this->wave_bank_accounts();
        echo '<p>Compares Wave\'s current balance with the latest OFX ledger balance, allowing for imported transactions not yet exported to Wave. Read-only: nothing is stored or sent.</p>';
        if (!$accounts) {
            echo '<p class="to403-tools-error">No Wave bank or credit-card accounts found. Open Forms → Wave Sync and click Discover Accounts.</p>';
            return;
        }
        $this->form_open('save_wave_accounts');
        echo '<div class="to403-cutover-grid">';
        foreach (self::BANK_SOURCES as $src) {
            $sel = $s['wave_accounts'][$src] ?? $this->guess_wave_account($src, $accounts);
            echo '<label>' . esc_html(self::SOURCES[$src]) . ' <select name="wave_accounts[' . esc_attr($src) . ']"><option value="">Not mapped</option>';
            foreach ($accounts as $a) echo '<option value="' . esc_attr($a['id']) . '"' . selected($sel, $a['id'], false) . '>' . esc_html($a['name']) . '</option>';
            echo '</select></label>';
        }
        echo '</div>';
        $this->button('Save mapping', 'button');
        echo '</form>';
        $this->form_open('wave_check');
        $this->button('Check balances now');
        echo '</form>';
    }

    public function render_gf() {
        echo '<p>Imports or refreshes expense-claim entries from the form selected in Wave Sync. Only inbox records are updated; an entry\'s Wave export status is never changed.</p>';
        $date = '<label>Entries created on or after <input type="date" name="from_date" required value="' . esc_attr(wp_date('Y-m-d', strtotime('-30 days'))) . '"></label>';
        echo '<div class="to403-inline-forms">';
        $this->form_open('gf_preview'); echo $date; $this->button('Preview', 'button'); echo '</form>';
        $this->form_open('gf_import'); echo $date; $this->button('Import'); echo '</form>';
        echo '</div>';
    }

    public function render_reset() {
        echo '<p>Deletes every inbox observation with its allocations, matches and reviews. Import batch history is kept. Gravity Forms entries, emails, OFX files and Wave are not touched. Back up the database first.</p>';
        echo '<div class="to403-inline-forms">';
        $this->form_open('reset_preview'); $this->button('Preview reset', 'button'); echo '</form>';
        $this->form_open('reset');
        echo '<label class="confirm"><input type="checkbox" name="confirm" value="RESET" required> I understand all inbox data will be deleted.</label>';
        echo '<label>Type <code>RESET INBOX</code> <input type="text" name="confirmation_text" autocomplete="off" required></label>';
        $this->button('Reset inbox', 'button button-danger');
        echo '</form></div>';
    }

    /* ------------------------------------------------------------------ */
    /* Deletion helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function ids_where($where, $params) {
        $q = $this->db()->prepare('SELECT observation_id FROM inbox_observations WHERE ' . $where);
        $q->execute($params);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    private function count_in($sql, $ids, $twice = false) {
        if (!$ids) return 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db()->prepare(str_replace('%IDS%', $ph, $sql));
        $q->execute($twice ? array_merge($ids, $ids) : $ids);
        return (int)$q->fetchColumn();
    }

    private function delete_ids($ids) {
        $db = $this->db();
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $db->prepare("DELETE FROM inbox_matches WHERE left_observation_id IN ($ph) OR right_observation_id IN ($ph)")->execute(array_merge($chunk, $chunk));
            $db->prepare("DELETE FROM inbox_allocations WHERE bank_observation_id IN ($ph)")->execute($chunk);
            try { $db->prepare("DELETE FROM inbox_reviews WHERE bank_observation_id IN ($ph)")->execute($chunk); } catch (Throwable $e) { /* table may be absent */ }
            $db->prepare("DELETE FROM inbox_observations WHERE observation_id IN ($ph)")->execute($chunk);
        }
    }

    private function cutover_plan() {
        $plan = [];
        foreach (self::SOURCES as $src => $label) {
            $cut = $this->cutover($src);
            if (!$cut) continue;
            $ids = $this->ids_where('source_system = ? AND transaction_date < ?', [$src, $cut]);
            $plan[$src] = ['label' => $label, 'cutover' => $cut, 'ids' => $ids];
        }
        return $plan;
    }

    /* ------------------------------------------------------------------ */
    /* Actions: cut-over                                                   */
    /* ------------------------------------------------------------------ */

    public function action_save_cutover() {
        try {
            $this->guard('save_cutover');
            $s = $this->settings();
            $in = (array)($_POST['cutover'] ?? []);
            $s['cutover'] = [];
            foreach (self::SOURCES as $src => $label) {
                $v = sanitize_text_field(wp_unslash($in[$src] ?? ''));
                if ($v === '') continue;
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v) throw new RuntimeException('Invalid date for ' . $label . '.');
                $s['cutover'][$src] = $v;
            }
            $this->save_settings($s);
            $this->done('success', 'Cut-over dates saved.');
        } catch (Throwable $e) { $this->fail($e); }
    }

    public function action_cutover_preview() {
        try {
            $this->guard('cutover_preview');
            $plan = $this->cutover_plan();
            if (!$plan) throw new RuntimeException('No cut-over dates are set.');
            $rows = []; $all = [];
            foreach ($plan as $p) {
                $rows[] = [$p['label'], $p['cutover'], count($p['ids'])];
                $all = array_merge($all, $p['ids']);
            }
            $rows[] = ['_class' => 'total', 'Total', '', count($all)];
            $deps = [
                ['Allocations', $this->count_in('SELECT COUNT(*) FROM inbox_allocations WHERE bank_observation_id IN (%IDS%)', $all)],
                ['Matches', $this->count_in('SELECT COUNT(*) FROM inbox_matches WHERE left_observation_id IN (%IDS%) OR right_observation_id IN (%IDS%)', $all, true)],
            ];
            $this->done('success', 'Cleanup preview. Nothing has been deleted.', [
                ['title' => 'Observations before cut-over', 'headers' => ['Source', 'Cut-over', 'To delete'], 'rows' => $rows],
                ['title' => 'Linked records also deleted', 'headers' => ['Type', 'Count'], 'rows' => $deps],
            ]);
        } catch (Throwable $e) { $this->fail($e); }
    }

    public function action_cutover_apply() {
        try {
            $this->guard('cutover_apply');
            if (($_POST['confirm'] ?? '') !== 'yes') throw new RuntimeException('Tick the confirmation box first.');
            $plan = $this->cutover_plan();
            if (!$plan) throw new RuntimeException('No cut-over dates are set.');
            $db = $this->db(); $rows = [];
            $db->beginTransaction();
            foreach ($plan as $p) {
                $this->delete_ids($p['ids']);
                $rows[] = [$p['label'], $p['cutover'], count($p['ids'])];
            }
            $db->commit();
            $this->done('success', 'Cut-over cleanup complete.', [['headers' => ['Source', 'Cut-over', 'Deleted'], 'rows' => $rows]]);
        } catch (Throwable $e) { $this->fail($e); }
    }

    /* ------------------------------------------------------------------ */
    /* Actions: OFX                                                        */
    /* ------------------------------------------------------------------ */

    public function action_save_ofx_accounts() {
        try {
            $this->guard('save_ofx_accounts');
            $s = $this->settings(); $map = [];
            foreach (preg_split('/\r\n|\r|\n/', (string)wp_unslash($_POST['ofx_accounts'] ?? '')) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $p = array_map('trim', explode('|', $line));
                if (count($p) < 3) throw new RuntimeException('Each line needs at least ACCTID | source | statement type.');
                [$id, $src, $type] = $p;
                if (!in_array($src, self::BANK_SOURCES, true)) throw new RuntimeException('Unknown source "' . $src . '".');
                if (!in_array($type, ['bank', 'credit_card'], true)) throw new RuntimeException('Statement type must be bank or credit_card.');
                $map[sanitize_text_field($id)] = ['source_system' => $src, 'statement_type' => $type, 'account_name' => sanitize_text_field($p[3] ?? self::SOURCES[$src])];
            }
            if (!$map) throw new RuntimeException('Add at least one account.');
            $s['ofx_accounts'] = $map;
            $this->save_settings($s);
            $this->done('success', 'OFX account mapping saved (' . count($map) . ' accounts).');
        } catch (Throwable $e) { $this->fail($e); }
    }

    public function action_ofx_preview() {
        try {
            $this->guard('ofx_preview');
            $files = $_FILES['ofx_files'] ?? null;
            if (!$files || empty($files['name'])) throw new RuntimeException('Choose at least one file.');
            $stored = []; $bytes = 0;
            foreach ((array)$files['name'] as $i => $name) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed for ' . $name . '.');
                $raw = file_get_contents($files['tmp_name'][$i]);
                $bytes += strlen((string)$raw);
                if ($bytes > self::MAX_UPLOAD_BYTES) throw new RuntimeException('Files are larger than 5 MB in total.');
                $stored[] = ['name' => sanitize_file_name($name), 'raw' => (string)$raw];
            }
            $summary = []; $detail = [];
            foreach ($stored as $f) {
                try {
                    $plan = $this->ofx_plan($f['name'], $f['raw']);
                    $summary[] = [$f['name'], $plan['account_name'], $plan['start'] . ' → ' . $plan['end'], $plan['ledger_balance'] . ' (' . $plan['ledger_date'] . ')', $plan['counts']['new'], $plan['counts']['existing'], $plan['counts']['before_cutover'], $plan['counts']['rejected']];
                    foreach ($plan['rejections'] as $msg) $detail[] = [$f['name'], $msg];
                } catch (Throwable $e) {
                    $summary[] = ['_class' => 'failed', $f['name'], 'FAILED', $e->getMessage(), '', '', '', '', ''];
                }
            }
            set_transient('to403_tools_ofx_' . get_current_user_id(), $stored, 30 * MINUTE_IN_SECONDS);
            $tables = [['title' => 'OFX preview', 'headers' => ['File', 'Account', 'Statement', 'Ledger balance', 'New', 'Already imported', 'Before cut-over (skipped)', 'Rejected'], 'rows' => $summary]];
            if ($detail) $tables[] = ['title' => 'Rejected transactions', 'headers' => ['File', 'Reason'], 'rows' => $detail];
            $this->done('success', 'Preview ready. Nothing has been written. Use Import in the OFX card to continue.', $tables);
        } catch (Throwable $e) { $this->fail($e); }
    }

    public function action_ofx_cancel() {
        $this->guard('ofx_cancel');
        delete_transient('to403_tools_ofx_' . get_current_user_id());
        $this->done('success', 'OFX preview discarded.');
    }

    public function action_ofx_import() {
        try {
            $this->guard('ofx_import');
            $key = 'to403_tools_ofx_' . get_current_user_id();
            $stored = get_transient($key);
            if (!$stored) throw new RuntimeException('The preview has expired. Upload the files again.');
            delete_transient($key);
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
        } catch (Throwable $e) { $this->fail($e); }
    }

    /** Parse one file and classify each transaction without writing anything. */
    private function ofx_plan($filename, $raw) {
        $st = TO403_OFX_Parser::parse($raw);
        $s = $this->settings();
        $acct = $s['ofx_accounts'][$st['account_id']] ?? null;
        if (!$acct) throw new RuntimeException('Account ' . $st['account_id'] . ' is not in the OFX account mapping.');
        if ($acct['statement_type'] !== $st['statement_type']) throw new RuntimeException(sprintf('Account %s is mapped as %s but the file is a %s statement.', $st['account_id'], $acct['statement_type'], $st['statement_type']));
        $src = $acct['source_system'];
        $cut = $this->cutover($src);
        $keys = [];
        foreach ($st['transactions'] as $t) if (empty($t['error'])) $keys[] = $src . ':' . $st['account_id'] . ':' . $t['fitid'];
        $existing = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db()->prepare("SELECT source_key FROM inbox_observations WHERE source_system = ? AND source_key IN ($ph)");
            $q->execute(array_merge([$src], $chunk));
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $k) $existing[$k] = true;
        }
        $counts = ['new' => 0, 'existing' => 0, 'before_cutover' => 0, 'rejected' => 0];
        $rejections = [];
        foreach ($st['transactions'] as $i => $t) {
            if (!empty($t['error'])) { $counts['rejected']++; $rejections[] = 'Transaction ' . ($i + 1) . ': ' . $t['error']; continue; }
            if ($cut && $t['date'] < $cut) { $counts['before_cutover']++; continue; }
            $k = $src . ':' . $st['account_id'] . ':' . $t['fitid'];
            isset($existing[$k]) ? $counts['existing']++ : $counts['new']++;
        }
        return [
            'statement' => $st, 'source_system' => $src, 'account_name' => $acct['account_name'], 'cutover' => $cut,
            'start' => $st['statement_start'] ?: '?', 'end' => $st['statement_end'] ?: '?',
            'ledger_balance' => $st['ledger_balance'] !== '' ? $st['ledger_balance'] : '?', 'ledger_date' => $st['ledger_date'] ?: '?',
            'counts' => $counts, 'rejections' => $rejections,
        ];
    }

    /** Writes the same records the retired command-line ingester did, so earlier imports stay compatible. */
    private function ofx_import_file($filename, $raw) {
        $plan = $this->ofx_plan($filename, $raw);
        $st = $plan['statement']; $src = $plan['source_system']; $cut = $plan['cutover'];
        $db = $this->db();
        $db->prepare('INSERT INTO inbox_import_batches (source_system, source_filename, rows_imported, rows_rejected, notes) VALUES (?, ?, 0, 0, ?)')
           ->execute([$src, $filename, sprintf('Web OFX import started. Account=%s; type=%s; ACCTID=%s; transactions=%d.', $plan['account_name'], $st['statement_type'], $st['account_id'], count($st['transactions']))]);
        $batch = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO inbox_observations (import_batch_id, source_system, source_key, transaction_date, amount, direction, description, payments_reference_number, gf_entry_id, wave_transaction_id, payload_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?)
            ON DUPLICATE KEY UPDATE import_batch_id = VALUES(import_batch_id), transaction_date = VALUES(transaction_date), amount = VALUES(amount), direction = VALUES(direction), description = VALUES(description), payload_json = VALUES(payload_json)');
        $n = ['inserted' => 0, 'refreshed' => 0, 'skipped' => 0, 'rejected' => 0]; $msgs = [];
        foreach ($st['transactions'] as $i => $t) {
            if (!empty($t['error'])) { $n['rejected']++; $msgs[] = 'Transaction ' . ($i + 1) . ' rejected: ' . $t['error']; continue; }
            if ($cut && $t['date'] < $cut) { $n['skipped']++; continue; }
            $payload = wp_json_encode([
                'format' => $st['format'], 'ofx_version' => $st['version'], 'source_filename' => $filename,
                'statement_type' => $st['statement_type'], 'account_name' => $plan['account_name'], 'account_id' => $st['account_id'],
                'account_type' => $st['account_type'], 'bank_id' => $st['bank_id'], 'branch_id' => $st['branch_id'], 'currency' => $st['currency'],
                'statement_start' => $st['statement_start'], 'statement_end' => $st['statement_end'],
                'ledger_balance' => $st['ledger_balance'], 'ledger_date' => $st['ledger_date'],
                'transaction_type' => $t['trntype'], 'posted_date_raw' => $t['dtposted'], 'amount' => number_format($t['amount'], 2, '.', ''),
                'fitid' => $t['fitid'], 'name' => $t['name'], 'memo' => $t['memo'], 'check_number' => $t['checknum'], 'reference_number' => $t['refnum'],
                'raw_transaction' => $t['raw'], 'imported_via' => 'transaction-inbox-tools ' . self::VERSION,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ins->execute([$batch, $src, $src . ':' . $st['account_id'] . ':' . $t['fitid'], $t['date'], number_format($t['amount'], 2, '.', ''), $t['amount'] < 0 ? 'expense' : 'income', TO403_OFX_Parser::description($t['name'], $t['memo']), $payload]);
            $ins->rowCount() === 1 ? $n['inserted']++ : $n['refreshed']++;
        }
        $notes = sprintf('Completed (web). Account=%s; type=%s; ACCTID=%s; inserted=%d; refreshed=%d; skipped_before_cutover=%d; rejected=%d; statement=%s to %s; ledger=%s %s.',
            $plan['account_name'], $st['statement_type'], $st['account_id'], $n['inserted'], $n['refreshed'], $n['skipped'], $n['rejected'],
            $st['statement_start'] ?: 'unknown', $st['statement_end'] ?: 'unknown', $st['currency'] ?: 'unknown currency', $st['ledger_balance'] !== '' ? $st['ledger_balance'] : 'unknown balance');
        if ($msgs) $notes .= ' ' . implode(' | ', $msgs);
        $db->prepare('UPDATE inbox_import_batches SET rows_imported = ?, rows_rejected = ?, notes = ? WHERE batch_id = ?')->execute([$n['inserted'] + $n['refreshed'], $n['rejected'], $notes, $batch]);
        return $n + ['account_name' => $plan['account_name'], 'batch_id' => $batch];
    }

    /* ------------------------------------------------------------------ */
    /* Actions: Wave balance check                                         */
    /* ------------------------------------------------------------------ */

    private function wave_bank_accounts() {
        if (!class_exists('GF_Wave_Settings')) return [];
        $s = GF_Wave_Settings::get(); $out = [];
        foreach ((array)($s['discovered_accounts'] ?? []) as $a) {
            if (!empty($a['is_archived'])) continue;
            if (!in_array($a['subtype_value'] ?? '', ['CASH_AND_BANK', 'CREDIT_CARD'], true)) continue;
            $out[] = $a;
        }
        return $out;
    }

    private function guess_wave_account($src, $accounts) {
        $names = ['ofx_operating' => 'Operating Acct', 'ofx_cg_funds' => 'CG Funds', 'ofx_visa' => 'GC Visa'];
        foreach ($accounts as $a) if (strcasecmp($a['name'], $names[$src] ?? '') === 0) return $a['id'];
        return '';
    }

    public function action_save_wave_accounts() {
        try {
            $this->guard('save_wave_accounts');
            $s = $this->settings(); $in = (array)($_POST['wave_accounts'] ?? []); $s['wave_accounts'] = [];
            foreach (self::BANK_SOURCES as $src) $s['wave_accounts'][$src] = sanitize_text_field(wp_unslash($in[$src] ?? ''));
            $this->save_settings($s);
            $this->done('success', 'Wave account mapping saved.');
        } catch (Throwable $e) { $this->fail($e); }
    }

    public function action_wave_check() {
        try {
            $this->guard('wave_check');
            if (!class_exists('GF_Wave_API') || !class_exists('GF_Wave_Settings')) throw new RuntimeException('Gravity Forms Wave Sync must be active (it holds the Wave token and business).');
            $ws = GF_Wave_Settings::get();
            if (empty($ws['business_id'])) throw new RuntimeException('Select a Wave business in Forms → Wave Sync first.');
            $wave = $this->wave_balances($ws['business_id']);
            $s = $this->settings(); $accounts = $this->wave_bank_accounts(); $db = $this->db(); $rows = [];
            foreach (self::BANK_SOURCES as $src) {
                $wid = $s['wave_accounts'][$src] ?? $this->guess_wave_account($src, $accounts);
                if (!$wid || !isset($wave[$wid])) { $rows[] = [self::SOURCES[$src], 'Not mapped', '', '', '', '', '']; continue; }
                $w = $wave[$wid];
                $q = $db->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ledger_balance')) lb, JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ledger_date')) ld FROM inbox_observations WHERE source_system = ? HAVING lb IS NOT NULL AND lb <> '' ORDER BY ld DESC, lb LIMIT 1");
                $q->execute([$src]);
                $led = $q->fetch();
                // Wave reports credit-normal accounts (credit cards) as positive amounts owed; convert to the bank's sign.
                $wave_bank_sign = strtoupper((string)$w['normalBalanceType']) === 'CREDIT' ? -(float)$w['balance'] : (float)$w['balance'];
                if (!$led) { $rows[] = [self::SOURCES[$src], 'No OFX imported', '', '', $this->money($wave_bank_sign), '', $w['name']]; continue; }
                $cut = $this->cutover($src);
                $sql = "SELECT COALESCE(SUM(o.amount), 0) FROM inbox_observations o LEFT JOIN inbox_reviews r ON r.bank_observation_id = o.observation_id
                        WHERE o.source_system = ? AND o.transaction_date <= ? AND (o.wave_transaction_id IS NULL OR o.wave_transaction_id = '')
                        AND (r.review_status IS NULL OR r.review_status <> 'exported')" . ($cut ? ' AND o.transaction_date >= ?' : '');
                $u = $db->prepare($sql);
                $u->execute($cut ? [$src, $led['ld'], $cut] : [$src, $led['ld']]);
                $unexported = (float)$u->fetchColumn();
                $expected = (float)$led['lb'] - $unexported;
                $diff = round($wave_bank_sign - $expected, 2);
                $rows[] = [
                    '_class' => abs($diff) < 0.005 ? 'ok' : 'failed',
                    self::SOURCES[$src],
                    $this->money($led['lb']) . ' (' . $led['ld'] . ')',
                    $this->money($unexported),
                    $this->money($expected),
                    $this->money($wave_bank_sign),
                    abs($diff) < 0.005 ? 'Balanced' : $this->money($diff),
                    $w['name'],
                ];
            }
            $this->done('success', 'Wave balance check complete (Wave balances are as of today).', [[
                'headers' => ['Account', 'Bank (OFX ledger)', 'Not yet in Wave', 'Expected Wave', 'Actual Wave', 'Difference', 'Wave account'],
                'rows' => $rows,
            ]]);
        } catch (Throwable $e) { $this->fail($e); }
    }

    private function wave_balances($business_id) {
        $api = new GF_Wave_API();
        $query = 'query ($id: ID!, $page: Int!) { business(id: $id) { accounts(page: $page, pageSize: 100) { pageInfo { totalPages } edges { node { id name balance normalBalanceType isArchived } } } } }';
        $out = []; $page = 1; $pages = 1;
        do {
            $r = $api->request($query, ['id' => $business_id, 'page' => $page]);
            if (is_wp_error($r)) throw new RuntimeException('Wave request failed: ' . $r->get_error_message());
            $acc = $r['data']['business']['accounts'] ?? [];
            $pages = max(1, (int)($acc['pageInfo']['totalPages'] ?? 1));
            foreach (($acc['edges'] ?? []) as $e) { $n = $e['node'] ?? []; if (!empty($n['id'])) $out[$n['id']] = $n; }
            $page++;
        } while ($page <= $pages);
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Actions: Gravity Forms backfill                                     */
    /* ------------------------------------------------------------------ */

    private function gf_entries($from) {
        if (!class_exists('GFAPI')) throw new RuntimeException('Gravity Forms is not active.');
        if (!class_exists('GF_Wave_Settings')) throw new RuntimeException('Gravity Forms Wave Sync is not active.');
        $s = GF_Wave_Settings::get(); $form_id = (int)($s['form_id'] ?? 0);
        if (!$form_id) throw new RuntimeException('No form is selected in Forms → Wave Sync.');
        $form = GFAPI::get_form($form_id);
        if (!$form) throw new RuntimeException('The selected Gravity Form could not be loaded.');
        $entries = []; $offset = 0;
        do {
            $page = GFAPI::get_entries($form_id, ['status' => 'active', 'start_date' => $from . ' 00:00:00'], null, ['offset' => $offset, 'page_size' => 200]);
            if (is_wp_error($page)) throw new RuntimeException($page->get_error_message());
            $entries = array_merge($entries, $page); $offset += count($page);
        } while (count($page) === 200);
        return [$form, $entries, $s];
    }

    public function action_gf_preview() {
        try {
            $this->guard('gf_preview');
            $from = $this->posted_date('from_date');
            [$form, $entries] = $this->gf_entries($from);
            $ids = array_map(function ($e) { return (int)$e['id']; }, $entries);
            $existing = $this->count_in("SELECT COUNT(*) FROM inbox_observations WHERE source_system = 'gravity_forms' AND gf_entry_id IN (%IDS%)", $ids);
            $this->done('success', 'Gravity Forms preview. Nothing has been written.', [['headers' => ['Item', 'Value'], 'rows' => [
                ['Form', $form['title'] . ' (#' . $form['id'] . ')'], ['From date', $from], ['Entries found', count($entries)], ['Already in inbox', $existing], ['Missing from inbox', max(0, count($entries) - $existing)],
            ]]]);
        } catch (Throwable $e) { $this->fail($e); }
    }

    public function action_gf_import() {
        try {
            $this->guard('gf_import');
            $from = $this->posted_date('from_date');
            [$form, $entries, $s] = $this->gf_entries($from);
            if (!class_exists('GF_Wave_Sync') || !method_exists('GF_Wave_Sync', 'send_entry_to_inbox')) throw new RuntimeException('Gravity Forms Wave Sync 0.7.1 or later is required.');
            // Call the inbox writer directly. after_submission() also resets the entry's Wave export metadata, which a backfill must not do.
            $m = new ReflectionMethod('GF_Wave_Sync', 'send_entry_to_inbox');
            $m->setAccessible(true);
            $sync = GF_Wave_Sync::instance();
            $ok = 0; $failed = [];
            foreach ($entries as $entry) {
                $account = trim((string)rgar($entry, (string)($s['field_category'] ?? '')));
                $r = $m->invoke($sync, $entry, $form, $s, $account);
                if (is_wp_error($r)) $failed[] = ['#' . $entry['id'], $r->get_error_message()]; else $ok++;
            }
            $tables = [['headers' => ['Item', 'Value'], 'rows' => [['From date', $from], ['Imported or refreshed', $ok], ['Failed', count($failed)]]]];
            if ($failed) $tables[] = ['title' => 'Failures', 'headers' => ['Entry', 'Reason'], 'rows' => $failed];
            $this->done($failed ? 'warning' : 'success', 'Gravity Forms backfill finished.', $tables);
        } catch (Throwable $e) { $this->fail($e); }
    }

    /* ------------------------------------------------------------------ */
    /* Actions: reset                                                      */
    /* ------------------------------------------------------------------ */

    public function action_reset_preview() {
        try {
            $this->guard('reset_preview');
            $rows = [];
            foreach ($this->db()->query('SELECT source_system, COUNT(*) n FROM inbox_observations GROUP BY source_system')->fetchAll() as $r) {
                $rows[] = [self::SOURCES[$r['source_system']] ?? $r['source_system'], $r['n']];
            }
            $rows[] = ['Allocations', $this->db()->query('SELECT COUNT(*) FROM inbox_allocations')->fetchColumn()];
            $rows[] = ['Matches', $this->db()->query('SELECT COUNT(*) FROM inbox_matches')->fetchColumn()];
            $this->done('success', 'Reset preview. Nothing has been deleted.', [['headers' => ['Records', 'Count'], 'rows' => $rows]]);
        } catch (Throwable $e) { $this->fail($e); }
    }

    public function action_reset() {
        try {
            $this->guard('reset');
            if (($_POST['confirm'] ?? '') !== 'RESET' || trim((string)wp_unslash($_POST['confirmation_text'] ?? '')) !== 'RESET INBOX') throw new RuntimeException('Reset confirmation did not match.');
            $ids = $this->ids_where('1 = 1', []);
            $db = $this->db();
            $db->beginTransaction();
            $this->delete_ids($ids);
            $db->commit();
            $this->done('success', 'Inbox reset complete. Deleted ' . count($ids) . ' observations.');
        } catch (Throwable $e) { $this->fail($e); }
    }
}

/**
 * OFX parsing (originally ported from the retired ingest/ofx/ofx-ingest.php).
 * Source keys and payload fields must stay compatible with records already imported.
 */
final class TO403_OFX_Parser {
    public static function parse($raw) {
        if (trim($raw) === '') throw new RuntimeException('The file is empty.');
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $ofx = self::decode($raw);
        $type = self::statement_type($ofx);
        $account_id = self::account_tag($ofx, $type, 'ACCTID');
        if ($account_id === '') throw new RuntimeException('No account number (ACCTID) found in the file.');
        $blocks = preg_match_all('/<STMTTRN\b[^>]*>\s*(.*?)\s*<\/STMTTRN>/is', $ofx, $m) ? $m[1] : [];
        if (!$blocks) throw new RuntimeException('No transactions (STMTTRN) found in the file.');
        $txs = [];
        foreach ($blocks as $block) {
            $t = [
                'trntype' => self::tag($block, 'TRNTYPE'), 'dtposted' => self::tag($block, 'DTPOSTED'), 'trnamt' => self::tag($block, 'TRNAMT'),
                'fitid' => self::tag($block, 'FITID'), 'name' => self::tag($block, 'NAME'), 'memo' => self::tag($block, 'MEMO'),
                'checknum' => self::tag($block, 'CHECKNUM'), 'refnum' => self::tag($block, 'REFNUM'), 'raw' => trim($block), 'error' => '',
            ];
            try {
                $missing = [];
                foreach (['dtposted', 'trnamt', 'fitid'] as $f) if ($t[$f] === '') $missing[] = strtoupper($f);
                if ($missing) throw new RuntimeException('Missing required OFX fields: ' . implode(', ', $missing));
                $t['date'] = self::date($t['dtposted']);
                $amt = str_replace([',', ' '], '', trim($t['trnamt']));
                if ($amt === '' || !is_numeric($amt)) throw new RuntimeException('Invalid OFX amount: ' . $t['trnamt']);
                $t['amount'] = (float)$amt;
            } catch (Throwable $e) { $t['error'] = $e->getMessage(); }
            $txs[] = $t;
        }
        $start = self::tag($ofx, 'DTSTART'); $end = self::tag($ofx, 'DTEND'); $ldate = self::tag($ofx, 'DTASOF');
        return [
            'format' => preg_match('/^\s*<\?xml\b/i', $ofx) ? 'OFXXML' : 'OFXSGML',
            'version' => self::version($ofx),
            'statement_type' => $type,
            'account_id' => $account_id,
            'account_type' => $type === 'credit_card' ? 'CREDITCARD' : self::account_tag($ofx, 'bank', 'ACCTTYPE'),
            'bank_id' => $type === 'bank' ? self::account_tag($ofx, 'bank', 'BANKID') : '',
            'branch_id' => $type === 'bank' ? self::account_tag($ofx, 'bank', 'BRANCHID') : '',
            'currency' => self::tag($ofx, 'CURDEF'),
            'statement_start' => $start === '' ? null : self::date($start),
            'statement_end' => $end === '' ? null : self::date($end),
            'ledger_balance' => self::tag($ofx, 'BALAMT'),
            'ledger_date' => $ldate === '' ? null : self::date($ldate),
            'transactions' => $txs,
        ];
    }

    public static function description($name, $memo) {
        $name = trim($name); $memo = trim($memo);
        if ($name !== '' && $memo !== '') return $name . ' | ' . $memo;
        if ($memo !== '') return $memo;
        if ($name !== '') return $name;
        return '[No OFX description]';
    }

    private static function decode($raw) {
        $enc = self::encoding($raw);
        if (strcasecmp($enc, 'UTF-8') === 0) return $raw;
        if (function_exists('mb_convert_encoding')) return mb_convert_encoding($raw, 'UTF-8', $enc);
        if (function_exists('iconv')) { $c = iconv($enc, 'UTF-8//TRANSLIT', $raw); if ($c !== false) return $c; }
        return $raw;
    }

    private static function encoding($ofx) {
        $h = self::header($ofx, 'ENCODING'); $cs = self::header($ofx, 'CHARSET');
        if (strcasecmp($h, 'UTF-8') === 0 || $cs === '65001') return 'UTF-8';
        if (preg_match('/<\?xml[^>]*encoding=["\']([^"\']+)["\']/i', $ofx, $m)) {
            $x = strtoupper(trim($m[1]));
            if ($x === 'UTF-8') return 'UTF-8';
            if ($x === 'US-ASCII') return 'Windows-1252';
            return $x;
        }
        return 'Windows-1252';
    }

    private static function statement_type($ofx) {
        if (preg_match('/<CCACCTFROM\b/i', $ofx)) return 'credit_card';
        if (preg_match('/<BANKACCTFROM\b/i', $ofx)) return 'bank';
        throw new RuntimeException('The file contains neither CCACCTFROM nor BANKACCTFROM.');
    }

    private static function account_tag($ofx, $type, $tag) {
        $c = $type === 'credit_card' ? 'CCACCTFROM' : 'BANKACCTFROM';
        if (!preg_match('/<' . $c . '\b[^>]*>(.*?)<\/' . $c . '>/is', $ofx, $m)) return '';
        return self::tag($m[1], $tag);
    }

    private static function version($ofx) {
        $v = self::header($ofx, 'VERSION');
        if ($v !== '') return $v;
        if (preg_match('/<\?OFX\b[^>]*\bVERSION=["\']?([^\s"\'?>]+)/i', $ofx, $m)) return trim($m[1]);
        return '';
    }

    private static function header($ofx, $name) {
        return preg_match('/(?:^|\n)' . preg_quote($name, '/') . ':([^\n]*)/i', $ofx, $m) ? trim($m[1]) : '';
    }

    private static function tag($text, $tag) {
        if (!preg_match('/<' . preg_quote($tag, '/') . '\b[^>]*>\s*([^<]*)/i', $text, $m)) return '';
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function date($v) {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m)) throw new RuntimeException('Invalid OFX date: ' . $v);
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new RuntimeException('Invalid OFX calendar date: ' . $v);
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
}

TO403_Inbox_Tools::instance();
