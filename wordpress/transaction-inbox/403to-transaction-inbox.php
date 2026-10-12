<?php
/**
 * Plugin Name: 403TO Transaction Inbox
 * Description: Unified transaction register for OFX bank and credit-card transactions, supporting evidence (Interac e-Transfers and expense claims), categories, splits, and bulk review.
 * Version: 0.8.0
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) exit;

final class TO403_Transaction_Inbox {
    const V = '0.8.0';
    const CAP = 'review_403to_payments';
    const ACT = 'to403_transaction_inbox_action';
    const NONCE = 'to403_transaction_inbox';
    const AJAX_ACCEPT = 'to403_transaction_inbox_accept';
    const AJAX_ACTION = 'to403_transaction_inbox_ajax';
    const UNIFORM = 'Program income - uniforms';

    /* Matching windows, in days. */
    const INTERAC_WINDOW = 7;          // Interac email date vs deposit date, either side
    const CARD_BEFORE = 10;            // card receipt may be up to 10 days before the Visa posting
    const CLAIM_BEFORE = 120;          // reimbursements/invoices may be paid up to 120 days after the receipt
    const AFTER = 3;                   // tolerance for evidence dated slightly after the bank date
    const MAX_GROUP = 4;               // most claims combined into one payment
    const MAX_POOL = 12;               // most open claims per claimant considered for combinations

    const UNMATCHED = 'NOT EXISTS (SELECT 1 FROM inbox_matches m WHERE m.left_observation_id = o.observation_id OR m.right_observation_id = o.observation_id)';

    private static $i;
    private $pdo;
    private $accountDirection = '';
    private $acctCache = [];
    private $canonCache = null;
    private $candCache = [];

    static function instance() { return self::$i ??= new self(); }

    private function __construct() {
        foreach (['403to_transaction_inbox', '403to_payment_review', '403to_inbox_review'] as $s) add_shortcode($s, [$this, 'render']);
        add_action('admin_post_' . self::ACT, [$this, 'handle']);
        add_action('wp_ajax_' . self::AJAX_ACCEPT, [$this, 'ajaxAccept']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajaxAction']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    static function activate() {
        add_role('403to_treasurer', '403TO Treasurer', ['read' => true, self::CAP => true]);
        foreach (['403to_treasurer', 'administrator'] as $n) { $r = get_role($n); if ($r) $r->add_cap(self::CAP); }
    }

    function assets() {
        if (!is_user_logged_in() || !current_user_can(self::CAP)) return;
        wp_enqueue_style('to403-transaction-inbox', plugins_url('assets/register.css', __FILE__), [], @filemtime(__DIR__ . '/assets/register.css') ?: self::V);
        wp_enqueue_script('to403-transaction-inbox', plugins_url('assets/register.js', __FILE__), [], @filemtime(__DIR__ . '/assets/register.js') ?: self::V, true);
        wp_localize_script('to403-transaction-inbox', 'to403TransactionInbox', ['ajaxUrl' => admin_url('admin-ajax.php'), 'acceptAction' => self::AJAX_ACCEPT, 'actionAction' => self::AJAX_ACTION]);
    }

    private function db() {
        if ($this->pdo) return $this->pdo;
        foreach (['TO403_PAYMENTS_DB_HOST', 'TO403_PAYMENTS_DB_NAME', 'TO403_PAYMENTS_DB_USER', 'TO403_PAYMENTS_DB_PASSWORD'] as $c) {
            if (!defined($c) || constant($c) === '') throw new RuntimeException('Missing wp-config.php constant: ' . $c);
        }
        $this->pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', TO403_PAYMENTS_DB_HOST, TO403_PAYMENTS_DB_NAME), TO403_PAYMENTS_DB_USER, TO403_PAYMENTS_DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        return $this->pdo;
    }

    private function access() {
        if (!is_user_logged_in() || !current_user_can(self::CAP)) wp_die('You do not have permission to review transactions.', '403TO Transaction Register', ['response' => 403]);
    }

    /* ------------------------------------------------------------------ */
    /* Accounts                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Category list. Uses the Wave chart of accounts discovered by Gravity Forms Wave Sync,
     * so names always match Wave exactly. Falls back to a built-in list if nothing is discovered.
     */
    private function accounts($direction = '') {
        $key = $direction ?: 'all';
        if (isset($this->acctCache[$key])) return $this->acctCache[$key];
        $want = $direction === 'expense' ? ['Expenses'] : ($direction === 'income' ? ['Income'] : ['Expenses', 'Income']);
        $a = [];
        if (class_exists('GF_Wave_Settings')) {
            foreach ((array)(GF_Wave_Settings::get()['discovered_accounts'] ?? []) as $acc) {
                if (!empty($acc['is_archived'])) continue;
                if (!in_array($acc['type'] ?? '', $want, true)) continue;
                if (stripos($acc['name'] ?? '', 'Uncategorized') === 0) continue;
                $a[] = (string)$acc['name'];
            }
        }
        if (!$a) $a = $this->fallbackAccounts($direction);
        $a = (array)apply_filters('to403_transaction_inbox_accounts', apply_filters('to403_payment_review_accounts', $a), $direction);
        $a = array_values(array_unique(array_filter(array_map('trim', $a))));
        natcasesort($a);
        return $this->acctCache[$key] = array_values($a);
    }

    private function fallbackAccounts($direction) {
        $expense = ['Admin & operations', 'Bank Service Charges', 'Boating - all except keelboat expenses', 'Direct Program Subsidy', 'facility or hall rental', 'Group activities and events', 'Keelboat related equipment, maintenance and storage', 'Printing and Reproduction', 'Program equipment and misc program expense', 'Rent Expense', 'Storage costs', 'Training and certification', 'Travel Expense - non-section', 'Beavers - Camp (not food)', 'Beavers - Camp food', 'Beavers - Program (not camp)', 'Beavers - Transportation', 'Cubs - Camp (not food)', 'Cubs - Camp food', 'Cubs - Program (not camp)', 'Cubs - Transportation', 'Fundraising Costs', 'National & International Scouting Events', 'Sea Scouts - Camp (not food)', 'Sea Scouts - Camp food', 'Sea Scouts - Program (not camp)', 'Sea Scouts - Transportation', 'Sea Venturers - Camp (not food)', 'Sea Venturers - Camp food', 'Sea Venturers - Program', 'Sea Venturers - Transportation', 'Uniform Items - all sections'];
        $income = ['Fundraising', 'Miscellaneous Income', 'National & International Scouting events', 'Program Income - Beavers', 'Program Income - Cubs', 'Program Income - Sea Scouts', 'Program Income - Sea Venturers', 'Program income - uniforms', 'Program Income - unspecified'];
        return $direction === 'expense' ? $expense : ($direction === 'income' ? $income : array_merge($income, $expense));
    }

    /** Comparison key: case, spacing and dash/colon differences are ignored. */
    private function acctKey($s) {
        $s = str_replace(["\u{2013}", "\u{2014}", ':'], '-', (string)$s);
        $s = strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
        return preg_replace('/\s*-\s*/', ' - ', $s);
    }

    /** Return the exact Wave account name for a loosely written one, or '' if there is none. */
    private function canon($name) {
        if (trim((string)$name) === '') return '';
        if ($this->canonCache === null) {
            $this->canonCache = [];
            foreach ($this->accounts('') as $a) $this->canonCache[$this->acctKey($a)] = $a;
        }
        return $this->canonCache[$this->acctKey($name)] ?? '';
    }

    private function amap() {
        return (array)apply_filters('to403_transaction_inbox_account_map', apply_filters('to403_payment_review_account_map', ['Beavers' => 'Program Income - Beavers', 'Cubs' => 'Program Income - Cubs', 'Sea Scouts' => 'Program Income - Sea Scouts', 'Sea Venturers' => 'Program Income - Sea Venturers', 'Venturers' => 'Program Income - Sea Venturers']));
    }

    /* ------------------------------------------------------------------ */
    /* Small helpers                                                       */
    /* ------------------------------------------------------------------ */

    private function payload($r) { $x = json_decode((string)($r['payload_json'] ?? ''), true); return is_array($x) ? $x : []; }

    private function norm($v) {
        $v = trim((string)$v);
        if (function_exists('iconv')) { $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v); if ($x !== false) $v = $x; }
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower($v))));
    }

    private function cents($v) { return (int)round(abs((float)$v) * 100); }

    private function days($a, $b) { return (strtotime($a) - strtotime($b)) / 86400; }

    private function bankText($b) {
        $p = $this->payload($b);
        return $this->norm(($p['name'] ?? '') . ' ' . ($p['memo'] ?? '') . ' ' . ($b['description'] ?? ''));
    }

    /** True when a meaningful part of $name (e.g. a surname) appears as a whole word in $haystack. */
    private function nameHit($name, $haystack) {
        $stop = ['the', 'and', 'inc', 'ltd', 'from', 'for', 'mrs', 'scouter'];
        $hay = ' ' . $haystack . ' ';
        foreach (explode(' ', $this->norm($name)) as $t) {
            if (strlen($t) < 3 || in_array($t, $stop, true)) continue;
            if (strpos($hay, ' ' . $t . ' ') !== false) return true;
        }
        return false;
    }

    /** card | reimburse | invoice | other, from the claim's payment method. */
    private function claimMethod($p) {
        $m = strtolower((string)($p['payment_method'] ?? ''));
        if (strpos($m, 'credit card') !== false || strpos($m, 'card') !== false) return 'card';
        if (strpos($m, 'reimburs') !== false) return 'reimburse';
        if (strpos($m, 'invoice') !== false) return 'invoice';
        return 'other';
    }

    private function evidenceIds($v) {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)wp_unslash($v))))));
        sort($ids);
        return $ids;
    }

    /* ------------------------------------------------------------------ */
    /* Data access                                                         */
    /* ------------------------------------------------------------------ */

    private function bank($id) {
        $s = $this->db()->prepare("SELECT * FROM inbox_observations WHERE observation_id = ? AND source_system IN ('ofx_operating','ofx_cg_funds','ofx_visa')");
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** All evidence matched to a bank transaction (one or more). */
    private function matchedEvidence($id) {
        $s = $this->db()->prepare('SELECT m.match_id, m.confidence, o.* FROM inbox_matches m JOIN inbox_observations o ON o.observation_id = CASE WHEN m.left_observation_id = ? THEN m.right_observation_id ELSE m.left_observation_id END WHERE m.left_observation_id = ? OR m.right_observation_id = ? ORDER BY o.transaction_date, o.observation_id');
        $s->execute([$id, $id, $id]);
        return $s->fetchAll();
    }

    private function allocations($id) { $s = $this->db()->prepare('SELECT * FROM inbox_allocations WHERE bank_observation_id = ? ORDER BY line_number'); $s->execute([$id]); return $s->fetchAll(); }

    private function review($id) { $s = $this->db()->prepare('SELECT * FROM inbox_reviews WHERE bank_observation_id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

    private function rows() {
        $w = "source_system IN ('ofx_operating','ofx_cg_funds','ofx_visa')"; $a = [];
        $acct = sanitize_key(wp_unslash($_GET['account'] ?? ''));
        if (in_array($acct, ['ofx_operating', 'ofx_cg_funds', 'ofx_visa'], true)) { $w .= ' AND source_system = ?'; $a[] = $acct; }
        $s = $this->db()->prepare("SELECT * FROM inbox_observations WHERE $w ORDER BY transaction_date DESC, observation_id DESC");
        $s->execute($a);
        return $s->fetchAll();
    }

    private function status($b, $a, $r) {
        if (($r['review_status'] ?? '') === 'excluded') return 'excluded';
        if (($r['review_status'] ?? '') === 'exported') return 'exported';
        if (count($a) > 1) return 'split';
        if (count($a) === 1 && $this->cents($a[0]['amount']) === $this->cents($b['amount'])) return 'ready';
        return 'needs-category';
    }

    /* ------------------------------------------------------------------ */
    /* Matching                                                            */
    /* ------------------------------------------------------------------ */

    private function cand($rows, $score, $nameHit, $confidence, $kind) {
        $ids = array_map(function ($r) { return (int)$r['observation_id']; }, $rows);
        sort($ids);
        return ['rs' => $rows, 'r' => $rows[0], 'p' => $this->payload($rows[0]), 'score' => (int)round($score), 'exact_name' => $nameHit, 'confidence' => $confidence, 'ids' => $ids, 'kind' => $kind];
    }

    /** Unmatched evidence that could explain this bank or card transaction, best first. */
    private function candidates($b) {
        if (!$b) return [];
        $id = (int)$b['observation_id'];
        if (isset($this->candCache[$id])) return $this->candCache[$id];
        $amount = (float)$b['amount'];
        $out = [];
        if ($amount > 0 && $b['source_system'] === 'ofx_operating') $out = $this->interacCandidates($b);
        if ($amount < 0) $out = $this->claimCandidates($b);
        usort($out, function ($x, $y) { return $y['score'] <=> $x['score']; });
        return $this->candCache[$id] = array_slice($out, 0, 10);
    }

    private function interacCandidates($b) {
        $q = $this->db()->prepare("SELECT o.* FROM inbox_observations o WHERE o.source_system = 'interac_email' AND o.amount = ? AND o.transaction_date BETWEEN DATE_SUB(?, INTERVAL " . self::INTERAC_WINDOW . " DAY) AND DATE_ADD(?, INTERVAL " . self::INTERAC_WINDOW . " DAY) AND " . self::UNMATCHED . " ORDER BY o.transaction_date, o.observation_id");
        $q->execute([number_format(abs((float)$b['amount']), 2, '.', ''), $b['transaction_date'], $b['transaction_date']]);
        $hay = ' ' . $this->bankText($b) . ' ';
        $out = [];
        foreach ($q->fetchAll() as $r) {
            $rp = $this->payload($r);
            $days = abs($this->days($r['transaction_date'], $b['transaction_date']));
            $score = max(0, 100 - $days * 10);
            $payer = $this->norm($rp['payer_name'] ?? '');
            $exact = $payer !== '' && strpos($hay, ' ' . $payer . ' ') !== false;
            if ($exact) $score += 100;
            $out[] = $this->cand([$r], $score, $exact, $days <= 1 ? 'HIGH' : ($days <= 3 ? 'MEDIUM' : 'LOW'), 'interac');
        }
        return $out;
    }

    /**
     * Expense claims for an outgoing payment.
     * GC Visa: "Charge on 403TO credit card" claims, same amount, receipt up to 10 days before posting.
     * Operating: reimbursement (and invoice) claims, same amount or the exact sum of 2-4 open claims
     *            from the person named in the bank description, receipt up to 120 days before payment.
     * CG Funds: invoice claims, same amount.
     */
    private function claimCandidates($b) {
        $src = $b['source_system'];
        $allowed = $src === 'ofx_visa' ? ['card'] : ($src === 'ofx_cg_funds' ? ['invoice', 'other'] : ['reimburse', 'invoice', 'other']);
        $target = $this->cents($b['amount']);
        $q = $this->db()->prepare("SELECT o.* FROM inbox_observations o WHERE o.source_system = 'gravity_forms' AND o.transaction_date BETWEEN DATE_SUB(?, INTERVAL " . self::CLAIM_BEFORE . " DAY) AND DATE_ADD(?, INTERVAL " . self::AFTER . " DAY) AND " . self::UNMATCHED . " ORDER BY o.transaction_date DESC, o.observation_id DESC");
        $q->execute([$b['transaction_date'], $b['transaction_date']]);
        $hay = $this->bankText($b);
        $out = []; $pool = [];
        foreach ($q->fetchAll() as $r) {
            $p = $this->payload($r);
            $method = $this->claimMethod($p);
            if (!in_array($method, $allowed, true)) continue;
            $before = $this->days($b['transaction_date'], $r['transaction_date']); // days the receipt precedes the payment
            $limit = $method === 'card' ? self::CARD_BEFORE : self::CLAIM_BEFORE;
            if ($before < -self::AFTER || $before > $limit) continue;
            $hit = $this->nameHit($p['submitter_name'] ?? '', $hay);
            $c = $this->cents($r['amount']);
            if ($c === $target) {
                $score = $method === 'card' ? 100 - abs($before) * 8 : 70 - min(60, abs($before) / 2);
                if ($hit) $score += 100;
                $conf = ($hit || ($method === 'card' && abs($before) <= 3)) ? 'HIGH' : (($method === 'card' || abs($before) <= 30) ? 'MEDIUM' : 'LOW');
                $out[] = $this->cand([$r], max(1, $score), $hit, $conf, 'claim');
            } elseif ($hit && $method !== 'card' && $c > 0 && $c < $target) {
                $pool[$this->norm($p['submitter_name'] ?? '')][] = ['r' => $r, 'c' => $c];
            }
        }
        foreach ($pool as $items) {
            foreach ($this->subsets(array_slice($items, 0, self::MAX_POOL), $target) as $set) {
                $rows = array_column($set, 'r');
                usort($rows, function ($x, $y) { return strcmp($x['transaction_date'], $y['transaction_date']); });
                $out[] = $this->cand($rows, 190 - count($rows), true, 'HIGH', 'claims');
            }
        }
        return $out;
    }

    /** Combinations of 2..MAX_GROUP items whose cents add up exactly to $target (at most 5). */
    private function subsets($items, $target) {
        $found = []; $n = count($items);
        $walk = function ($start, $sum, $chosen) use (&$walk, &$found, $items, $n, $target) {
            if (count($found) >= 5) return;
            if ($sum === $target && count($chosen) >= 2) { $found[] = $chosen; return; }
            if (count($chosen) >= self::MAX_GROUP || $sum >= $target) return;
            for ($i = $start; $i < $n; $i++) $walk($i + 1, $sum + $items[$i]['c'], array_merge($chosen, [$items[$i]]));
        };
        $walk(0, 0, []);
        return $found;
    }

    /** Category suggestion from matched or candidate evidence, or from the bank text. */
    private function suggest($b, $evs = []) {
        $evs = array_values(array_filter((array)$evs));
        $claimCats = [];
        foreach ($evs as $e) {
            if (($e['source_system'] ?? '') !== 'gravity_forms') continue;
            $ep = $this->payload($e);
            $claimCats[$this->canon($ep['export_account'] ?? ($ep['submitted_category'] ?? ''))] = true;
        }
        if ($claimCats) return count($claimCats) === 1 ? (string)array_key_first($claimCats) : '';
        $e = $evs[0] ?? null;
        $bp = $this->payload($b); $ep = $e ? $this->payload($e) : [];
        $t = $this->norm(($bp['memo'] ?? '') . ' ' . $b['description'] . ' ' . ($e['description'] ?? '') . ' ' . ($ep['payment_message'] ?? '') . ' ' . ($ep['section'] ?? '') . ' ' . ($ep['recipient'] ?? '') . ' ' . ($ep['recipient_email'] ?? '') . ' ' . ($ep['to_email'] ?? '') . ' ' . ($ep['mailbox'] ?? ''));
        foreach (['hat', 'hats', 'shirt', 'shirts', 'uniform', 'uniforms', 'necker', 'neckers', 'scarf', 'scarves'] as $k) {
            if (preg_match('/(^| )' . preg_quote($k, '/') . '( |$)/', $t)) return $this->canon(self::UNIFORM);
        }
        $section = $this->norm($ep['section'] ?? '');
        foreach ($this->amap() as $label => $account) if ($section === $this->norm($label)) return $this->canon($account);
        foreach (['Beavers' => 'beaver', 'Cubs' => 'cub', 'Sea Scouts' => 'sea scout', 'Sea Venturers' => 'sea venturer', 'Venturers' => 'venturer'] as $label => $needle) {
            if (strpos(' ' . $t . ' ', ' ' . $needle . ' ') !== false || strpos($t, $needle . 's') !== false) return $this->canon($this->amap()[$label] ?? '');
        }
        return '';
    }

    /* ------------------------------------------------------------------ */
    /* Writes                                                              */
    /* ------------------------------------------------------------------ */

    private function hidden($op) {
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="' . self::ACT . '"><input type="hidden" name="operation" value="' . esc_attr($op) . '"><input type="hidden" name="return_url" value="' . esc_url(remove_query_arg(['to403_notice', 'to403_message'])) . '">';
    }

    private function redirect($t, $m) {
        $u = esc_url_raw(wp_unslash($_POST['return_url'] ?? home_url('/')));
        wp_safe_redirect(add_query_arg(['to403_notice' => $t, 'to403_message' => rawurlencode($m)], $u));
        exit;
    }

    function handle() {
        $this->access();
        check_admin_referer(self::NONCE);
        try {
            $op = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
            if ($op === 'save-category') { $this->saveCategory((int)$_POST['bank_id'], sanitize_text_field(wp_unslash($_POST['category'] ?? ''))); $this->redirect('success', 'Category saved.'); }
            if ($op === 'save-split') { $this->saveSplit((int)$_POST['bank_id']); $this->redirect('success', 'Split saved.'); }
            if ($op === 'accept') { $this->accept((int)$_POST['bank_id'], $this->evidenceIds($_POST['evidence_id'] ?? '')); $this->redirect('success', 'Match accepted.'); }
            if ($op === 'bulk') $this->bulk();
            throw new RuntimeException('Unknown action.');
        } catch (Throwable $e) { $this->redirect('error', $e->getMessage()); }
    }

    function ajaxAccept() {
        try {
            $this->access();
            check_ajax_referer(self::NONCE, 'nonce');
            $id = (int)($_POST['bank_id'] ?? 0);
            $eids = $this->evidenceIds($_POST['evidence_id'] ?? '');
            $this->accept($id, $eids);
            $labels = array_map([$this, 'evidencePlain'], $this->matchedEvidence($id));
            $b = $this->bank($id); $a = $this->allocations($id); $st = $this->status($b, $a, $this->review($id));
            wp_send_json_success([
                'bank_id' => $id, 'evidence_id' => implode(',', $eids), 'label' => implode(' + ', $labels), 'payer' => implode(' + ', $labels),
                'description' => '', 'section' => '', 'status' => $st, 'ready' => in_array($st, ['ready', 'split'], true),
                'split' => count($a) > 1, 'category' => count($a) === 1 ? $a[0]['export_account'] : '',
                'message' => 'Match accepted.' . (count($a) > 1 ? ' Split created from the claim categories.' : (count($a) === 1 ? ' Category set from the claim.' : '')),
            ]);
        } catch (Throwable $e) { wp_send_json_error(['message' => $e->getMessage()], 400); }
    }

    function ajaxAction() {
        try {
            $this->access();
            check_ajax_referer(self::NONCE, 'nonce');
            $op = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
            if ($op === 'save-category') {
                $id = (int)($_POST['bank_id'] ?? 0); $cat = sanitize_text_field(wp_unslash($_POST['category'] ?? ''));
                $this->saveCategory($id, $cat);
                wp_send_json_success(['operation' => $op, 'bank_id' => $id, 'category' => $this->canon($cat), 'status' => 'ready', 'message' => 'Category saved.']);
            }
            if ($op === 'save-split') {
                $id = (int)($_POST['bank_id'] ?? 0); $this->saveSplit($id); $a = $this->allocations($id);
                wp_send_json_success(['operation' => $op, 'bank_id' => $id, 'line_count' => count($a), 'status' => 'ready', 'message' => 'Split saved.']);
            }
            if ($op === 'bulk') {
                $result = $this->bulkResult();
                wp_send_json_success(array_merge(['operation' => $op, 'message' => sprintf('Accepted matches: %d. Saved categories: %d. Skipped: %d. Errors: %d.', $result['matched'], $result['categorized'], $result['skipped'], $result['errors'])], $result));
            }
            throw new RuntimeException('Unknown Ajax action.');
        } catch (Throwable $e) { wp_send_json_error(['message' => $e->getMessage()], 400); }
    }

    private function saveLines($id, $lines) {
        $b = $this->bank($id);
        if (!$b) throw new RuntimeException('Transaction not found.');
        $sum = array_sum(array_column($lines, 1));
        if (!$lines || $sum !== $this->cents($b['amount'])) throw new RuntimeException('Category amount or split total must equal the transaction amount.');
        $d = $this->db();
        $d->beginTransaction();
        try {
            $d->prepare('DELETE FROM inbox_allocations WHERE bank_observation_id = ?')->execute([$id]);
            $q = $d->prepare('INSERT INTO inbox_allocations (bank_observation_id, line_number, export_account, amount, allocation_note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($lines as $i => $l) $q->execute([$id, $i + 1, $l[0], $l[1] / 100, $l[2] ?: null, get_current_user_id()]);
            $d->prepare("INSERT INTO inbox_reviews (bank_observation_id, review_status, reviewed_by) VALUES (?, 'ready', ?) ON DUPLICATE KEY UPDATE review_status = 'ready', reviewed_by = VALUES(reviewed_by), updated_at = CURRENT_TIMESTAMP")->execute([$id, get_current_user_id()]);
            $d->commit();
        } catch (Throwable $e) { if ($d->inTransaction()) $d->rollBack(); throw $e; }
    }

    private function saveCategory($id, $cat) {
        $c = $this->canon($cat);
        if ($c === '') throw new RuntimeException('Choose a valid category.');
        $b = $this->bank($id);
        if (!$b) throw new RuntimeException('Transaction not found.');
        $this->saveLines($id, [[$c, $this->cents($b['amount']), '']]);
    }

    private function saveSplit($id) {
        $aa = (array)($_POST['split_account'] ?? []); $vv = (array)($_POST['split_amount'] ?? []); $nn = (array)($_POST['split_note'] ?? []);
        $l = [];
        foreach ($aa as $i => $a) {
            $a = sanitize_text_field(wp_unslash($a)); $v = sanitize_text_field(wp_unslash($vv[$i] ?? '')); $n = sanitize_text_field(wp_unslash($nn[$i] ?? ''));
            if ($a === '' && $v === '' && $n === '') continue;
            $c = $this->canon($a);
            if ($c === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $v) || (float)$v <= 0) throw new RuntimeException('Complete every split line.');
            $l[] = [$c, (int)round((float)$v * 100), $n];
        }
        $this->saveLines($id, $l);
    }

    /**
     * Accept one candidate: a single piece of evidence or a group of claims.
     * $eids must be exactly the ids of a current candidate for this transaction.
     */
    private function accept($id, $eids) {
        $eids = array_values(array_unique(array_map('intval', (array)$eids)));
        sort($eids);
        if (!$eids) throw new RuntimeException('Choose evidence to accept.');
        $b = $this->bank($id);
        if (!$b) throw new RuntimeException('Transaction not found.');
        $valid = null;
        foreach ($this->candidates($b) as $c) if ($c['ids'] === $eids) { $valid = $c; break; }
        if (!$valid) throw new RuntimeException('That evidence is no longer a candidate for this transaction. Refresh the page.');
        $d = $this->db();
        $all = array_merge([$id], $eids);
        $ph = implode(',', array_fill(0, count($all), '?'));
        $d->beginTransaction();
        try {
            $q = $d->prepare("SELECT match_id FROM inbox_matches WHERE left_observation_id IN ($ph) OR right_observation_id IN ($ph) LIMIT 1 FOR UPDATE");
            $q->execute(array_merge($all, $all));
            if ($q->fetch()) throw new RuntimeException('This transaction or its evidence is already matched. Refresh the page.');
            $ins = $d->prepare('INSERT INTO inbox_matches (left_observation_id, right_observation_id, confidence) VALUES (?, ?, ?)');
            foreach ($eids as $e) { $x = [$id, $e]; sort($x); $ins->execute([$x[0], $x[1], $valid['confidence']]); }
            $d->commit();
        } catch (Throwable $e) { if ($d->inTransaction()) $d->rollBack(); throw $e; }
        $this->candCache = [];
        $this->allocateFromClaims($id, $valid['rs']);
    }

    /** When claims are matched and nothing is categorized yet, copy their categories (one line per claim). */
    private function allocateFromClaims($id, $rows) {
        foreach ($rows as $r) if (($r['source_system'] ?? '') !== 'gravity_forms') return;
        if ($this->allocations($id)) return;
        $lines = [];
        foreach ($rows as $r) {
            $p = $this->payload($r);
            $cat = $this->canon($p['export_account'] ?? ($p['submitted_category'] ?? ''));
            if ($cat === '') return; // an old or invalid category: leave it for the reviewer
            $lines[] = [$cat, $this->cents($r['amount']), 'Claim #' . ($r['gf_entry_id'] ?: ($p['entry_id'] ?? ''))];
        }
        if (count($lines) === 1) $lines[0][2] = '';
        try { $this->saveLines($id, $lines); } catch (Throwable $e) { /* leave uncategorized */ }
    }

    private function bulkResult() {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected'] ?? [])))));
        if (!$ids) throw new RuntimeException('Select at least one transaction.');
        $shown = (array)($_POST['displayed_category'] ?? []);
        $matched = $categorized = $skip = $errors = 0; $processed = [];
        foreach ($ids as $id) {
            $changed = false;
            try {
                $b = $this->bank($id);
                if (!$b) throw new RuntimeException('Transaction not found.');
                if (!$this->matchedEvidence($id)) {
                    $c = $this->candidates($b);
                    if ($c) { $this->accept($id, $c[0]['ids']); $matched++; $changed = true; }
                }
                $cat = sanitize_text_field(wp_unslash($shown[$id] ?? ''));
                $a = $this->allocations($id);
                if ($cat === '') $cat = count($a) === 1 ? ($a[0]['export_account'] ?? '') : (count($a) > 1 ? '' : $this->suggest($b, $this->matchedEvidence($id)));
                if (count($a) <= 1 && $this->canon($cat) !== '') { $this->saveCategory($id, $cat); $categorized++; $changed = true; }
                if (!$changed) $skip++;
                $processed[] = ['bank_id' => $id, 'changed' => $changed, 'category' => $this->canon($cat)];
            } catch (Throwable $e) { $errors++; $processed[] = ['bank_id' => $id, 'changed' => false, 'error' => $e->getMessage()]; }
        }
        return ['matched' => $matched, 'categorized' => $categorized, 'skipped' => $skip, 'errors' => $errors, 'processed' => $processed];
    }

    private function bulk() {
        $r = $this->bulkResult();
        $this->redirect($r['errors'] ? 'error' : 'success', sprintf('Accepted matches: %d. Saved categories: %d. Skipped: %d. Errors: %d.', $r['matched'], $r['categorized'], $r['skipped'], $r['errors']));
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    function render() {
        try {
            $this->access();
            $view = isset($_GET['to403_view']) ? sanitize_key(wp_unslash($_GET['to403_view'])) : 'transactions';
            if ($view === 'claims') return $this->renderClaims();
            $rows = $this->rows();
            ob_start();
            echo '<div class="to403-register"><h2>403TO Transaction Inbox</h2>';
            $this->tabs('transactions');
            echo '<p class="help">Choose a category in the register. Use Split only when one bank transaction needs multiple category lines. Accepting expense claims fills in their categories automatically.</p>';
            $this->notice();
            $this->filters();
            echo '<div class="scroll"><table><thead><tr><th><input type="checkbox" class="all"></th><th>Date</th><th>Account</th><th class="money">Amount</th><th>Description / evidence</th><th>Category</th><th>Status</th><th></th></tr></thead><tbody>';
            foreach ($rows as $b) $this->row($b);
            echo '</tbody></table></div>';
            foreach ($rows as $b) $this->renderModal($b);
            echo '<form id="to403-bulk-form" class="bulk" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            $this->hidden('bulk');
            echo '<button type="submit" class="primary accept-selected">Accept selected</button><span class="bulk-help">Accepts each displayed evidence match and saves each displayed category.</span></form></div>';
            return ob_get_clean();
        } catch (Throwable $e) { return '<div class="msg error">' . esc_html($e->getMessage()) . '</div>'; }
    }

    private function filters() {
        $cur = $_GET['account'] ?? '';
        echo '<form method="get" class="filters"><label>Account <select name="account"><option value="">All accounts</option>';
        foreach (['ofx_operating' => 'Operating', 'ofx_cg_funds' => 'CG Funds', 'ofx_visa' => 'GC Visa'] as $k => $v) echo '<option value="' . $k . '"' . selected($cur, $k, false) . '>' . $v . '</option>';
        echo '</select></label><button>Filter</button></form>';
    }

    private function account_label($source) { return ['ofx_operating' => 'Operating', 'ofx_cg_funds' => 'CG Funds', 'ofx_visa' => 'GC Visa'][$source] ?? $source; }

    private function receipt_links($p) {
        $urls = $p['receipt_urls'] ?? [];
        if (!is_array($urls)) $urls = [$urls];
        $html = '';
        foreach ($urls as $u) { $u = esc_url($u); if ($u !== '') $html .= ' · <a href="' . $u . '" target="_blank" rel="noopener noreferrer">Receipt</a>'; }
        return $html;
    }

    /** One line describing a piece of evidence (escaped HTML). */
    private function evidenceHtml($e) {
        $p = $this->payload($e);
        if (($e['source_system'] ?? '') === 'gravity_forms') {
            $bits = ['Claim #' . ($e['gf_entry_id'] ?: ($p['entry_id'] ?? '')), $p['submitter_name'] ?? '', $p['payee'] ?? '', $p['description'] ?? '', '$' . number_format(abs((float)$e['amount']), 2), $e['transaction_date'] ?? '', $p['submitted_category'] ?? ''];
            return implode(' · ', array_map('esc_html', array_filter(array_map('strval', $bits), 'strlen'))) . $this->receipt_links($p);
        }
        $bits = ['Interac', $p['payer_name'] ?? '', $e['description'] ?? '', $p['section'] ?? '', $e['transaction_date'] ?? ''];
        return implode(' · ', array_map('esc_html', array_filter(array_map('strval', $bits), 'strlen')));
    }

    private function evidencePlain($e) { return html_entity_decode(wp_strip_all_tags($this->evidenceHtml($e)), ENT_QUOTES, 'UTF-8'); }

    private function candidateLabel($c) {
        if ($c['kind'] === 'claims') return count($c['rs']) . ' CLAIMS · NAME MATCH';
        if ($c['exact_name']) return $c['kind'] === 'interac' ? 'EXACT PAYER NAME' : 'NAME MATCH';
        return $c['confidence'] . ' ' . (int)$c['score'];
    }

    private function candidateRow($id, $c, $class) {
        echo '<tr class="evidence suggested cand-' . $id . ' ' . esc_attr($class) . '"' . (strpos($class, 'alternative') !== false ? ' hidden' : '') . '><td></td><td colspan="7">└─ <b>' . esc_html($this->candidateLabel($c)) . '</b> · ';
        echo implode('<br>&nbsp;&nbsp;&nbsp;+ ', array_map([$this, 'evidenceHtml'], $c['rs']));
        echo ' <form class="inline ajax-accept" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $this->hidden('accept');
        echo '<input type="hidden" name="bank_id" value="' . $id . '"><input type="hidden" name="evidence_id" value="' . esc_attr(implode(',', $c['ids'])) . '"><button type="submit" class="accept-match">Accept</button></form>';
    }

    private function row($b) {
        $this->accountDirection = (float)$b['amount'] < 0 ? 'expense' : 'income';
        $id = (int)$b['observation_id'];
        $evs = $this->matchedEvidence($id);
        $c = $evs ? [] : $this->candidates($b);
        $a = $this->allocations($id); $r = $this->review($id); $st = $this->status($b, $a, $r); $p = $this->payload($b);
        $cat = count($a) === 1 ? $a[0]['export_account'] : $this->suggest($b, $evs ?: ($c[0]['rs'] ?? []));
        echo '<tr class="bank ' . $st . '" data-bank-id="' . $id . '"><td><input class="check" type="checkbox" name="selected[]" form="to403-bulk-form" value="' . $id . '"></td><td>' . esc_html($b['transaction_date']) . '</td><td>' . esc_html($this->account_label($b['source_system'])) . '</td><td class="money">' . ((float)$b['amount'] >= 0 ? '+' : '−') . '$' . number_format(abs((float)$b['amount']), 2) . '</td><td><strong>' . esc_html($p['memo'] ?? $b['description']) . '</strong><br><small>' . esc_html($p['name'] ?? $b['description']) . ' · FITID ' . esc_html($p['fitid'] ?? '') . '</small></td><td><form class="category" data-bank-id="' . $id . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $this->hidden('save-category');
        echo '<input type="hidden" name="bank_id" value="' . $id . '"><select name="category"><option value="">Choose category</option>';
        $this->options($cat);
        echo '</select><button type="submit" class="category-save">Save</button></form></td><td><span class="pill">' . esc_html(strtoupper(str_replace('-', ' ', $st))) . '</span></td><td><button type="button" class="split-open" data-id="split-' . $id . '">' . (count($a) > 1 ? 'Edit split' : 'Split') . '</button></td></tr>';
        foreach ($evs as $e) echo '<tr class="evidence matched"><td></td><td colspan="7">└─ <b>MATCHED</b> · ' . $this->evidenceHtml($e) . '</td></tr>';
        if (!$evs && $c) {
            $alts = array_slice($c, 1);
            $this->candidateRow($id, $c[0], 'best');
            if ($alts) echo ' <button type="button" class="candidates-toggle" aria-expanded="false" data-target="alternative-' . $id . '">Show alternatives (' . count($alts) . ')</button>';
            echo '</td></tr>';
            foreach ($alts as $x) { $this->candidateRow($id, $x, 'alternative alternative-' . $id); echo '</td></tr>'; }
        }
    }

    private function tabs($active) {
        $base = remove_query_arg('to403_view');
        echo '<nav class="to403-tabs" aria-label="Transaction Inbox views"><a class="' . ($active === 'transactions' ? 'active' : '') . '" href="' . esc_url($base) . '">Transactions</a><a class="' . ($active === 'claims' ? 'active' : '') . '" href="' . esc_url(add_query_arg('to403_view', 'claims', $base)) . '">Expense Claims</a></nav>';
    }

    private function claims() {
        return $this->db()->query("SELECT o.*, CASE WHEN " . str_replace('NOT EXISTS', 'EXISTS', self::UNMATCHED) . " THEN 1 ELSE 0 END AS is_matched FROM inbox_observations o WHERE o.source_system = 'gravity_forms' ORDER BY o.transaction_date DESC, o.observation_id DESC")->fetchAll();
    }

    private function renderClaims() {
        $rows = $this->claims();
        ob_start();
        echo '<div class="to403-register"><h2>403TO Transaction Inbox</h2>';
        $this->tabs('claims');
        echo '<p class="help">Expense claims are evidence submissions and remain visible whether or not they are matched to a bank or credit-card transaction.</p><div class="scroll"><table class="claims"><thead><tr><th>Date</th><th>GF Entry #</th><th>Claimant</th><th>Payee</th><th>Description</th><th>Payment Method</th><th class="money">Amount</th><th>Submitted Category</th><th>Receipt Link</th><th>Matched</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $p = $this->payload($r);
            $receipts = array_values(array_filter((array)($p['receipt_urls'] ?? [])));
            $cat = (string)($p['submitted_category'] ?? '');
            $valid = $cat === '' || $this->canon($cat) !== '';
            echo '<tr><td>' . esc_html($r['transaction_date']) . '</td><td>#' . esc_html($r['gf_entry_id'] ?: ($p['entry_id'] ?? '')) . '</td><td>' . esc_html($p['submitter_name'] ?? '') . '</td><td>' . esc_html($p['payee'] ?? '') . '</td><td>' . esc_html($p['description'] ?? $r['description']) . '</td><td>' . esc_html($p['payment_method'] ?? '') . '</td><td class="money">$' . number_format(abs((float)$r['amount']), 2) . '</td><td' . ($valid ? '' : ' class="not-in-wave" title="Not a current Wave account. Choose the category when matching."') . '>' . esc_html($cat) . '</td><td>';
            if ($receipts) { foreach ($receipts as $i => $url) { if ($i) echo '<br>'; echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">Receipt ' . ($i + 1) . '</a>'; } } else echo '—';
            echo '</td><td><span class="pill ' . ($r['is_matched'] ? 'matched' : 'unmatched') . '">' . ($r['is_matched'] ? 'MATCHED' : 'UNMATCHED') . '</span></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="10">No Gravity Forms expense claims found.</td></tr>';
        echo '</tbody></table></div></div>';
        return ob_get_clean();
    }

    private function renderModal($b) {
        $this->accountDirection = (float)$b['amount'] < 0 ? 'expense' : 'income';
        $id = (int)$b['observation_id'];
        $evs = $this->matchedEvidence($id);
        $c = $evs ? [] : $this->candidates($b);
        $a = $this->allocations($id);
        $cat = count($a) === 1 ? $a[0]['export_account'] : $this->suggest($b, $evs ?: ($c[0]['rs'] ?? []));
        $this->modal($b, $a, $cat);
    }

    private function options($sel) {
        foreach ($this->accounts($this->accountDirection) as $a) echo '<option value="' . esc_attr($a) . '"' . selected($sel, $a, false) . '>' . esc_html($a) . '</option>';
    }

    private function modal($b, $a, $cat) {
        $id = (int)$b['observation_id'];
        $lines = $a ?: [['export_account' => $cat, 'amount' => number_format(abs((float)$b['amount']), 2, '.', ''), 'allocation_note' => ''], ['export_account' => '', 'amount' => '', 'allocation_note' => '']];
        echo '<div class="modal" id="split-' . $id . '" hidden><div class="backdrop" data-close></div><div class="panel"><form class="split" data-total="' . number_format(abs((float)$b['amount']), 2, '.', '') . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $this->hidden('save-split');
        echo '<input type="hidden" name="bank_id" value="' . $id . '"><header><h3>Split $' . number_format(abs((float)$b['amount']), 2) . '</h3><button type="button" data-close>Close</button></header><div class="lines">';
        foreach ($lines as $l) $this->line($l);
        echo '</div><p class="summary">Allocated $<span class="allocated">0.00</span> · Remaining $<span class="remaining">0.00</span> · <span class="split-state">Check split lines</span></p><footer><button type="button" class="add">Add line</button><button type="submit" class="primary save" disabled>Save split</button></footer></form></div></div>';
    }

    private function line($l) {
        echo '<div class="line"><select name="split_account[]"><option value="">Choose category</option>';
        $this->options($l['export_account'] ?? '');
        echo '</select><input name="split_amount[]" value="' . esc_attr($l['amount'] ?? '') . '" placeholder="0.00"><input name="split_note[]" value="' . esc_attr($l['allocation_note'] ?? '') . '" placeholder="Line note"><button type="button" class="remove">Remove</button></div>';
    }

    private function notice() {
        if (isset($_GET['to403_notice'], $_GET['to403_message'])) echo '<div class="msg ' . esc_attr(sanitize_key(wp_unslash($_GET['to403_notice']))) . '">' . esc_html(sanitize_text_field(rawurldecode(wp_unslash($_GET['to403_message'])))) . '</div>';
    }
}

register_activation_hook(__FILE__, ['TO403_Transaction_Inbox', 'activate']);
TO403_Transaction_Inbox::instance();
