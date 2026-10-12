<?php
/**
 * One-time upgrade from the separate plugins used before 1.0.0
 * (Transaction Inbox, Transaction Inbox Tools, Gravity Forms Wave Sync, Receipt OCR).
 *
 * This is the only file that refers to the old option, role and capability names.
 * The old options are left in place so the old plugins could be reactivated; delete
 * them once 1.0.0 is working.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_Upgrade
{
    private const VERSION_OPTION = 'sat_version';

    private const OLD = [
        'wave' => 'gf_wave_settings',
        'tools' => 'to403_tools_settings',
        'ocr' => '403to_receipt_ocr_settings',
        'role' => '403to_treasurer',
        'cap' => 'review_403to_payments',
    ];

    /** True while any of the separate plugins this one replaces is still active. */
    public static function old_plugins_active(): bool
    {
        foreach (['TO403_Transaction_Inbox', 'TO403_Inbox_Tools', 'GF_Wave_Sync', 'GF_403TO_Receipt_OCR'] as $class) {
            if (class_exists($class, false)) {
                return true;
            }
        }
        return false;
    }

    public static function maybe_upgrade(): void
    {
        if (get_option(self::VERSION_OPTION) === SAT_VERSION) {
            return;
        }
        if (get_option(SAT_Settings::OPTION, null) === null) {
            self::import_old_settings();
        }
        self::migrate_roles();
        update_option(self::VERSION_OPTION, SAT_VERSION, false);
    }

    private static function import_old_settings(): void
    {
        $wave = get_option(self::OLD['wave'], []);
        $tools = get_option(self::OLD['tools'], []);
        $ocr = get_option(self::OLD['ocr'], []);
        $wave = is_array($wave) ? $wave : [];
        $tools = is_array($tools) ? $tools : [];
        $ocr = is_array($ocr) ? $ocr : [];
        $all = SAT_Settings::defaults();

        if ($wave) {
            foreach (array_keys($all['wave']) as $key) {
                if (isset($wave[$key])) {
                    $all['wave'][$key] = $wave[$key];
                }
            }
            $all['form']['form_id'] = (int)($wave['form_id'] ?? 0);
            foreach (array_keys(SAT_Settings::FORM_FIELDS) as $field) {
                $all['form'][$field] = (string)($wave['field_' . $field] ?? '');
            }
        }

        if ($tools) {
            $all['tools']['cutover'] = (array)($tools['cutover'] ?? []);
            $all['tools']['cron_log'] = (string)($tools['cron_log'] ?? '');
            $wave_names = self::wave_names_by_id($all['wave']['discovered_accounts']);
            $defaults = ['ofx_operating' => 'Operating Acct', 'ofx_cg_funds' => 'CG Funds', 'ofx_visa' => 'GC Visa'];
            foreach ((array)($tools['ofx_accounts'] ?? []) as $acctid => $a) {
                $source = (string)($a['source_system'] ?? '');
                $wave_id = (string)(($tools['wave_accounts'] ?? [])[$source] ?? '');
                $a['wave_account'] = $wave_names[$wave_id] ?? ($defaults[$source] ?? '');
                $all['accounts'][$acctid] = $a;
            }
        }

        if ($ocr) {
            foreach (array_keys($all['ocr']) as $key) {
                if (isset($ocr[$key])) {
                    $all['ocr'][$key] = (string)$ocr[$key];
                }
            }
            $all['ocr']['known_merchants'] = 'tshirtelephant | T-Shirt Elephant';
            if ((int)($ocr['form_id'] ?? 0) && (int)$ocr['form_id'] !== (int)$all['form']['form_id']) {
                update_option('sat_notice_ocr_form', (int)$ocr['form_id'], false);
            }
        }

        if ($wave || $tools) {
            $all['inbox']['uniform_account'] = 'Program income - uniforms';
            $all['inbox']['section_map'] = [
                'Beavers' => 'Program Income - Beavers',
                'Cubs' => 'Program Income - Cubs',
                'Sea Scouts' => 'Program Income - Sea Scouts',
                'Sea Venturers' => 'Program Income - Sea Venturers',
                'Venturers' => 'Program Income - Sea Venturers',
            ];
        }

        update_option(SAT_Settings::OPTION, $all, false);
    }

    private static function wave_names_by_id(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $a) {
            if (!empty($a['id'])) {
                $out[(string)$a['id']] = (string)($a['name'] ?? '');
            }
        }
        return $out;
    }

    /** Give the new role and capability to everyone who had the old ones, then remove the old ones. */
    private static function migrate_roles(): void
    {
        if (!get_role('sat_treasurer')) {
            add_role('sat_treasurer', 'Treasurer (Scout Accounting)', ['read' => true, SAT_CAP_REVIEW => true]);
        }
        foreach (wp_roles()->role_objects as $role) {
            if ($role->name === 'administrator' || $role->has_cap(self::OLD['cap'])) {
                $role->add_cap(SAT_CAP_REVIEW);
            }
            if ($role->has_cap(self::OLD['cap'])) {
                $role->remove_cap(self::OLD['cap']);
            }
        }
        if (get_role(self::OLD['role'])) {
            foreach (get_users(['role' => self::OLD['role']]) as $user) {
                $user->add_role('sat_treasurer');
                $user->remove_role(self::OLD['role']);
            }
            remove_role(self::OLD['role']);
        }
    }
}
