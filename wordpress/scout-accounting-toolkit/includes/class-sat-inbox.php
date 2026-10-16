<?php
/**
 * The inbox: shortcode [sat_inbox].
 *
 * Bank and card transactions (from OFX) with supporting evidence underneath each one:
 * Interac e-Transfer emails for deposits, expense claims for payments, and the other side of
 * transfers between the group's own accounts. Tabs: Transactions, Expense claims,
 * Wave export, Transfers to Wave and Tools.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Inbox
{
    private const ACTION = 'sat_inbox_action';
    private const NONCE = 'sat_inbox';
    private const AJAX_ACCEPT = 'sat_inbox_accept';
    private const AJAX_ACTION = 'sat_inbox_ajax';

    /* Matching windows, in days. */
    private const INTERAC_WINDOW = 7;   // Interac email date vs deposit date, either side
    private const CARD_BEFORE = 10;     // card receipt may be up to 10 days before the card posting
    private const CLAIM_BEFORE = 120;   // reimbursements and invoices may be paid up to 120 days after the receipt
    private const AFTER = 3;            // evidence dated slightly after the bank date
    private const TRANSFER_WINDOW = 5;  // the two sides of a transfer between our own accounts
    private const MAX_GROUP = 4;        // most claims combined into one payment
    private const MAX_POOL = 12;        // most open claims per claimant considered for combinations

    private const UNMATCHED = 'NOT EXISTS (SELECT 1 FROM inbox_matches m WHERE m.left_observation_id = o.observation_id OR m.right_observation_id = o.observation_id)';
    private const OPEN_REVIEW = "NOT EXISTS (SELECT 1 FROM inbox_reviews rv WHERE rv.bank_observation_id = o.observation_id AND rv.review_status IN ('excluded','exported'))";

    private const VIEWS = ['attention' => 'Needs attention', 'ready' => 'Ready for Wave', 'excluded' => 'Excluded', 'all' => 'All'];

    private static ?self $instance = null;

    private string $direction = '';
    private string $rowSource = '';
    private array $accountCache = [];
    private ?array $canonCache = null;
    private array $candidateCache = [];
    private ?array $bankCache = null;
    private ?array $allocMap = null;
    private ?array $reviewMap = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_shortcode('sat_inbox', [$this, 'render']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        add_action('wp_ajax_' . self::AJAX_ACCEPT, [$this, 'ajax_accept']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_action']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        if (!is_user_logged_in() || !current_user_can(SAT_CAP_REVIEW)) {
            return;
        }
        wp_enqueue_style('sat-inbox', SAT_URL . 'assets/inbox.css', [], (string)(@filemtime(SAT_DIR . '/assets/inbox.css') ?: SAT_VERSION));
        wp_enqueue_script('sat-inbox', SAT_URL . 'assets/inbox.js', [], (string)(@filemtime(SAT_DIR . '/assets/inbox.js') ?: SAT_VERSION), true);
        wp_localize_script('sat-inbox', 'satInbox', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'acceptAction' => self::AJAX_ACCEPT,
            'actionAction' => self::AJAX_ACTION,
        ]);
    }

    private function db(): PDO
    {
        return SAT_DB::pdo();
    }

    private function access(): void
    {
        if (!is_user_logged_in() || !current_user_can(SAT_CAP_REVIEW)) {
            wp_die('You do not have permission to review transactions.', 'Scout Accounting', ['response' => 403]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Accounts                                                            */
    /* ------------------------------------------------------------------ */

    /** Our bank and card accounts keyed by source system. Falls back to any OFX sources in the database. */
    private function banks(): array
    {
        if ($this->bankCache !== null) {
            return $this->bankCache;
        }
        $banks = SAT_Settings::banks();
        if (!$banks) {
            $rows = $this->db()->query("SELECT DISTINCT source_system FROM inbox_observations WHERE source_system LIKE 'ofx\\_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $source) {
                $banks[$source] = ['label' => $source, 'type' => 'bank', 'wave' => '', 'acctid' => ''];
            }
        }
        return $this->bankCache = $banks;
    }

    private function bankSources(): array
    {
        return array_keys($this->banks()) ?: ['__none__'];
    }

    private function inBanks(): string
    {
        return implode(',', array_fill(0, count($this->bankSources()), '?'));
    }

    private function isBank(string $source): bool
    {
        return isset($this->banks()[$source]);
    }

    private function accountLabel(string $source): string
    {
        return $this->banks()[$source]['label'] ?? $source;
    }

    /** Wave account used as the category for a transfer to or from each of our own accounts. */
    private function transferNames(): array
    {
        $out = [];
        foreach ($this->banks() as $source => $bank) {
            $out[$source] = $bank['wave'] !== '' ? $bank['wave'] : $bank['label'];
        }
        return $out;
    }

    private function isTransferName(string $name): bool
    {
        $key = $this->accountKey($name);
        foreach ($this->transferNames() as $n) {
            if ($this->accountKey($n) === $key) {
                return true;
            }
        }
        return false;
    }

    /** Category list: active Wave income or expense accounts, plus our own accounts for transfers. */
    private function accounts(string $direction = ''): array
    {
        $key = $direction ?: 'all';
        if (isset($this->accountCache[$key])) {
            return $this->accountCache[$key];
        }
        $want = $direction === 'expense' ? ['Expenses'] : ($direction === 'income' ? ['Income'] : ['Expenses', 'Income']);
        $list = [];
        foreach ((array)SAT_Settings::get('wave')['discovered_accounts'] as $account) {
            if (!empty($account['is_archived']) || !in_array($account['type'] ?? '', $want, true)) {
                continue;
            }
            if (stripos($account['name'] ?? '', 'Uncategorized') === 0) {
                continue;
            }
            $list[] = (string)$account['name'];
        }
        $list = (array)apply_filters('sat_inbox_accounts', $list, $direction);
        $list = array_merge($list, array_values($this->transferNames()));
        $list = array_values(array_unique(array_filter(array_map('trim', $list))));
        natcasesort($list);
        return $this->accountCache[$key] = array_values($list);
    }

    /** Comparison key: case, spacing and dash differences are ignored. */
    private function accountKey(string $s): string
    {
        $s = str_replace(["\u{2013}", "\u{2014}", ':'], '-', $s);
        $s = strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
        return preg_replace('/\s*-\s*/', ' - ', $s);
    }

    /** The exact account name for a loosely written one, or '' if there is none. */
    private function canon(string $name): string
    {
        $name = SAT_Settings::canonical_category($name);
        if (trim($name) === '') {
            return '';
        }
        if ($this->canonCache === null) {
            $this->canonCache = [];
            foreach ($this->accounts('') as $account) {
                $this->canonCache[$this->accountKey($account)] = $account;
            }
        }
        return $this->canonCache[$this->accountKey($name)] ?? '';
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function payload(array $row): array
    {
        $data = json_decode((string)($row['payload_json'] ?? ''), true);
        return is_array($data) ? $data : [];
    }

    private function norm(string $value): string
    {
        $value = trim($value);
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false) {
                $value = $ascii;
            }
        }
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower($value))));
    }

    private function cents($value): int
    {
        return (int)round(abs((float)$value) * 100);
    }

    private function days(string $a, string $b): float
    {
        return (strtotime($a) - strtotime($b)) / 86400;
    }

    private function money($value): string
    {
        return ((float)$value >= 0 ? '+' : '−') . '$' . number_format(abs((float)$value), 2);
    }

    private function bankText(array $bank): string
    {
        $p = $this->payload($bank);
        return $this->norm(($p['name'] ?? '') . ' ' . ($p['memo'] ?? '') . ' ' . ($bank['description'] ?? ''));
    }

    /** True when a meaningful part of $name (for example a surname) appears as a whole word in $haystack. */
    private function nameHit(string $name, string $haystack): bool
    {
        $stop = ['the', 'and', 'inc', 'ltd', 'from', 'for', 'mrs', 'scouter'];
        $hay = ' ' . $haystack . ' ';
        foreach (explode(' ', $this->norm($name)) as $token) {
            if (strlen($token) < 3 || in_array($token, $stop, true)) {
                continue;
            }
            if (strpos($hay, ' ' . $token . ' ') !== false) {
                return true;
            }
        }
        return false;
    }

    /** card | reimburse | invoice | other, from the claim's payment method. */
    private function claimMethod(array $p): string
    {
        $method = strtolower((string)($p['payment_method'] ?? ''));
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

    private function evidenceIds($value): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)wp_unslash($value))))));
        sort($ids);
        return $ids;
    }

    /* ------------------------------------------------------------------ */
    /* Data access                                                         */
    /* ------------------------------------------------------------------ */

    private function bank(int $id): ?array
    {
        $q = $this->db()->prepare('SELECT * FROM inbox_observations WHERE observation_id = ? AND source_system IN (' . $this->inBanks() . ')');
        $q->execute(array_merge([$id], $this->bankSources()));
        return $q->fetch() ?: null;
    }

    /** All evidence matched to a bank transaction (for a transfer, the other side). */
    private function matchedEvidence(int $id): array
    {
        $q = $this->db()->prepare(
            'SELECT m.match_id, m.confidence, o.*
             FROM inbox_matches m
             JOIN inbox_observations o ON o.observation_id = CASE WHEN m.left_observation_id = ? THEN m.right_observation_id ELSE m.left_observation_id END
             WHERE m.left_observation_id = ? OR m.right_observation_id = ?
             ORDER BY o.transaction_date, o.observation_id'
        );
        $q->execute([$id, $id, $id]);
        return $q->fetchAll();
    }

    private function allocations(int $id): array
    {
        if ($this->allocMap !== null && array_key_exists($id, $this->allocMap)) {
            return $this->allocMap[$id];
        }
        $q = $this->db()->prepare('SELECT * FROM inbox_allocations WHERE bank_observation_id = ? ORDER BY line_number');
        $q->execute([$id]);
        return $q->fetchAll();
    }

    private function review(int $id): ?array
    {
        if ($this->reviewMap !== null && array_key_exists($id, $this->reviewMap)) {
            return $this->reviewMap[$id];
        }
        $q = $this->db()->prepare('SELECT * FROM inbox_reviews WHERE bank_observation_id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** Load allocations and reviews for a whole page at once. */
    private function loadMeta(array $ids): void
    {
        $this->allocMap = array_fill_keys($ids, []);
        $this->reviewMap = array_fill_keys($ids, null);
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db()->prepare("SELECT * FROM inbox_allocations WHERE bank_observation_id IN ($in) ORDER BY bank_observation_id, line_number");
            $q->execute($chunk);
            foreach ($q->fetchAll() as $row) {
                $this->allocMap[(int)$row['bank_observation_id']][] = $row;
            }
            $q = $this->db()->prepare("SELECT * FROM inbox_reviews WHERE bank_observation_id IN ($in)");
            $q->execute($chunk);
            foreach ($q->fetchAll() as $row) {
                $this->reviewMap[(int)$row['bank_observation_id']] = $row;
            }
        }
    }

    private function filterArgs(): array
    {
        $status = sanitize_key(wp_unslash($_GET['sat_status'] ?? 'attention'));
        $account = sanitize_key(wp_unslash($_GET['account'] ?? ''));
        $dir = sanitize_key(wp_unslash($_GET['dir'] ?? ''));
        $month = sanitize_text_field(wp_unslash($_GET['month'] ?? ''));
        return [
            'status' => isset(self::VIEWS[$status]) ? $status : 'attention',
            'account' => $this->isBank($account) ? $account : '',
            'dir' => in_array($dir, ['income', 'expense'], true) ? $dir : '',
            'month' => preg_match('/^\d{4}-\d{2}$/', $month) ? $month : '',
        ];
    }

    private function rows(array $f): array
    {
        $where = 'source_system IN (' . $this->inBanks() . ')';
        $args = $this->bankSources();
        if ($f['account']) {
            $where .= ' AND source_system = ?';
            $args[] = $f['account'];
        }
        if ($f['dir'] === 'income') {
            $where .= ' AND amount > 0';
        }
        if ($f['dir'] === 'expense') {
            $where .= ' AND amount < 0';
        }
        if ($f['month']) {
            $where .= " AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
            $args[] = $f['month'];
        }
        $q = $this->db()->prepare("SELECT * FROM inbox_observations WHERE $where ORDER BY transaction_date DESC, observation_id DESC");
        $q->execute($args);
        return $q->fetchAll();
    }

    private function months(): array
    {
        $q = $this->db()->prepare("SELECT DISTINCT DATE_FORMAT(transaction_date, '%Y-%m') m FROM inbox_observations WHERE source_system IN (" . $this->inBanks() . ') ORDER BY m DESC');
        $q->execute($this->bankSources());
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Review state. code: ready (lines add up to the amount) | needs-category | excluded | exported. */
    private function statusOf(array $bank, array $lines, ?array $review): array
    {
        $state = [
            'code' => 'needs-category',
            'split' => count($lines) > 1,
            'transfer' => false,
            'reason' => (string)($review['exclusion_reason'] ?? ''),
        ];
        foreach ($lines as $line) {
            if ($this->isTransferName((string)$line['export_account'])) {
                $state['transfer'] = true;
            }
        }
        $status = $review['review_status'] ?? '';
        if ($status === 'excluded' || $status === 'exported') {
            $state['code'] = $status;
            return $state;
        }
        $sum = 0;
        foreach ($lines as $line) {
            $sum += $this->cents($line['amount']);
        }
        if ($lines && $sum === $this->cents($bank['amount'])) {
            $state['code'] = 'ready';
        }
        return $state;
    }

    private function bucket(array $state): string
    {
        return ['ready' => 'ready', 'excluded' => 'excluded', 'exported' => 'exported'][$state['code']] ?? 'attention';
    }

    private function pillText(array $state): string
    {
        if ($state['code'] === 'ready') {
            return 'READY' . ($state['split'] ? ' · SPLIT' : '') . ($state['transfer'] ? ' · TRANSFER' : '');
        }
        if ($state['code'] === 'excluded' || $state['code'] === 'exported') {
            return strtoupper($state['code']);
        }
        return $state['split'] ? 'SPLIT UNBALANCED' : 'NEEDS CATEGORY';
    }

    /** Fresh state of one transaction after a change (Ajax responses). */
    private function rowState(int $id): array
    {
        $bank = $this->bank($id);
        $lines = $this->allocations($id);
        $state = $this->statusOf($bank, $lines, $this->review($id));
        return [
            'bank_id' => $id,
            'pill' => $this->pillText($state),
            'ready' => $state['code'] === 'ready',
            'split' => count($lines) > 1,
            'category' => count($lines) === 1 ? $lines[0]['export_account'] : '',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Matching                                                            */
    /* ------------------------------------------------------------------ */

    private function candidate(array $rows, float $score, bool $nameHit, string $confidence, string $kind): array
    {
        $ids = array_map(function ($row) {
            return (int)$row['observation_id'];
        }, $rows);
        sort($ids);
        return ['rs' => $rows, 'score' => (int)round($score), 'exact_name' => $nameHit, 'confidence' => $confidence, 'ids' => $ids, 'kind' => $kind];
    }

    /** Unmatched evidence (or the other side of a transfer) for this transaction, best first. */
    private function candidates(?array $bank): array
    {
        if (!$bank) {
            return [];
        }
        $id = (int)$bank['observation_id'];
        if (isset($this->candidateCache[$id])) {
            return $this->candidateCache[$id];
        }
        $type = $this->banks()[$bank['source_system']]['type'] ?? 'bank';
        $out = [];
        if ((float)$bank['amount'] > 0 && $type === 'bank') {
            $out = $this->interacCandidates($bank);
        }
        if ((float)$bank['amount'] < 0) {
            $out = $this->claimCandidates($bank, $type);

            // Internal transfers are presented only from the outgoing side.
            // This keeps the paying account as the base transaction and avoids
            // showing the same transfer again under the receiving account.
            $out = array_merge($out, $this->transferCandidates($bank));
        }
        usort($out, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        return $this->candidateCache[$id] = array_slice($out, 0, 10);
    }

    private function interacCandidates(array $bank): array
    {
        $q = $this->db()->prepare(
            "SELECT o.* FROM inbox_observations o
             WHERE o.source_system = 'interac_email' AND o.amount = ?
               AND o.transaction_date BETWEEN DATE_SUB(?, INTERVAL " . self::INTERAC_WINDOW . " DAY) AND DATE_ADD(?, INTERVAL " . self::INTERAC_WINDOW . ' DAY)
               AND ' . self::UNMATCHED . '
             ORDER BY o.transaction_date, o.observation_id'
        );
        $q->execute([number_format(abs((float)$bank['amount']), 2, '.', ''), $bank['transaction_date'], $bank['transaction_date']]);
        $hay = ' ' . $this->bankText($bank) . ' ';
        $out = [];
        foreach ($q->fetchAll() as $row) {
            $p = $this->payload($row);
            $days = abs($this->days($row['transaction_date'], $bank['transaction_date']));
            $score = max(0, 100 - $days * 10);
            $payer = $this->norm((string)($p['payer_name'] ?? ''));
            $exact = $payer !== '' && strpos($hay, ' ' . $payer . ' ') !== false;
            if ($exact) {
                $score += 100;
            }
            $out[] = $this->candidate([$row], $score, $exact, $days <= 1 ? 'HIGH' : ($days <= 3 ? 'MEDIUM' : 'LOW'), 'interac');
        }
        return $out;
    }

    /** The other side of a transfer: the opposite amount in another of our accounts within a few days. */
    private function transferCandidates(array $bank): array
    {
        // A transfer candidate is anchored to the outgoing transaction. The
        // receiving side remains a canonical inbox row but does not repeat the
        // same candidate underneath it.
        if ((float)$bank['amount'] >= 0) {
            return [];
        }

        $q = $this->db()->prepare(
            'SELECT o.* FROM inbox_observations o
             WHERE o.source_system IN (' . $this->inBanks() . ') AND o.source_system <> ? AND o.amount = ?
               AND o.transaction_date BETWEEN DATE_SUB(?, INTERVAL ' . self::TRANSFER_WINDOW . ' DAY) AND DATE_ADD(?, INTERVAL ' . self::TRANSFER_WINDOW . ' DAY)
               AND ' . self::UNMATCHED . ' AND ' . self::OPEN_REVIEW . '
             ORDER BY o.transaction_date, o.observation_id'
        );
        $q->execute(array_merge($this->bankSources(), [$bank['source_system'], number_format(-(float)$bank['amount'], 2, '.', ''), $bank['transaction_date'], $bank['transaction_date']]));
        $hint = (bool)preg_match('/\b(transfer|tfr|xfer|visa|payment|pymt|credit card)\b/', $this->bankText($bank));
        $out = [];
        foreach ($q->fetchAll() as $row) {
            $days = abs($this->days($row['transaction_date'], $bank['transaction_date']));
            $out[] = $this->candidate([$row], 160 - $days * 10 + ($hint ? 20 : 0), false, $days <= 2 ? 'HIGH' : 'MEDIUM', 'transfer');
        }
        return $out;
    }

    /**
     * Receiving transactions already represented by a visible transfer
     * candidate. They are omitted as loose rows in Needs attention and remain
     * represented inside the transfer candidate directly beneath the outgoing
     * transaction. If an account filter hides the outgoing row, the receiving
     * row is not suppressed.
     */
    private function groupedTransferTargetIds(array $rows): array
    {
        $visibleIds = [];
        foreach ($rows as $row) {
            $visibleIds[(int)$row['observation_id']] = true;
        }

        $grouped = [];
        foreach ($rows as $bank) {
            if ((float)$bank['amount'] >= 0) {
                continue;
            }

            foreach ($this->candidates($bank) as $candidate) {
                if ($candidate['kind'] !== 'transfer') {
                    continue;
                }

                foreach ($candidate['rs'] as $other) {
                    $otherId = (int)$other['observation_id'];
                    if (isset($visibleIds[$otherId])) {
                        $grouped[$otherId] = true;
                    }
                }
            }
        }

        return $grouped;
    }

    /**
     * Expense claims for an outgoing payment.
     * Card accounts: claims paid on the group card, same amount, receipt up to 10 days before posting.
     * Bank accounts: reimbursement, invoice and other claims, same amount or the exact sum of 2–4 open
     * claims from the person named in the bank description, receipt up to 120 days before payment.
     */
    private function claimCandidates(array $bank, string $type): array
    {
        $allowed = $type === 'credit_card' ? ['card'] : ['reimburse', 'invoice', 'other'];
        $target = $this->cents($bank['amount']);
        $q = $this->db()->prepare(
            "SELECT o.* FROM inbox_observations o
             WHERE o.source_system = 'gravity_forms'
               AND o.transaction_date BETWEEN DATE_SUB(?, INTERVAL " . self::CLAIM_BEFORE . ' DAY) AND DATE_ADD(?, INTERVAL ' . self::AFTER . ' DAY)
               AND ' . self::UNMATCHED . '
             ORDER BY o.transaction_date DESC, o.observation_id DESC'
        );
        $q->execute([$bank['transaction_date'], $bank['transaction_date']]);
        $hay = $this->bankText($bank);
        $out = [];
        $pool = [];
        foreach ($q->fetchAll() as $row) {
            $p = $this->payload($row);
            $method = $this->claimMethod($p);
            if (!in_array($method, $allowed, true)) {
                continue;
            }
            $before = $this->days($bank['transaction_date'], $row['transaction_date']);
            $limit = $method === 'card' ? self::CARD_BEFORE : self::CLAIM_BEFORE;
            if ($before < -self::AFTER || $before > $limit) {
                continue;
            }
            $hit = $this->nameHit((string)($p['submitter_name'] ?? ''), $hay);
            $cents = $this->cents($row['amount']);
            if ($cents === $target) {
                $score = $method === 'card' ? 100 - abs($before) * 8 : 70 - min(60, abs($before) / 2);
                if ($hit) {
                    $score += 100;
                }
                $confidence = ($hit || ($method === 'card' && abs($before) <= 3)) ? 'HIGH' : (($method === 'card' || abs($before) <= 30) ? 'MEDIUM' : 'LOW');
                $out[] = $this->candidate([$row], max(1, $score), $hit, $confidence, 'claim');
            } elseif ($hit && $method !== 'card' && $cents > 0 && $cents < $target) {
                $pool[$this->norm((string)($p['submitter_name'] ?? ''))][] = ['r' => $row, 'c' => $cents];
            }
        }
        foreach ($pool as $items) {
            foreach ($this->subsets(array_slice($items, 0, self::MAX_POOL), $target) as $set) {
                $rows = array_column($set, 'r');
                usort($rows, function ($a, $b) {
                    return strcmp($a['transaction_date'], $b['transaction_date']);
                });
                $out[] = $this->candidate($rows, 190 - count($rows), true, 'HIGH', 'claims');
            }
        }
        return $out;
    }

    /** Combinations of 2..MAX_GROUP items whose cents add up exactly to $target (at most 5). */
    private function subsets(array $items, int $target): array
    {
        $found = [];
        $count = count($items);
        $walk = function ($start, $sum, $chosen) use (&$walk, &$found, $items, $count, $target) {
            if (count($found) >= 5) {
                return;
            }
            if ($sum === $target && count($chosen) >= 2) {
                $found[] = $chosen;
                return;
            }
            if (count($chosen) >= self::MAX_GROUP || $sum >= $target) {
                return;
            }
            for ($i = $start; $i < $count; $i++) {
                $walk($i + 1, $sum + $items[$i]['c'], array_merge($chosen, [$items[$i]]));
            }
        };
        $walk(0, 0, []);
        return $found;
    }

    /** Category suggestion from matched or candidate evidence, or from the bank text. */
    private function suggest(array $bank, array $evidence = []): string
    {
        $evidence = array_values(array_filter($evidence));
        foreach ($evidence as $e) {
            if ($this->isBank((string)($e['source_system'] ?? ''))) {
                return $this->canon($this->transferNames()[$e['source_system']] ?? '');
            }
        }
        $claimCategories = [];
        foreach ($evidence as $e) {
            if (($e['source_system'] ?? '') === 'gravity_forms') {
                $p = $this->payload($e);
                $claimCategories[$this->canon((string)($p['export_account'] ?? ($p['submitted_category'] ?? '')))] = true;
            }
        }
        if ($claimCategories) {
            return count($claimCategories) === 1 ? (string)array_key_first($claimCategories) : '';
        }

        $settings = SAT_Settings::get('inbox');
        $e = $evidence[0] ?? null;
        $bp = $this->payload($bank);
        $ep = $e ? $this->payload($e) : [];
        $text = $this->norm(implode(' ', [
            $bp['memo'] ?? '', $bank['description'] ?? '', $e['description'] ?? '',
            $ep['payment_message'] ?? '', $ep['section'] ?? '',
        ]));

        $uniform = (string)$settings['uniform_account'];
        if ($uniform !== '') {
            foreach (array_filter(array_map('trim', explode(',', (string)$settings['uniform_keywords']))) as $word) {
                if (preg_match('/(^| )' . preg_quote($this->norm($word), '/') . '( |$)/', $text)) {
                    return $this->canon($uniform);
                }
            }
        }

        $map = (array)$settings['section_map'];
        $section = $this->norm((string)($ep['section'] ?? ''));
        foreach ($map as $label => $account) {
            if ($section !== '' && $section === $this->norm((string)$label)) {
                return $this->canon((string)$account);
            }
        }
        foreach ($map as $label => $account) {
            $needle = preg_replace('/s$/', '', $this->norm((string)$label));
            if ($needle !== '' && (strpos(' ' . $text . ' ', ' ' . $needle . ' ') !== false || strpos($text, $needle . 's') !== false)) {
                return $this->canon((string)$account);
            }
        }
        return '';
    }

    /* ------------------------------------------------------------------ */
    /* Writes                                                              */
    /* ------------------------------------------------------------------ */

    private function hidden(string $operation): void
    {
        wp_nonce_field(self::NONCE);
        echo '<input type="hidden" name="action" value="' . self::ACTION . '">';
        echo '<input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
        echo '<input type="hidden" name="return_url" value="' . esc_url(remove_query_arg(['sat_notice', 'sat_message'])) . '">';
    }

    private function redirect(string $type, string $message): void
    {
        $url = esc_url_raw(wp_unslash($_POST['return_url'] ?? home_url('/')));
        wp_safe_redirect(add_query_arg(['sat_notice' => $type, 'sat_message' => rawurlencode($message)], $url));
        exit;
    }

    public function handle(): void
    {
        $this->access();
        check_admin_referer(self::NONCE);
        try {
            $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
            $id = (int)($_POST['bank_id'] ?? 0);
            switch ($operation) {
                case 'save-category':
                    $this->saveCategory($id, sanitize_text_field(wp_unslash($_POST['category'] ?? '')));
                    $this->redirect('success', 'Category saved.');
                    break;
                case 'save-split':
                    $this->saveSplit($id);
                    $this->redirect('success', 'Split saved.');
                    break;
                case 'accept':
                    $this->accept($id, $this->evidenceIds($_POST['evidence_id'] ?? ''));
                    $this->redirect('success', 'Match accepted.');
                    break;
                case 'exclude':
                    $released = $this->exclude($id, sanitize_text_field(wp_unslash($_POST['reason'] ?? '')));
                    $this->redirect('success', 'Transaction excluded. It will not be sent to Wave.' . ($released ? ' Its evidence match was released.' : ''));
                    break;
                case 'reopen':
                    $this->reopen($id);
                    $this->redirect('success', 'Transaction reopened.');
                    break;
                case 'bulk':
                    $result = $this->bulkResult();
                    $this->redirect($result['errors'] ? 'error' : 'success', $this->bulkMessage($result));
                    break;
            }
            throw new RuntimeException('Unknown action.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }

    public function ajax_accept(): void
    {
        try {
            $this->access();
            check_ajax_referer(self::NONCE, 'nonce');
            $id = (int)($_POST['bank_id'] ?? 0);
            $this->accept($id, $this->evidenceIds($_POST['evidence_id'] ?? ''));
            $evidence = $this->matchedEvidence($id);
            $state = $this->rowState($id);
            $out = $state + ['label' => implode(' + ', array_map([$this, 'evidencePlain'], $evidence))];
            foreach ($evidence as $e) {
                if ($this->isBank((string)$e['source_system'])) {
                    $pair = $this->rowState((int)$e['observation_id']);
                    $out['pair_id'] = $pair['bank_id'];
                    $out['pair_pill'] = $pair['pill'];
                    $out['pair_ready'] = $pair['ready'];
                    $out['pair_category'] = $pair['category'];
                }
            }
            $out['message'] = 'Match accepted.' . (isset($out['pair_id'])
                ? ' Both sides of the transfer are categorized.'
                : ($state['split'] ? ' Split created from the claim categories.' : ($state['category'] ? ' Category set from the claim.' : '')));
            wp_send_json_success($out);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    public function ajax_action(): void
    {
        try {
            $this->access();
            check_ajax_referer(self::NONCE, 'nonce');
            $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
            $id = (int)($_POST['bank_id'] ?? 0);
            if ($operation === 'save-category') {
                $this->saveCategory($id, sanitize_text_field(wp_unslash($_POST['category'] ?? '')));
                wp_send_json_success(['message' => 'Category saved.'] + $this->rowState($id));
            }
            if ($operation === 'save-split') {
                $this->saveSplit($id);
                wp_send_json_success(['message' => 'Split saved.'] + $this->rowState($id));
            }
            if ($operation === 'bulk') {
                $result = $this->bulkResult();
                wp_send_json_success(['message' => $this->bulkMessage($result)] + $result);
            }
            throw new RuntimeException('Unknown action.');
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    private function assertEditable(int $id): void
    {
        if (($this->review($id)['review_status'] ?? '') === 'exported') {
            throw new RuntimeException('This transaction has already been exported to Wave.');
        }
        $reserved = $this->db()->prepare(
            "SELECT 1 FROM sat_transfer_ofx_reservations r
             JOIN sat_transfer_ofx_batches b ON b.batch_id = r.batch_id
             WHERE r.observation_id = ? AND b.batch_status = 'generated'
             LIMIT 1"
        );
        $reserved->execute([$id]);
        if ($reserved->fetchColumn()) {
            throw new RuntimeException('This transaction belongs to a generated transfer OFX batch. Cancel that batch before changing it.');
        }
    }

    private function saveLines(int $id, array $lines): void
    {
        $bank = $this->bank($id);
        if (!$bank) {
            throw new RuntimeException('Transaction not found.');
        }
        $this->assertEditable($id);
        if (!$lines || array_sum(array_column($lines, 1)) !== $this->cents($bank['amount'])) {
            throw new RuntimeException('Category amount or split total must equal the transaction amount.');
        }
        $db = $this->db();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT observation_id FROM inbox_observations WHERE observation_id = ? FOR UPDATE');
            $lock->execute([$id]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Transaction not found.');
            }
            $this->assertEditable($id);
            $db->prepare('DELETE FROM inbox_allocations WHERE bank_observation_id = ?')->execute([$id]);
            $insert = $db->prepare('INSERT INTO inbox_allocations (bank_observation_id, line_number, export_account, amount, allocation_note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($lines as $i => $line) {
                $insert->execute([$id, $i + 1, $line[0], $line[1] / 100, $line[2] ?: null, get_current_user_id()]);
            }
            $db->prepare(
                "INSERT INTO inbox_reviews (bank_observation_id, review_status, reviewed_by) VALUES (?, 'ready', ?)
                 ON DUPLICATE KEY UPDATE review_status = 'ready', exclusion_reason = NULL, reviewed_by = VALUES(reviewed_by), updated_at = CURRENT_TIMESTAMP"
            )->execute([$id, get_current_user_id()]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private function saveCategory(int $id, string $category): void
    {
        $name = $this->canon($category);
        if ($name === '') {
            throw new RuntimeException('Choose a valid category.');
        }
        $bank = $this->bank($id);
        if (!$bank) {
            throw new RuntimeException('Transaction not found.');
        }
        $this->saveLines($id, [[$name, $this->cents($bank['amount']), '']]);
    }

    private function saveSplit(int $id): void
    {
        $accounts = (array)($_POST['split_account'] ?? []);
        $amounts = (array)($_POST['split_amount'] ?? []);
        $notes = (array)($_POST['split_note'] ?? []);
        $lines = [];
        foreach ($accounts as $i => $account) {
            $account = sanitize_text_field(wp_unslash($account));
            $amount = sanitize_text_field(wp_unslash($amounts[$i] ?? ''));
            $note = sanitize_text_field(wp_unslash($notes[$i] ?? ''));
            if ($account === '' && $amount === '' && $note === '') {
                continue;
            }
            $name = $this->canon($account);
            if ($name === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float)$amount <= 0) {
                throw new RuntimeException('Complete every split line.');
            }
            $lines[] = [$name, (int)round((float)$amount * 100), $note];
        }
        $this->saveLines($id, $lines);
    }

    /** Exclude from Wave export. Any evidence match is released. Returns the number of matches removed. */
    private function exclude(int $id, string $reason): int
    {
        if (!$this->bank($id)) {
            throw new RuntimeException('Transaction not found.');
        }
        $this->assertEditable($id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give a reason for excluding the transaction.');
        }
        $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 255) : substr($reason, 0, 255);
        $db = $this->db();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT observation_id FROM inbox_observations WHERE observation_id = ? FOR UPDATE');
            $lock->execute([$id]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Transaction not found.');
            }
            $this->assertEditable($id);
            $delete = $db->prepare('DELETE FROM inbox_matches WHERE left_observation_id = ? OR right_observation_id = ?');
            $delete->execute([$id, $id]);
            $db->prepare(
                "INSERT INTO inbox_reviews (bank_observation_id, review_status, exclusion_reason, reviewed_by) VALUES (?, 'excluded', ?, ?)
                 ON DUPLICATE KEY UPDATE review_status = 'excluded', exclusion_reason = VALUES(exclusion_reason), reviewed_by = VALUES(reviewed_by), updated_at = CURRENT_TIMESTAMP"
            )->execute([$id, $reason, get_current_user_id()]);
            $db->commit();
            return $delete->rowCount();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private function reopen(int $id): void
    {
        $q = $this->db()->prepare("UPDATE inbox_reviews SET review_status = 'open', exclusion_reason = NULL, reviewed_by = ?, updated_at = CURRENT_TIMESTAMP WHERE bank_observation_id = ? AND review_status = 'excluded'");
        $q->execute([get_current_user_id(), $id]);
        if (!$q->rowCount()) {
            throw new RuntimeException('That transaction is not excluded.');
        }
    }

    /** Accept one candidate: evidence, a group of claims, or the other side of a transfer. */
    private function accept(int $id, array $evidenceIds): void
    {
        if (!$evidenceIds) {
            throw new RuntimeException('Choose evidence to accept.');
        }
        $bank = $this->bank($id);
        if (!$bank) {
            throw new RuntimeException('Transaction not found.');
        }
        if (in_array($this->review($id)['review_status'] ?? '', ['excluded', 'exported'], true)) {
            throw new RuntimeException('Reopen this transaction before matching it.');
        }
        $valid = null;
        foreach ($this->candidates($bank) as $candidate) {
            if ($candidate['ids'] === $evidenceIds) {
                $valid = $candidate;
                break;
            }
        }
        if (!$valid) {
            throw new RuntimeException('That evidence is no longer a candidate for this transaction. Refresh the page.');
        }
        $all = array_merge([$id], $evidenceIds);
        $in = implode(',', array_fill(0, count($all), '?'));
        $db = $this->db();
        $db->beginTransaction();
        try {
            $lockIds = $all;
            sort($lockIds);
            $locks = $db->prepare("SELECT observation_id FROM inbox_observations WHERE observation_id IN ($in) ORDER BY observation_id FOR UPDATE");
            $locks->execute($lockIds);
            if (count($locks->fetchAll(PDO::FETCH_COLUMN)) !== count($lockIds)) {
                throw new RuntimeException('One or more observations no longer exist. Refresh the page.');
            }
            if ($valid['kind'] === 'transfer') {
                $wave_preview = $db->prepare(
                    "SELECT observation_id FROM sat_wave_export_sources WHERE observation_id IN ($in) LIMIT 1"
                );
                $wave_preview->execute($lockIds);
                if ($wave_preview->fetchColumn()) {
                    throw new RuntimeException('This transfer is already in a Wave API preview. Discard that preview before accepting the transfer.');
                }
            }
            $check = $db->prepare("SELECT match_id FROM inbox_matches WHERE left_observation_id IN ($in) OR right_observation_id IN ($in) LIMIT 1 FOR UPDATE");
            $check->execute(array_merge($all, $all));
            if ($check->fetch()) {
                throw new RuntimeException('This transaction or its evidence is already matched. Refresh the page.');
            }
            $insert = $db->prepare('INSERT INTO inbox_matches (left_observation_id, right_observation_id, confidence) VALUES (?, ?, ?)');
            foreach ($evidenceIds as $evidenceId) {
                $pair = [$id, $evidenceId];
                sort($pair);
                $insert->execute([$pair[0], $pair[1], $valid['confidence']]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $this->candidateCache = [];
        if ($valid['kind'] === 'transfer') {
            $this->allocateTransfer($bank, $valid['rs'][0]);
        } else {
            $this->allocateFromClaims($id, $valid['rs']);
        }
    }

    /** Categorize both sides of a transfer as the other account, unless already categorized. */
    private function allocateTransfer(array $bank, array $other): void
    {
        $names = $this->transferNames();
        $sides = [
            [(int)$bank['observation_id'], $names[$other['source_system']] ?? '', $bank['amount']],
            [(int)$other['observation_id'], $names[$bank['source_system']] ?? '', $other['amount']],
        ];
        foreach ($sides as [$sideId, $name, $amount]) {
            if ($name === '' || $this->allocations($sideId)) {
                continue;
            }
            try {
                $this->saveLines($sideId, [[$name, $this->cents($amount), 'Transfer']]);
            } catch (Throwable $e) {
                // Leave it for the reviewer.
            }
        }
    }

    /** When claims are matched and nothing is categorized yet, copy their categories (one line per claim). */
    private function allocateFromClaims(int $id, array $rows): void
    {
        foreach ($rows as $row) {
            if (($row['source_system'] ?? '') !== 'gravity_forms') {
                return;
            }
        }
        if ($this->allocations($id)) {
            return;
        }
        $lines = [];
        foreach ($rows as $row) {
            $p = $this->payload($row);
            $category = $this->canon((string)($p['export_account'] ?? ($p['submitted_category'] ?? '')));
            if ($category === '') {
                return; // An old or invalid category: leave it for the reviewer.
            }
            $lines[] = [$category, $this->cents($row['amount']), 'Claim #' . ($row['gf_entry_id'] ?: ($p['entry_id'] ?? ''))];
        }
        if (count($lines) === 1) {
            $lines[0][2] = '';
        }
        try {
            $this->saveLines($id, $lines);
        } catch (Throwable $e) {
            // Leave uncategorized.
        }
    }

    private function bulkResult(): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected'] ?? [])))));
        if (!$ids) {
            throw new RuntimeException('Select at least one transaction.');
        }
        $shown = (array)($_POST['displayed_category'] ?? []);
        $result = ['matched' => 0, 'categorized' => 0, 'skipped' => 0, 'errors' => 0, 'processed' => []];
        foreach ($ids as $id) {
            $changed = false;
            try {
                $bank = $this->bank($id);
                if (!$bank) {
                    throw new RuntimeException('Transaction not found.');
                }
                if (in_array($this->review($id)['review_status'] ?? '', ['excluded', 'exported'], true)) {
                    $result['skipped']++;
                    continue;
                }
                if (!$this->matchedEvidence($id)) {
                    $candidates = $this->candidates($bank);
                    if ($candidates) {
                        $this->accept($id, $candidates[0]['ids']);
                        $result['matched']++;
                        $changed = true;
                    }
                }
                $category = sanitize_text_field(wp_unslash($shown[$id] ?? ''));
                $lines = $this->allocations($id);
                if ($category === '') {
                    $category = count($lines) === 1 ? (string)$lines[0]['export_account'] : (count($lines) > 1 ? '' : $this->suggest($bank, $this->matchedEvidence($id)));
                }
                if (count($lines) <= 1 && $this->canon($category) !== '') {
                    $this->saveCategory($id, $category);
                    $result['categorized']++;
                    $changed = true;
                }
                if (!$changed) {
                    $result['skipped']++;
                }
                $result['processed'][] = ['changed' => $changed] + $this->rowState($id);
            } catch (Throwable $e) {
                $result['errors']++;
                $result['processed'][] = ['bank_id' => $id, 'changed' => false, 'error' => $e->getMessage()];
            }
        }
        return $result;
    }

    private function bulkMessage(array $r): string
    {
        return sprintf('Accepted matches: %d. Saved categories: %d. Skipped: %d. Errors: %d.', $r['matched'], $r['categorized'], $r['skipped'], $r['errors']);
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    public function render(): string
    {
        try {
            $this->access();
            $view = sanitize_key(wp_unslash($_GET['sat_view'] ?? 'transactions'));
            ob_start();
            echo '<div class="sat-inbox">';
            echo '<h2>Transaction Inbox</h2>';
            $this->tabs($view);
            if ($view === 'tools' && current_user_can('manage_options')) {
                SAT_Tools::instance()->render();
            } elseif ($view === 'wave') {
                SAT_Wave_Export::instance()->render();
            } elseif ($view === 'transfers') {
                SAT_Transfer_OFX::instance()->render();
            } elseif ($view === 'claims') {
                $this->renderClaims();
            } else {
                $this->renderTransactions();
            }
            echo '</div>';
            return (string)ob_get_clean();
        } catch (Throwable $e) {
            return '<div class="sat-inbox"><div class="msg error">' . esc_html($e->getMessage()) . '</div></div>';
        }
    }

    private function tabs(string $active): void
    {
        $base = remove_query_arg(['sat_view', 'sat_notice', 'sat_message', 'sat_status', 'account', 'dir', 'month']);
        $tabs = ['transactions' => 'Transactions', 'claims' => 'Expense claims', 'wave' => 'Wave export', 'transfers' => 'Transfers to Wave'];
        if (current_user_can('manage_options')) {
            $tabs['tools'] = 'Tools';
        }
        echo '<nav class="sat-tabs" aria-label="Inbox views">';
        foreach ($tabs as $key => $label) {
            $url = $key === 'transactions' ? $base : add_query_arg('sat_view', $key, $base);
            $isActive = $active === $key || ($key === 'transactions' && !isset($tabs[$active]));
            echo '<a class="' . ($isActive ? 'active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function renderTransactions(): void
    {
        $f = $this->filterArgs();
        $all = $this->rows($f);
        $transfer_ids = $f['status'] === 'ready'
            ? SAT_Transfer_OFX::instance()->matched_transfer_observation_ids()
            : [];
        $this->loadMeta(array_map(function ($row) {
            return (int)$row['observation_id'];
        }, $all));
        $counts = array_fill_keys(array_keys(self::VIEWS), 0);
        $rows = [];
        $attentionRows = [];
        foreach ($all as $bank) {
            $id = (int)$bank['observation_id'];
            $bucket = $this->bucket($this->statusOf($bank, $this->allocations($id), $this->review($id)));
            $counts['all']++;
            if ($bucket === 'ready' && isset($transfer_ids[$id])) {
                continue;
            }
            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            }
            if ($bucket === 'attention') {
                $attentionRows[] = $bank;
            }
            if ($f['status'] === 'all' || $f['status'] === $bucket) {
                $rows[] = $bank;
            }
        }

        // In Needs attention, keep an unmatched transfer together as one
        // visual group: outgoing transaction + transfer candidate. The
        // receiving transaction is still canonical and returns as its own row
        // after the transfer is accepted (or when viewing/filtering it alone).
        $groupedTargets = $this->groupedTransferTargetIds($attentionRows);
        $counts['attention'] = max(0, $counts['attention'] - count($groupedTargets));
        if ($f['status'] === 'attention') {
            $rows = array_values(array_filter($rows, function ($row) use ($groupedTargets) {
                return !isset($groupedTargets[(int)$row['observation_id']]);
            }));
        }

        $help = [
            'attention' => 'Transactions that still need a category or a match. When a row is ready it turns green and moves to Ready for Wave on the next refresh.',
            'ready' => 'Fully categorized transactions waiting for Wave export. Change a category or split here to correct one; anything incomplete goes back to Needs attention.',
            'excluded' => 'Transactions that will not be sent to Wave, with the reason given. Reopen one to put it back in Needs attention.',
            'all' => 'Every transaction, including exported and excluded ones.',
        ];
        echo '<div class="view-' . esc_attr($f['status']) . '">';
        echo '<p class="help">' . esc_html($help[$f['status']]) . ' Accepting expense claims or a transfer fills in the categories automatically.</p>';
        $this->notice();
        $this->viewBar($f, $counts);
        $this->filters($f);
        echo '<div class="scroll"><table><thead><tr><th><input type="checkbox" class="all"></th><th>Date</th><th>Account</th><th class="money">Amount</th><th>Description / evidence</th><th>Category</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $bank) {
            $this->row($bank);
        }
        if (!$rows) {
            echo '<tr><td colspan="8" class="empty">Nothing here' . ($f['account'] || $f['dir'] || $f['month'] ? ' with these filters' : '') . '.</td></tr>';
        }
        echo '</tbody></table></div>';
        foreach ($rows as $bank) {
            $this->renderModal($bank);
        }
        if ($f['status'] === 'ready') {
            SAT_Wave_Export::instance()->render_selection_form();
        } else {
            echo '<form id="sat-bulk-form" class="bulk" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            $this->hidden('bulk');
            echo '<button type="submit" class="primary accept-selected">Accept selected</button><span class="bulk-help">Accepts each displayed evidence match and saves each displayed category.</span></form>';
        }
        echo '</div>';
    }

    private function viewBar(array $f, array $counts): void
    {
        $base = remove_query_arg(['sat_notice', 'sat_message']);
        echo '<nav class="sat-views" aria-label="Review status">';
        foreach (self::VIEWS as $key => $label) {
            echo '<a class="' . ($f['status'] === $key ? 'active' : '') . '" href="' . esc_url(add_query_arg('sat_status', $key, $base)) . '">' . esc_html($label) . ' <span class="count">' . (int)$counts[$key] . '</span></a>';
        }
        echo '</nav>';
    }

    private function filters(array $f): void
    {
        echo '<form method="get" class="filters">';
        foreach ($_GET as $key => $value) {
            if (in_array($key, ['account', 'dir', 'month', 'sat_notice', 'sat_message'], true) || !is_scalar($value)) {
                continue;
            }
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(wp_unslash($value)) . '">';
        }
        echo '<label>Account <select name="account"><option value="">All accounts</option>';
        foreach ($this->banks() as $source => $bank) {
            echo '<option value="' . esc_attr($source) . '"' . selected($f['account'], $source, false) . '>' . esc_html($bank['label']) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Direction <select name="dir"><option value="">Income and expenses</option>';
        echo '<option value="income"' . selected($f['dir'], 'income', false) . '>Income only</option>';
        echo '<option value="expense"' . selected($f['dir'], 'expense', false) . '>Expenses only</option></select></label>';
        echo '<label>Month <select name="month"><option value="">All months</option>';
        foreach ($this->months() as $month) {
            echo '<option value="' . esc_attr($month) . '"' . selected($f['month'], $month, false) . '>' . esc_html(date_i18n('F Y', strtotime($month . '-01'))) . '</option>';
        }
        echo '</select></label><button>Filter</button>';
        if ($f['account'] || $f['dir'] || $f['month']) {
            echo ' <a class="clear-filters" href="' . esc_url(remove_query_arg(['account', 'dir', 'month', 'sat_notice', 'sat_message'])) . '">Clear filters</a>';
        }
        echo '</form>';
    }

    private function receiptLinks(array $p): string
    {
        $html = '';
        foreach ((array)($p['receipt_urls'] ?? []) as $url) {
            $url = esc_url((string)$url);
            if ($url !== '') {
                $html .= ' · <a href="' . $url . '" target="_blank" rel="noopener noreferrer">Receipt</a>';
            }
        }
        return $html;
    }

    /** One line describing a piece of evidence (escaped HTML). */
    private function evidenceHtml(array $e): string
    {
        $p = $this->payload($e);
        $source = (string)($e['source_system'] ?? '');
        if ($this->isBank($source)) {
            $bits = ['Transfer', $this->accountLabel($source), $p['memo'] ?? ($e['description'] ?? ''), $this->money($e['amount']), $e['transaction_date'] ?? ''];
        } elseif ($source === 'gravity_forms') {
            $bits = ['Claim #' . ($e['gf_entry_id'] ?: ($p['entry_id'] ?? '')), $p['submitter_name'] ?? '', $p['payee'] ?? '', $p['description'] ?? '', '$' . number_format(abs((float)$e['amount']), 2), $e['transaction_date'] ?? '', $p['submitted_category'] ?? ''];
        } else {
            $bits = ['Interac', $p['payer_name'] ?? '', $e['description'] ?? '', $p['section'] ?? '', $e['transaction_date'] ?? ''];
        }
        $text = implode(' · ', array_map('esc_html', array_filter(array_map('strval', $bits), 'strlen')));
        return $source === 'gravity_forms' ? $text . $this->receiptLinks($p) : $text;
    }

    private function evidencePlain(array $e): string
    {
        return html_entity_decode(wp_strip_all_tags($this->evidenceHtml($e)), ENT_QUOTES, 'UTF-8');
    }

    private function candidateLabel(array $c): string
    {
        if ($c['kind'] === 'transfer') {
            return 'TRANSFER';
        }
        if ($c['kind'] === 'claims') {
            return count($c['rs']) . ' CLAIMS · NAME MATCH';
        }
        if ($c['exact_name']) {
            return $c['kind'] === 'interac' ? 'EXACT PAYER NAME' : 'NAME MATCH';
        }
        return $c['confidence'] . ' ' . (int)$c['score'];
    }

    private function candidateRow(int $id, array $c, string $class): void
    {
        $hidden = strpos($class, 'alternative') !== false ? ' hidden' : '';
        echo '<tr class="evidence suggested cand-' . $id . ' ' . esc_attr($class) . '"' . $hidden . '><td></td><td colspan="7">└─ <b>' . esc_html($this->candidateLabel($c)) . '</b> · ';

        if ($c['kind'] === 'transfer') {
            $bank = $this->bank($id);
            $other = $c['rs'][0] ?? null;

            if ($bank && $other) {
                // Transfer candidates are anchored to the negative (outgoing)
                // row, so the visual direction is always payer → recipient.
                $direction = $this->accountLabel((string)$bank['source_system'])
                    . ' → '
                    . $this->accountLabel((string)$other['source_system']);

                $otherPayload = $this->payload($other);
                $otherDescription = (string)(
                    $otherPayload['memo']
                    ?? $otherPayload['name']
                    ?? $other['description']
                    ?? ''
                );

                echo esc_html($direction);
                if ($otherDescription !== '') {
                    echo ' · ' . esc_html($otherDescription);
                }
                echo ' · ' . esc_html((string)($other['transaction_date'] ?? ''));
            }
        } else {
            echo implode('<br>&nbsp;&nbsp;&nbsp;+ ', array_map([$this, 'evidenceHtml'], $c['rs']));
        }

        echo ' <form class="inline ajax-accept" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $this->hidden('accept');
        echo '<input type="hidden" name="bank_id" value="' . $id . '"><input type="hidden" name="evidence_id" value="' . esc_attr(implode(',', $c['ids'])) . '"><button type="submit" class="accept-match">Accept</button></form>';
    }

    private function row(array $bank): void
    {
        $this->direction = (float)$bank['amount'] < 0 ? 'expense' : 'income';
        $this->rowSource = (string)$bank['source_system'];
        $id = (int)$bank['observation_id'];
        $lines = $this->allocations($id);
        $state = $this->statusOf($bank, $lines, $this->review($id));
        $p = $this->payload($bank);
        $closed = in_array($state['code'], ['excluded', 'exported'], true);
        $evidence = $this->matchedEvidence($id);
        $candidates = ($evidence || $closed) ? [] : $this->candidates($bank);
        $category = count($lines) === 1 ? $lines[0]['export_account'] : $this->suggest($bank, $evidence ?: ($candidates[0]['rs'] ?? []));
        $postUrl = esc_url(admin_url('admin-post.php'));

        echo '<tr class="bank ' . esc_attr($state['code']) . '" data-bank-id="' . $id . '">';
        echo '<td><input class="check" type="checkbox" name="selected[]" form="sat-bulk-form" value="' . $id . '"' . ($closed ? ' disabled' : '') . '></td>';
        echo '<td>' . esc_html($bank['transaction_date']) . '</td>';
        echo '<td>' . esc_html($this->accountLabel($this->rowSource)) . '</td>';
        echo '<td class="money">' . $this->money($bank['amount']) . '</td>';
        echo '<td><strong>' . esc_html($p['memo'] ?? $bank['description']) . '</strong><br><small>' . esc_html($p['name'] ?? $bank['description']) . ' · FITID ' . esc_html($p['fitid'] ?? '') . '</small>';
        if ($state['code'] === 'excluded') {
            echo '<br><small class="excluded-reason">Excluded: ' . esc_html($state['reason']) . '</small>';
        }
        echo '</td><td><form class="category" data-bank-id="' . $id . '" method="post" action="' . $postUrl . '">';
        $this->hidden('save-category');
        echo '<input type="hidden" name="bank_id" value="' . $id . '"><select name="category"' . ($closed ? ' disabled' : '') . '><option value="">Choose category</option>';
        $this->options($category);
        echo '</select>' . ($closed ? '' : '<button type="submit" class="category-save">Save</button>') . '</form></td>';
        echo '<td><span class="pill">' . esc_html($this->pillText($state)) . '</span></td><td class="actions">';
        if (!$closed) {
            echo '<button type="button" class="split-open" data-id="split-' . $id . '">' . (count($lines) > 1 ? 'Edit split' : 'Split') . '</button>';
            echo '<details class="exclude"><summary>Exclude</summary><form method="post" action="' . $postUrl . '">';
            $this->hidden('exclude');
            echo '<input type="hidden" name="bank_id" value="' . $id . '"><input type="text" name="reason" maxlength="255" required placeholder="Reason (e.g. duplicate)"><button type="submit">Exclude</button></form></details>';
        } elseif ($state['code'] === 'excluded') {
            echo '<form method="post" action="' . $postUrl . '">';
            $this->hidden('reopen');
            echo '<input type="hidden" name="bank_id" value="' . $id . '"><button type="submit">Reopen</button></form>';
        }
        echo '</td></tr>';

        foreach ($evidence as $e) {
            echo '<tr class="evidence matched"><td></td><td colspan="7">└─ <b>MATCHED</b> · ' . $this->evidenceHtml($e) . '</td></tr>';
        }
        if (!$evidence && $candidates) {
            $alternatives = array_slice($candidates, 1);
            $this->candidateRow($id, $candidates[0], 'best');
            if ($alternatives) {
                echo ' <button type="button" class="candidates-toggle" aria-expanded="false" data-target="alternative-' . $id . '">Show alternatives (' . count($alternatives) . ')</button>';
            }
            echo '</td></tr>';
            foreach ($alternatives as $alternative) {
                $this->candidateRow($id, $alternative, 'alternative alternative-' . $id);
                echo '</td></tr>';
            }
        }
    }

    private function renderClaims(): void
    {
        $rows = $this->db()->query(
            "SELECT o.*, CASE WHEN " . str_replace('NOT EXISTS', 'EXISTS', self::UNMATCHED) . " THEN 1 ELSE 0 END AS is_matched
             FROM inbox_observations o WHERE o.source_system = 'gravity_forms'
             ORDER BY o.transaction_date DESC, o.observation_id DESC"
        )->fetchAll();
        echo '<p class="help">Expense claims stay visible here whether or not they are matched to a bank or card transaction.</p>';
        echo '<div class="scroll"><table class="claims"><thead><tr><th>Date</th><th>Entry</th><th>Claimant</th><th>Payee</th><th>Description</th><th>Payment method</th><th class="money">Amount</th><th>Category</th><th>Receipt</th><th>Matched</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $p = $this->payload($row);
            $receipts = array_values(array_filter((array)($p['receipt_urls'] ?? [])));
            $category = (string)($p['submitted_category'] ?? '');
            $valid = $category === '' || $this->canon($category) !== '';
            echo '<tr><td>' . esc_html($row['transaction_date']) . '</td>';
            echo '<td>#' . esc_html((string)($row['gf_entry_id'] ?: ($p['entry_id'] ?? ''))) . '</td>';
            echo '<td>' . esc_html($p['submitter_name'] ?? '') . '</td>';
            echo '<td>' . esc_html($p['payee'] ?? '') . '</td>';
            echo '<td>' . esc_html($p['description'] ?? $row['description']) . '</td>';
            echo '<td>' . esc_html($p['payment_method'] ?? '') . '</td>';
            echo '<td class="money">$' . number_format(abs((float)$row['amount']), 2) . '</td>';
            echo '<td' . ($valid ? '' : ' class="not-in-wave" title="Not a current Wave account. Choose the category when matching."') . '>' . esc_html($category) . '</td><td>';
            if ($receipts) {
                foreach ($receipts as $i => $url) {
                    echo ($i ? '<br>' : '') . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">Receipt ' . ($i + 1) . '</a>';
                }
            } else {
                echo '—';
            }
            echo '</td><td><span class="pill ' . ($row['is_matched'] ? 'matched' : 'unmatched') . '">' . ($row['is_matched'] ? 'MATCHED' : 'UNMATCHED') . '</span></td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="10">No expense claims yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function renderModal(array $bank): void
    {
        $this->direction = (float)$bank['amount'] < 0 ? 'expense' : 'income';
        $this->rowSource = (string)$bank['source_system'];
        $id = (int)$bank['observation_id'];
        $evidence = $this->matchedEvidence($id);
        $candidates = $evidence ? [] : $this->candidates($bank);
        $lines = $this->allocations($id);
        $category = count($lines) === 1 ? $lines[0]['export_account'] : $this->suggest($bank, $evidence ?: ($candidates[0]['rs'] ?? []));
        $total = number_format(abs((float)$bank['amount']), 2, '.', '');
        $rows = $lines ?: [
            ['export_account' => $category, 'amount' => $total, 'allocation_note' => ''],
            ['export_account' => '', 'amount' => '', 'allocation_note' => ''],
        ];
        echo '<div class="modal" id="split-' . $id . '" hidden><div class="backdrop" data-close></div><div class="panel">';
        echo '<form class="split" data-total="' . $total . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $this->hidden('save-split');
        echo '<input type="hidden" name="bank_id" value="' . $id . '"><header><h3>Split $' . number_format(abs((float)$bank['amount']), 2) . '</h3><button type="button" data-close>Close</button></header><div class="lines">';
        foreach ($rows as $line) {
            echo '<div class="line"><select name="split_account[]"><option value="">Choose category</option>';
            $this->options((string)($line['export_account'] ?? ''));
            echo '</select><input name="split_amount[]" value="' . esc_attr($line['amount'] ?? '') . '" placeholder="0.00">';
            echo '<input name="split_note[]" value="' . esc_attr($line['allocation_note'] ?? '') . '" placeholder="Line note"><button type="button" class="remove">Remove</button></div>';
        }
        echo '</div><p class="summary">Allocated $<span class="allocated">0.00</span> · Remaining $<span class="remaining">0.00</span> · <span class="split-state">Check split lines</span></p>';
        echo '<footer><button type="button" class="add">Add line</button><button type="submit" class="primary save" disabled>Save split</button></footer></form></div></div>';
    }

    /** Category options; our own accounts are grouped separately as transfers. */
    private function options(string $selected): void
    {
        $own = $this->transferNames()[$this->rowSource] ?? '';
        $transfers = [];
        foreach ($this->accounts($this->direction) as $account) {
            if ($this->isTransferName($account)) {
                if ($this->accountKey($account) !== $this->accountKey($own)) {
                    $transfers[] = $account;
                }
                continue;
            }
            echo '<option value="' . esc_attr($account) . '"' . selected($selected, $account, false) . '>' . esc_html($account) . '</option>';
        }
        if ($transfers) {
            echo '<optgroup label="Transfer to or from">';
            foreach ($transfers as $account) {
                echo '<option value="' . esc_attr($account) . '"' . selected($selected, $account, false) . '>' . esc_html($account) . '</option>';
            }
            echo '</optgroup>';
        }
    }

    private function notice(): void
    {
        if (isset($_GET['sat_notice'], $_GET['sat_message'])) {
            echo '<div class="msg ' . esc_attr(sanitize_key(wp_unslash($_GET['sat_notice']))) . '">' . esc_html(sanitize_text_field(rawurldecode(wp_unslash($_GET['sat_message'])))) . '</div>';
        }
    }
}
