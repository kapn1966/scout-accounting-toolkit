<?php
/**
 * All plugin settings, stored in one option.
 *
 * Sections:
 *   wave      Wave business and discovered chart of accounts
 *   form      Gravity Forms expense form and field mapping
 *   accounts  Bank and card accounts imported from OFX files
 *   ocr       Receipt OCR
 *   inbox     Matching and suggestion rules
 *   tools     Tools tab (cut-over dates, cron log)
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Settings
{
    public const OPTION = 'sat_settings';

    public const FORM_FIELDS = [
        'name' => 'Submitter name',
        'email' => 'Submitter email',
        'amount' => 'Total amount',
        'date' => 'Receipt or invoice date',
        'category' => 'Expense category (Wave account name)',
        'payee' => 'Payee',
        'description' => 'Brief description',
        'payment' => 'Payment method',
        'receipt' => 'Receipt upload',
        'external_id' => 'Request ID',
    ];

    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'wave' => [
                'business_id' => '',
                'business_name' => '',
                'discovered_businesses' => [],
                'discovered_accounts' => [],
            ],
            'form' => array_merge(['form_id' => 0], array_fill_keys(array_keys(self::FORM_FIELDS), '')),
            'accounts' => [],
            'ocr' => [
                'enabled' => '0',
                'credit_card_choice' => '',
                'reimbursement_choice' => '',
                'card_mappings' => '',
                'card_context_chars' => '40',
                'known_merchants' => '',
                'tesseract_path' => '/usr/bin/tesseract',
                'pdftoppm_path' => '/usr/bin/pdftoppm',
                'language' => 'eng',
                'max_files' => '10',
                'max_file_mb' => '8',
                'rate_limit' => '12',
                'debug_log' => '0',
            ],
            'inbox' => [
                'uniform_account' => '',
                'uniform_keywords' => 'hat, hats, shirt, shirts, uniform, uniforms, necker, neckers, scarf, scarves',
                'section_map' => [],
                'category_aliases' => [],
            ],
            'tools' => [
                'cutover' => [],
                'cron_log' => '',
            ],
        ];
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, []);
            $saved = is_array($saved) ? $saved : [];
            $merged = [];
            foreach (self::defaults() as $section => $default) {
                $value = $saved[$section] ?? [];
                $merged[$section] = is_array($value) && $section !== 'accounts'
                    ? array_merge($default, $value)
                    : (is_array($value) ? $value : $default);
            }
            self::$cache = $merged;
        }
        return self::$cache;
    }

    public static function get(string $section): array
    {
        return self::all()[$section] ?? [];
    }

    public static function update(string $section, array $values): void
    {
        $all = self::all();
        $all[$section] = $values;
        update_option(self::OPTION, $all, false);
        self::$cache = null;
    }

    /**
     * Bank and card accounts keyed by inbox source system, e.g.
     *   'ofx_operating' => ['label' => 'Operating', 'type' => 'bank', 'wave' => 'Operating Acct', 'acctid' => '...']
     */
    public static function banks(): array
    {
        $out = [];
        foreach (self::get('accounts') as $acctid => $a) {
            $source = (string)($a['source_system'] ?? '');
            if ($source === '' || isset($out[$source])) {
                continue;
            }
            $out[$source] = [
                'label' => (string)($a['account_name'] ?? $source),
                'type' => (string)($a['statement_type'] ?? 'bank'),
                'wave' => (string)($a['wave_account'] ?? ''),
                'acctid' => (string)$acctid,
            ];
        }
        return $out;
    }

    public static function wave_token(): string
    {
        return defined('SAT_WAVE_TOKEN') && SAT_WAVE_TOKEN ? (string)SAT_WAVE_TOKEN : '';
    }

    /**
     * Convert a form-facing category prefix to its canonical Wave prefix.
     * Example: Venturers | Sea Venturers converts
     * Venturers - Camp food to Sea Venturers - Camp food.
     */
    public static function canonical_category(string $category): string
    {
        $category = trim($category);
        if ($category === '') {
            return '';
        }

        foreach ((array)(self::get('inbox')['category_aliases'] ?? []) as $alias => $canonical) {
            $alias = trim((string)$alias);
            $canonical = trim((string)$canonical);
            if ($alias === '' || $canonical === '') {
                continue;
            }

            if (strcasecmp($category, $alias) === 0) {
                return $canonical;
            }

            $prefix = $alias . ' - ';
            if (strncasecmp($category, $prefix, strlen($prefix)) === 0) {
                return $canonical . substr($category, strlen($alias));
            }
        }

        return $category;
    }

    /** Parse "a | b" lines into [a => b]. */
    public static function parse_pairs(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $out[sanitize_text_field($parts[0])] = sanitize_text_field($parts[1]);
            }
        }
        return $out;
    }

    public static function pairs_text(array $pairs): string
    {
        $lines = [];
        foreach ($pairs as $key => $value) {
            $lines[] = $key . ' | ' . $value;
        }
        return implode("\n", $lines);
    }
}
