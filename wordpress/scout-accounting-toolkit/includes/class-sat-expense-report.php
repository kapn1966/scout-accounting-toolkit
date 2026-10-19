<?php
/**
 * Expense Claims sorting/filtering and the CG Funds monthly report.
 *
 * One selection (select()) over the claims query drives the visible table, the summary, the CSV
 * rows and the receipt files in the ZIP. The CG Funds preset includes every claim dated in the
 * selected calendar month except Reimburse a Scouter claims; it does not infer account funding.
 * Expense Date, Payee, Description and Amount are effective values (SAT_Claim_Corrections).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** A report refusal whose message is safe to show (no paths, no claim contents beyond the entry number). */
final class SAT_Expense_Report_Error extends RuntimeException
{
}

final class SAT_Expense_Report
{
    public const ACTION = 'sat_cgaming_report';
    public const NONCE = 'sat_cgaming_report';
    public const PRESET = 'cgaming';
    public const INCLUSION_NOTE = 'Includes all claims for the selected month except Reimburse a Scouter. Review the exported CSV for non-CGaming exceptions.';

    /** Query parameters owned by the Expense Claims controls (prefixed to avoid WordPress query vars). */
    public const PARAMS = ['claim_preset', 'claim_month', 'claim_claimant', 'claim_method', 'claim_match', 'claim_sort', 'claim_order'];

    public const SORTS = [
        'expense_date' => 'Expense date',
        'submitted' => 'Submission date',
        'claimant' => 'Claimant',
        'vendor' => 'Vendor',
        'amount' => 'Amount',
    ];
    public const ORDERS = ['desc' => 'Descending', 'asc' => 'Ascending'];
    public const METHODS = ['card' => 'Credit card', 'invoice' => 'Invoice', 'reimburse' => 'Reimburse a Scouter', 'other' => 'Other'];
    public const MATCHES = ['matched' => 'Matched', 'unmatched' => 'Unmatched'];

    public const CSV_COLUMNS = [
        'Gravity Forms Entry ID',
        'Expense Date',
        'Submission Date',
        'Claimant',
        'Vendor',
        'Description',
        'Expense Category',
        'Amount',
        'Section or Program',
        'Payment Method',
        'Match Status',
        'Receipt Filename',
        'Receipt MIME Type',
    ];

    /** Receipt content types accepted in the ZIP and the file extensions each may carry. */
    private const RECEIPT_TYPES = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
    ];

    private const REFUSAL = 'Cannot create the CGaming report: ';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        add_action('admin_post_' . self::ACTION, [$this, 'action_download']);
    }

    /* ------------------------------------------------------------------ */
    /* Request parameters                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Calendar month 'YYYY-MM' as a half-open date range, or null when invalid.
     *
     * @return array{month:string,start:string,end:string,label:string}|null
     */
    public static function month_range(string $month): ?array
    {
        if (preg_match('/^[12]\d{3}-(0[1-9]|1[0-2])$/D', $month) !== 1) {
            return null;
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new DateTimeZone('UTC'));
        if ($start === false || $start->format('Y-m') !== $month) {
            return null;
        }
        return [
            'month' => $month,
            'start' => $start->format('Y-m-d'),
            'end' => $start->modify('first day of next month')->format('Y-m-d'),
            // PHP's 'F' is always English, so labels and filenames do not depend on locale.
            'label' => $start->format('F Y'),
        ];
    }

    private static function previous_month(string $current): string
    {
        $range = self::month_range($current);
        if ($range === null) {
            return '';
        }
        return (new DateTimeImmutable($range['start'], new DateTimeZone('UTC')))->modify('first day of previous month')->format('Y-m');
    }

    /**
     * Validated filters from unslashed query parameters. Unknown values fall back to defaults.
     * The CG Funds preset uses only the month (default: the month before $current_month) and sorting.
     */
    public static function filters(array $query, string $current_month): array
    {
        $get = static function (string $key) use ($query): string {
            $value = $query[$key] ?? '';
            return is_scalar($value) ? trim((string)$value) : '';
        };
        $preset = $get('claim_preset') === self::PRESET ? self::PRESET : '';
        $month = self::month_range($get('claim_month')) !== null ? $get('claim_month') : '';
        if ($preset !== '' && $month === '') {
            $month = self::previous_month($current_month);
        }
        $method = $get('claim_method');
        $match = $get('claim_match');
        $sort = $get('claim_sort');
        $order = $get('claim_order');
        $claimant = self::clean_text($get('claim_claimant'), 200);

        return [
            'preset' => $preset,
            'month' => $month,
            'claimant' => $preset === '' ? $claimant : '',
            'method' => $preset === '' && isset(self::METHODS[$method]) ? $method : '',
            'match' => $preset === '' && isset(self::MATCHES[$match]) ? $match : '',
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'expense_date',
            'order' => isset(self::ORDERS[$order]) ? $order : 'desc',
        ];
    }

    /** Filters for the CG Funds report of one month, as used by the screen and the ZIP. */
    public static function cgaming_filters(string $month): array
    {
        return self::filters(['claim_preset' => self::PRESET, 'claim_month' => $month], $month);
    }

    public static function narrowed(array $f): bool
    {
        return $f['month'] !== '' || $f['claimant'] !== '' || $f['method'] !== '' || $f['match'] !== '';
    }

    /* ------------------------------------------------------------------ */
    /* Claims                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Every expense claim, newest first, with the user-facing matched flag shown on Expense Claims.
     * Expense Date, Payee, Description and Amount are the effective (corrected) values.
     */
    public function claims(): array
    {
        $db = SAT_DB::pdo();
        $rows = $db->query(
            "SELECT o.*, CASE WHEN EXISTS (SELECT 1 FROM inbox_matches m WHERE m.left_observation_id = o.observation_id OR m.right_observation_id = o.observation_id) THEN 1 ELSE 0 END AS is_matched
             FROM inbox_observations o WHERE o.source_system = 'gravity_forms'
             ORDER BY o.transaction_date DESC, o.observation_id DESC"
        )->fetchAll();
        $context = [
            'sections' => array_keys((array)(SAT_Settings::get('inbox')['section_map'] ?? [])),
            'canonical' => [SAT_Settings::class, 'canonical_category'],
            'submitted_lookup' => [SAT_Expense_Form::class, 'entry_submitted_at_gmt'],
            'local_time' => static fn (string $gmt): string => (string)get_date_from_gmt($gmt, 'Y-m-d H:i:s'),
            'corrections' => SAT_Claim_Corrections::all(),
        ];
        $claims = array_map(static fn (array $row): array => self::claim($row, $context), $rows);
        return self::flag_amount_mismatches($claims, self::matched_bank_amounts($db));
    }

    /**
     * Claims matched to bank or card transactions: [claim_id, bank_id, bank_amount] per match.
     * Used only to flag disagreement; matches are never changed here.
     */
    private static function matched_bank_amounts(PDO $db): array
    {
        return $db->query(
            "SELECT c.observation_id AS claim_id, b.observation_id AS bank_id, b.amount AS bank_amount
             FROM inbox_matches m
             JOIN inbox_observations c ON c.observation_id IN (m.left_observation_id, m.right_observation_id) AND c.source_system = 'gravity_forms'
             JOIN inbox_observations b ON b.observation_id IN (m.left_observation_id, m.right_observation_id) AND b.observation_id <> c.observation_id AND b.source_system <> 'gravity_forms'"
        )->fetchAll();
    }

    /**
     * Flag matched claims whose effective amounts no longer add up to their bank or card
     * transaction (one claim, or the group of claims matched to the same transaction).
     */
    public static function flag_amount_mismatches(array $claims, array $links): array
    {
        $index = [];
        foreach ($claims as $i => $c) {
            $index[$c['observation_id']] = $i;
        }
        $groups = [];
        foreach ($links as $link) {
            $claim_id = (int)$link['claim_id'];
            if (isset($index[$claim_id])) {
                $groups[(int)$link['bank_id']]['bank'] = SAT_Claim_Corrections::amount_cents((string)$link['bank_amount']);
                $groups[(int)$link['bank_id']]['claims'][$claim_id] = true;
            }
        }
        foreach ($groups as $group) {
            $sum = 0;
            foreach (array_keys($group['claims']) as $claim_id) {
                $sum += $claims[$index[$claim_id]]['amount_cents'];
            }
            if ($sum !== $group['bank']) {
                foreach (array_keys($group['claims']) as $claim_id) {
                    $claims[$index[$claim_id]]['amount_mismatch'] = true;
                }
            }
        }
        return $claims;
    }

    /**
     * Business view of one claim observation.
     *
     * $context: sections (configured section names), canonical (category alias callable),
     * submitted_lookup (entry ID -> GF date_created in UTC), local_time (UTC -> site time),
     * corrections (observation ID -> field -> active correction row).
     */
    public static function claim(array $row, array $context = []): array
    {
        $p = json_decode((string)($row['payload_json'] ?? ''), true);
        $p = is_array($p) ? $p : [];
        $observation_id = (int)($row['observation_id'] ?? 0);
        $entry_id = (int)($row['gf_entry_id'] ?? 0) ?: (int)($p['entry_id'] ?? 0);
        $method = (string)($p['payment_method'] ?? '');
        $category = (string)($p['submitted_category'] ?? '');

        $gmt = SAT_Expense_Form::gmt_datetime((string)($p['submitted_at_gmt'] ?? ''));
        if ($gmt === '' && isset($context['submitted_lookup'])) {
            $gmt = SAT_Expense_Form::gmt_datetime((string)($context['submitted_lookup'])($entry_id));
        }
        $submitted = $gmt !== '' && isset($context['local_time']) ? (string)($context['local_time'])($gmt) : $gmt;

        $resolved = SAT_Claim_Corrections::resolve(
            SAT_Claim_Corrections::source_values($row),
            (array)(($context['corrections'] ?? [])[$observation_id] ?? [])
        );
        $effective = SAT_Claim_Corrections::effective($resolved);

        return [
            'observation_id' => $observation_id,
            'entry_id' => $entry_id,
            'expense_date' => $effective['transaction_date'],
            'submitted_at' => $submitted,
            'claimant' => (string)($p['submitter_name'] ?? ''),
            'vendor' => $effective['payee'],
            'description' => $effective['description'],
            'category' => $category,
            'section' => self::section($category, (array)($context['sections'] ?? []), $context['canonical'] ?? null),
            'amount_cents' => SAT_Claim_Corrections::amount_cents($effective['amount']),
            'payment_method' => $method,
            'payment_kind' => SAT_Expense_Form::payment_kind($method),
            'matched' => !empty($row['is_matched']),
            'receipt_urls' => array_values(array_filter(array_map('strval', (array)($p['receipt_urls'] ?? [])), 'strlen')),
            'corrections' => $resolved,
            'corrected' => $resolved['corrected'],
            'correction_review' => $resolved['review'],
            'amount_mismatch' => false,
        ];
    }

    /**
     * The configured section a category belongs to, when the category (as submitted or after alias
     * mapping) starts with "<section> - ". Group-level categories have no section.
     */
    public static function section(string $category, array $sections, ?callable $canonical = null): string
    {
        $values = [trim($category)];
        if ($canonical !== null) {
            $values[] = trim((string)$canonical($category));
        }
        $names = array_values(array_filter(array_map(static fn ($s): string => trim((string)$s), $sections), 'strlen'));
        usort($names, static fn (string $a, string $b): int => (strlen($b) <=> strlen($a)) ?: strcmp($a, $b));
        foreach ($names as $name) {
            $pattern = '/^' . preg_quote($name, '/') . '\s*[-\x{2013}\x{2014}]\s*\S/iu';
            foreach ($values as $value) {
                if ($value !== '' && preg_match($pattern, $value) === 1) {
                    return $name;
                }
            }
        }
        return '';
    }

    /** The authoritative selection for the table, summary, CSV and ZIP. */
    public static function select(array $claims, array $f): array
    {
        $range = $f['month'] !== '' ? self::month_range($f['month']) : null;
        $claimant = self::name_key($f['claimant']);
        $seen = [];
        $out = [];
        foreach ($claims as $c) {
            if (isset($seen[$c['observation_id']])) {
                continue;
            }
            if ($range !== null && ($c['expense_date'] < $range['start'] || $c['expense_date'] >= $range['end'])) {
                continue;
            }
            if ($f['preset'] === self::PRESET && $c['payment_kind'] === 'reimburse') {
                continue;
            }
            if ($claimant !== '' && self::name_key($c['claimant']) !== $claimant) {
                continue;
            }
            if ($f['method'] !== '' && $c['payment_kind'] !== $f['method']) {
                continue;
            }
            if ($f['match'] !== '' && ($c['matched'] ? 'matched' : 'unmatched') !== $f['match']) {
                continue;
            }
            $seen[$c['observation_id']] = true;
            $out[] = $c;
        }
        return self::sort($out, $f['sort'], $f['order']);
    }

    /** Sort by an allowlisted key; ties fall back to expense date, then claim order. */
    public static function sort(array $claims, string $sort, string $order): array
    {
        $sort = isset(self::SORTS[$sort]) ? $sort : 'expense_date';
        $sign = $order === 'asc' ? 1 : -1;
        usort($claims, static function (array $a, array $b) use ($sort, $sign): int {
            $cmp = match ($sort) {
                'amount' => $a['amount_cents'] <=> $b['amount_cents'],
                'submitted' => strcmp($a['submitted_at'], $b['submitted_at']),
                'claimant' => strnatcmp(self::name_key($a['claimant']), self::name_key($b['claimant'])),
                'vendor' => strnatcmp(self::name_key($a['vendor']), self::name_key($b['vendor'])),
                default => strcmp($a['expense_date'], $b['expense_date']),
            };
            if ($cmp === 0 && $sort !== 'expense_date') {
                $cmp = strcmp($a['expense_date'], $b['expense_date']);
            }
            if ($cmp === 0) {
                $cmp = $a['observation_id'] <=> $b['observation_id'];
            }
            return $sign * ($cmp <=> 0);
        });
        return $claims;
    }

    /** Included claims in report order (Expense Date, then claim), for one CG Funds month. */
    public static function report_claims(array $claims, string $month): array
    {
        return self::sort(self::select($claims, self::cgaming_filters($month)), 'expense_date', 'asc');
    }

    public static function summary(array $claims): array
    {
        $receipts = 0;
        foreach ($claims as $c) {
            $receipts += count($c['receipt_urls']);
        }
        return [
            'claims' => count($claims),
            'total_cents' => array_sum(array_column($claims, 'amount_cents')),
            'receipts' => $receipts,
        ];
    }

    /** Distinct claimant names (first spelling seen), naturally sorted. */
    public static function claimants(array $claims): array
    {
        $names = [];
        foreach ($claims as $c) {
            $key = self::name_key($c['claimant']);
            if ($key !== '' && !isset($names[$key])) {
                $names[$key] = trim($c['claimant']);
            }
        }
        natcasesort($names);
        return array_values($names);
    }

    /** Months that have claims, newest first, plus the selected month. */
    public static function months(array $claims, string $selected = ''): array
    {
        $months = [];
        foreach ($claims as $c) {
            $month = substr($c['expense_date'], 0, 7);
            if (self::month_range($month) !== null) {
                $months[$month] = true;
            }
        }
        if ($selected !== '') {
            $months[$selected] = true;
        }
        krsort($months, SORT_STRING);
        return array_keys($months);
    }

    private static function name_key(string $name): string
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private static function clean_text(string $value, int $limit): string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    public static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /* ------------------------------------------------------------------ */
    /* Receipts                                                            */
    /* ------------------------------------------------------------------ */

    /** Gravity Forms upload storage: the only place receipts may be read from. */
    private static function receipt_storage(): array
    {
        if (class_exists('GFFormsModel') && method_exists('GFFormsModel', 'get_upload_root') && method_exists('GFFormsModel', 'get_upload_url_root')) {
            return ['base_dir' => (string)GFFormsModel::get_upload_root(), 'base_url' => (string)GFFormsModel::get_upload_url_root()];
        }
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            return ['base_dir' => '', 'base_url' => ''];
        }
        return ['base_dir' => $uploads['basedir'] . '/gravity_forms/', 'base_url' => $uploads['baseurl'] . '/gravity_forms/'];
    }

    /** Scheme-less comparison key '//host[:port]/path' for an http(s) URL, or '' when unusable. */
    private static function url_key(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        return '//' . strtolower((string)$parts['host']) . (isset($parts['port']) ? ':' . (int)$parts['port'] : '') . (string)($parts['path'] ?? '/');
    }

    /**
     * Local file for a receipt URL inside the approved storage directory.
     * Returns null when the URL is remote, unsafe or resolves outside storage; '' when the file is missing.
     */
    private static function local_receipt_path(string $url, string $base_key, string $base_dir): ?string
    {
        $key = self::url_key($url);
        if ($key === '' || strncmp($key, $base_key, strlen($base_key)) !== 0) {
            return null;
        }
        $relative = rawurldecode(substr($key, strlen($base_key)));
        if ($relative === '' || $relative[0] === '/' || str_contains($relative, "\0") || str_contains($relative, '\\')
            || preg_match('#(^|/)\.{1,2}(/|$)#', $relative) === 1) {
            return null;
        }
        $candidate = $base_dir . DIRECTORY_SEPARATOR . $relative;
        $real = realpath($candidate);
        if ($real === false) {
            return file_exists($candidate) || is_link($candidate) ? null : '';
        }
        return strncmp($real, $base_dir . DIRECTORY_SEPARATOR, strlen($base_dir) + 1) === 0 ? $real : null;
    }

    private static function detect_mime(string $path): string
    {
        if (!function_exists('finfo_open')) {
            throw new SAT_Expense_Report_Error(self::REFUSAL . 'receipt file types cannot be verified on this server.');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $path) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        return is_string($mime) ? strtolower($mime) : '';
    }

    /** Readable, extraction-safe ASCII for a filename part. */
    public static function filename_text(string $text, string $fallback, int $max = 60): string
    {
        if (class_exists('Normalizer')) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                $text = (string)preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }
        $text = str_replace(["'", "\u{2019}", '`'], '', $text);
        $text = (string)preg_replace('/[^A-Za-z0-9&()+,._-]+/', ' ', $text);
        $text = (string)preg_replace('/\.{2,}/', '.', $text);
        $text = (string)preg_replace(['/\(\s+/', '/\s+\)/'], ['(', ')'], $text);
        $text = trim((string)preg_replace('/\s+/', ' ', $text), ' .,_-');
        if (strlen($text) > $max) {
            $text = rtrim(substr($text, 0, $max), ' .,_-');
        }
        return $text !== '' ? $text : $fallback;
    }

    /** e.g. "2026-09-03 - Brick and Beam Coffee - 28.91 - entry-123.pdf" */
    public static function receipt_filename(array $claim, string $extension): string
    {
        return sprintf(
            '%s - %s - %s - entry-%d.%s',
            $claim['expense_date'],
            self::filename_text($claim['vendor'], 'Unknown vendor'),
            self::amount((int)$claim['amount_cents']),
            (int)$claim['entry_id'],
            $extension
        );
    }

    /** Case-insensitively unique within the ZIP; a deterministic " (n)" suffix resolves collisions. */
    private static function unique_name(string $name, array &$used): string
    {
        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        $ext = $dot === false ? '' : substr($name, $dot);
        for ($n = 1; $n <= 100; $n++) {
            $candidate = $n === 1 ? $name : $stem . ' (' . $n . ')' . $ext;
            $key = strtolower($candidate);
            if (!isset($used[$key])) {
                $used[$key] = true;
                return $candidate;
            }
        }
        throw new SAT_Expense_Report_Error(self::REFUSAL . 'receipt filenames could not be made unique.');
    }

    /**
     * Validate and name exactly one local receipt per included claim, refusing the whole report on
     * any inconsistency. Keyed by observation ID.
     *
     * @param array $storage ['base_dir' => directory, 'base_url' => matching public URL]
     * @return array<int, array{zip_name:string,path:string,mime:string}>
     */
    public static function receipt_plan(array $claims, array $storage): array
    {
        $configured_dir = (string)($storage['base_dir'] ?? '');
        $base_dir = $configured_dir === '' ? false : realpath($configured_dir);
        $base_key = self::url_key((string)($storage['base_url'] ?? ''));
        if ($base_dir === false || !is_dir($base_dir) || $base_key === '') {
            throw new SAT_Expense_Report_Error(self::REFUSAL . 'the receipt upload storage is not available.');
        }
        $base_key = rtrim($base_key, '/') . '/';
        $base_dir = rtrim($base_dir, DIRECTORY_SEPARATOR);

        $plan = [];
        $paths = [];
        $names = [];
        foreach ($claims as $c) {
            $entry = (int)$c['entry_id'];
            if ($entry < 1) {
                throw new SAT_Expense_Report_Error(self::REFUSAL . 'an included expense claim has no Gravity Forms entry ID.');
            }
            if (count($c['receipt_urls']) !== 1) {
                throw new SAT_Expense_Report_Error(sprintf(self::REFUSAL . 'expense entry #%d does not have exactly one readable receipt (%d attached).', $entry, count($c['receipt_urls'])));
            }
            $path = self::local_receipt_path($c['receipt_urls'][0], $base_key, $base_dir);
            if ($path === null) {
                throw new SAT_Expense_Report_Error(sprintf(self::REFUSAL . 'expense entry #%d has a receipt outside the approved upload storage.', $entry));
            }
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                throw new SAT_Expense_Report_Error(sprintf(self::REFUSAL . 'expense entry #%d does not have exactly one readable receipt.', $entry));
            }
            if (isset($paths[$path])) {
                throw new SAT_Expense_Report_Error(sprintf(self::REFUSAL . 'expense entries #%d and #%d use the same receipt file.', $paths[$path], $entry));
            }
            $paths[$path] = $entry;

            $mime = self::detect_mime($path);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!isset(self::RECEIPT_TYPES[$mime]) || !in_array($extension, self::RECEIPT_TYPES[$mime], true)) {
                throw new SAT_Expense_Report_Error(sprintf(self::REFUSAL . 'expense entry #%d has a receipt that is not a PDF, JPEG or PNG matching its file extension.', $entry));
            }
            $plan[(int)$c['observation_id']] = [
                'zip_name' => 'receipts/' . self::unique_name(self::receipt_filename($c, $extension), $names),
                'path' => $path,
                'mime' => $mime,
            ];
        }
        return $plan;
    }

    /* ------------------------------------------------------------------ */
    /* CSV and ZIP                                                         */
    /* ------------------------------------------------------------------ */

    public static function csv_filename(array $range): string
    {
        return 'CGaming expenses - ' . $range['label'] . '.csv';
    }

    public static function zip_filename(array $range): string
    {
        return 'CGaming expenses - ' . $range['label'] . '.zip';
    }

    /** UTF-8 (with BOM for spreadsheets), CRLF, one row per claim in the given order. */
    public static function csv(array $claims, array $plan): string
    {
        $lines = [self::csv_line(self::CSV_COLUMNS)];
        foreach ($claims as $c) {
            $receipt = $plan[(int)$c['observation_id']] ?? null;
            if ($receipt === null) {
                throw new SAT_Expense_Report_Error(sprintf(self::REFUSAL . 'expense entry #%d has no validated receipt.', (int)$c['entry_id']));
            }
            $lines[] = self::csv_line([
                (string)(int)$c['entry_id'],
                $c['expense_date'],
                preg_match('/^\d{4}-\d{2}-\d{2}/', $c['submitted_at']) === 1 ? substr($c['submitted_at'], 0, 10) : '',
                self::csv_text($c['claimant']),
                self::csv_text($c['vendor']),
                self::csv_text($c['description']),
                self::csv_text($c['category']),
                self::amount((int)$c['amount_cents']),
                self::csv_text($c['section']),
                self::csv_text($c['payment_method']),
                $c['matched'] ? 'Matched' : 'Unmatched',
                $receipt['zip_name'],
                $receipt['mime'],
            ]);
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    private static function csv_line(array $fields): string
    {
        return implode(',', array_map(static function ($value): string {
            $value = (string)$value;
            return preg_match('/[",\r\n]/', $value) === 1 || $value !== trim($value)
                ? '"' . str_replace('"', '""', $value) . '"'
                : $value;
        }, $fields));
    }

    /** Free text that a spreadsheet would read as a formula is prefixed with an apostrophe. */
    private static function csv_text(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    public static function write_zip(string $path, string $csv_name, string $csv, array $plan): void
    {
        $entries = [$csv_name];
        foreach ($plan as $receipt) {
            $entries[] = $receipt['zip_name'];
        }
        foreach ($entries as $name) {
            if (preg_match('#^(receipts/)?[A-Za-z0-9][A-Za-z0-9 &()+,._-]*$#D', $name) !== 1 || str_contains($name, '..')) {
                throw new SAT_Expense_Report_Error(self::REFUSAL . 'a ZIP entry name is unsafe.');
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new SAT_Expense_Report_Error(self::REFUSAL . 'the private ZIP could not be opened.');
        }
        $open = true;
        try {
            $ok = $zip->addFromString($csv_name, $csv) && $zip->setMtimeName($csv_name, 315532800);
            if ($ok && $plan) {
                $ok = $zip->addEmptyDir('receipts') && $zip->setMtimeName('receipts/', 315532800);
            }
            foreach ($plan as $receipt) {
                $ok = $ok && $zip->addFile($receipt['path'], $receipt['zip_name']) && $zip->setMtimeName($receipt['zip_name'], 315532800);
            }
            if (!$ok) {
                throw new SAT_Expense_Report_Error(self::REFUSAL . 'a file could not be added to the ZIP.');
            }
            $open = false;
            if (!$zip->close()) {
                throw new SAT_Expense_Report_Error(self::REFUSAL . 'the ZIP could not be completed.');
            }
        } catch (Throwable $e) {
            if ($open) {
                try {
                    $zip->close();
                } catch (Throwable $ignored) {
                    // The caller removes the temporary file; the original refusal is what matters.
                }
            }
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Download                                                            */
    /* ------------------------------------------------------------------ */

    private function guard(): void
    {
        if (!is_user_logged_in() || !current_user_can(SAT_CAP_REVIEW)) {
            wp_die('You do not have permission to download expense reports.', 'Scout Accounting', ['response' => 403]);
        }
        check_admin_referer(self::NONCE);
    }

    public function action_download(): void
    {
        $this->guard();
        $this->deliver(sanitize_text_field(wp_unslash($_POST['claim_month'] ?? '')));
        exit;
    }

    /**
     * Build the report ZIP in a private temporary file and stream it. Any refusal removes the
     * temporary file and redirects back with the message; a shutdown hook covers aborted downloads.
     */
    public function deliver(string $month): void
    {
        $temporary = '';
        try {
            $range = self::month_range($month);
            if ($range === null) {
                throw new SAT_Expense_Report_Error(self::REFUSAL . 'choose a valid month.');
            }
            $claims = self::report_claims($this->claims(), $range['month']);
            $plan = self::receipt_plan($claims, self::receipt_storage());
            $csv = self::csv($claims, $plan);

            $temporary = (string)tempnam(get_temp_dir(), 'sat-cgaming-');
            if ($temporary === '') {
                throw new SAT_Expense_Report_Error(self::REFUSAL . 'a private temporary file could not be created.');
            }
            SAT_Transfer_OFX::register_temporary_cleanup($temporary);
            if (!chmod($temporary, 0600)) {
                throw new SAT_Expense_Report_Error(self::REFUSAL . 'the private temporary file could not be secured.');
            }
            self::write_zip($temporary, self::csv_filename($range), $csv, $plan);
            $size = filesize($temporary);
            if (!is_readable($temporary) || $size === false) {
                throw new SAT_Expense_Report_Error(self::REFUSAL . 'the generated ZIP cannot be read.');
            }
            nocache_headers();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . self::zip_filename($range) . '"');
            header('Content-Length: ' . (string)$size);
            readfile($temporary);
            unlink($temporary);
        } catch (Throwable $e) {
            if ($temporary !== '' && is_file($temporary)) {
                unlink($temporary);
            }
            $message = $e instanceof SAT_Expense_Report_Error ? $e->getMessage() : self::REFUSAL . 'an unexpected error occurred.';
            $this->redirect_back($month, $message);
        }
    }

    private function redirect_back(string $month, string $message): void
    {
        $url = wp_get_referer() ?: home_url('/');
        $url = add_query_arg(['sat_view' => 'claims', 'claim_preset' => self::PRESET], $url);
        if (self::month_range($month) !== null) {
            $url = add_query_arg('claim_month', $month, $url);
        }
        wp_safe_redirect(add_query_arg(['sat_notice' => 'error', 'sat_message' => rawurlencode($message)], $url));
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    /** View switch, controls and (for the preset) the report summary and download form. */
    public function render_controls(array $f, array $all, array $rows): void
    {
        $cg = $f['preset'] === self::PRESET;
        $base = remove_query_arg(array_merge(self::PARAMS, ['sat_notice', 'sat_message']));
        echo '<nav class="sat-views" aria-label="Expense claim views">';
        echo '<a class="' . ($cg ? '' : 'active') . '" href="' . esc_url($base) . '">All claims</a>';
        echo '<a class="' . ($cg ? 'active' : '') . '" href="' . esc_url(add_query_arg('claim_preset', self::PRESET, $base)) . '">CG Funds monthly report</a>';
        echo '</nav>';

        if (!$cg) {
            echo '<p class="help">Expense claims stay visible here whether or not they are matched to a bank or card transaction.</p>';
            $this->filter_form($f, $all, $base);
            return;
        }

        $range = self::month_range($f['month']);
        $summary = self::summary($rows);
        echo '<section class="cgaming-report" aria-labelledby="sat-cgaming-title">';
        echo '<h3 id="sat-cgaming-title">CG Funds monthly report</h3>';
        echo '<p class="help">' . esc_html(self::INCLUSION_NOTE) . '</p>';
        echo '<div class="cgaming-actions">';
        $this->filter_form($f, $all, $base);
        if ($range !== null) {
            echo '<form method="post" class="cgaming-download" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::NONCE);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            echo '<input type="hidden" name="claim_month" value="' . esc_attr($range['month']) . '">';
            echo '<button type="submit" class="primary">Download receipts ZIP</button></form>';
        }
        echo '</div>';
        echo '<dl class="cgaming-summary">';
        echo '<div><dt>Month</dt><dd>' . esc_html($range['label'] ?? '') . '</dd></div>';
        echo '<div data-claim-summary="claims"><dt>Included claims</dt><dd>' . (int)$summary['claims'] . '</dd></div>';
        echo '<div data-claim-summary="total"><dt>Total</dt><dd>$' . esc_html(number_format($summary['total_cents'] / 100, 2)) . '</dd></div>';
        echo '<div data-claim-summary="receipts"><dt>Included receipts</dt><dd>' . (int)$summary['receipts'] . '</dd></div>';
        echo '</dl>';

        $warning = self::receipt_warning($rows);
        echo '<div class="msg warning" data-claim-receipt-warning' . ($warning === '' ? ' hidden>' : '>') . esc_html($warning) . '</div>';
        echo '</section>';
    }

    /** The CG Funds receipt-count warning for the included claims, or '' when every claim has one receipt. */
    public static function receipt_warning(array $rows): string
    {
        $problems = [];
        foreach ($rows as $c) {
            if (count($c['receipt_urls']) !== 1) {
                $problems[] = (int)$c['entry_id'];
            }
        }
        if (!$problems) {
            return '';
        }
        sort($problems);
        $shown = array_map(static fn (int $id): string => '#' . $id, array_slice($problems, 0, 20));
        $more = count($problems) - count($shown);
        return 'Receipt check: expense ' . (count($problems) === 1 ? 'entry ' : 'entries ')
            . implode(', ', $shown) . ($more > 0 ? ' and ' . $more . ' more' : '')
            . (count($problems) === 1 ? ' does' : ' do') . ' not have exactly one receipt. The ZIP will be refused until this is corrected.';
    }

    private function filter_form(array $f, array $all, string $base): void
    {
        $cg = $f['preset'] === self::PRESET;
        echo '<form method="get" class="filters claim-filters">';
        foreach ($_GET as $key => $value) {
            if (in_array($key, self::PARAMS, true) || in_array($key, ['sat_notice', 'sat_message'], true) || !is_scalar($value)) {
                continue;
            }
            echo '<input type="hidden" name="' . esc_attr((string)$key) . '" value="' . esc_attr((string)wp_unslash($value)) . '">';
        }
        if ($cg) {
            echo '<input type="hidden" name="claim_preset" value="' . esc_attr(self::PRESET) . '">';
        }

        echo '<label>Month <select name="claim_month">';
        if (!$cg) {
            echo '<option value="">All months</option>';
        }
        foreach (self::months($all, $f['month']) as $month) {
            echo '<option value="' . esc_attr($month) . '"' . selected($f['month'], $month, false) . '>' . esc_html(self::month_range($month)['label']) . '</option>';
        }
        echo '</select></label>';

        if (!$cg) {
            echo '<label>Claimant <select name="claim_claimant"><option value="">All claimants</option>';
            foreach (self::claimants($all) as $name) {
                echo '<option value="' . esc_attr($name) . '"' . selected(self::name_key($f['claimant']), self::name_key($name), false) . '>' . esc_html($name) . '</option>';
            }
            echo '</select></label>';
            $this->select_field('Payment method', 'claim_method', ['' => 'All methods'] + self::METHODS, $f['method']);
            $this->select_field('Match status', 'claim_match', ['' => 'Matched and unmatched'] + self::MATCHES, $f['match']);
        }
        $this->select_field('Sort by', 'claim_sort', self::SORTS, $f['sort']);
        $this->select_field('Order', 'claim_order', self::ORDERS, $f['order']);
        echo '<button>' . ($cg ? 'Apply' : 'Filter') . '</button>';
        if (!$cg && (self::narrowed($f) || $f['sort'] !== 'expense_date' || $f['order'] !== 'desc')) {
            echo ' <a class="clear-filters" href="' . esc_url($base) . '">Clear filters</a>';
        }
        echo '</form>';
    }

    private function select_field(string $label, string $name, array $options, string $current): void
    {
        echo '<label>' . esc_html($label) . ' <select name="' . esc_attr($name) . '">';
        foreach ($options as $value => $text) {
            echo '<option value="' . esc_attr((string)$value) . '"' . selected($current, (string)$value, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></label>';
    }
}
