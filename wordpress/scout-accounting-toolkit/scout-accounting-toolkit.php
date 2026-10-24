<?php
/**
 * Plugin Name: Scout Accounting Toolkit
 * Description: Transaction inbox for Scout groups: OFX bank and card statements, Interac e-Transfer evidence, Gravity Forms expense claims with receipt OCR, matching, categories and Wave Accounting integration.
 * Version: 1.6.5
 * Author: Scout Accounting Toolkit contributors
 * License: GPL-3.0-or-later
 * Requires at least: 7.1.3
 * Requires PHP: 8.1
 * Text Domain: scout-accounting-toolkit
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SAT_VERSION', '1.6.5');
define('SAT_FILE', __FILE__);
define('SAT_DIR', __DIR__);
define('SAT_URL', plugin_dir_url(__FILE__));

/** Capability for reviewing the inbox. Administrators always have it. */
define('SAT_CAP_REVIEW', 'sat_review');

require_once SAT_DIR . '/includes/class-sat-settings.php';
require_once SAT_DIR . '/includes/class-sat-db.php';
require_once SAT_DIR . '/includes/class-sat-upgrade.php';
require_once SAT_DIR . '/includes/class-sat-wave.php';
require_once SAT_DIR . '/includes/class-sat-wave-export.php';
require_once SAT_DIR . '/includes/class-sat-ofx-writer.php';
require_once SAT_DIR . '/includes/class-sat-expense-form.php';
require_once SAT_DIR . '/includes/class-sat-claim-corrections.php';
require_once SAT_DIR . '/includes/class-sat-expense-report.php';
require_once SAT_DIR . '/includes/class-sat-income-view.php';
require_once SAT_DIR . '/includes/class-sat-receipt-ocr.php';
require_once SAT_DIR . '/includes/class-sat-ofx-parser.php';
require_once SAT_DIR . '/includes/class-sat-inbox.php';
require_once SAT_DIR . '/includes/class-sat-transfer-ofx.php';
require_once SAT_DIR . '/includes/class-sat-tools.php';
require_once SAT_DIR . '/includes/class-sat-admin.php';

final class SAT_Plugin
{
    public static function boot(): void
    {
        if (SAT_Upgrade::old_plugins_active()) {
            add_action('admin_notices', [self::class, 'old_plugins_notice']);
            return;
        }

        SAT_Upgrade::maybe_upgrade();

        SAT_Wave::instance();
        SAT_Wave_Export::instance();
        SAT_Expense_Form::instance();
        SAT_Claim_Corrections::instance();
        SAT_Expense_Report::instance();
        SAT_Receipt_OCR::instance();
        SAT_Inbox::instance();
        SAT_Transfer_OFX::instance();
        SAT_Tools::instance();
        if (is_admin()) {
            SAT_Admin::instance();
        }
    }

    public static function activate(): void
    {
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap(SAT_CAP_REVIEW);
        }
        if (!get_role('sat_treasurer')) {
            add_role('sat_treasurer', 'Treasurer (Scout Accounting)', ['read' => true, SAT_CAP_REVIEW => true]);
        }
        SAT_Upgrade::maybe_upgrade();
    }

    public static function old_plugins_notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>Scout Accounting Toolkit is paused.</strong> ';
        echo 'Deactivate the separate Transaction Inbox, Transaction Inbox Tools, Gravity Forms Wave Sync and Receipt OCR plugins. ';
        echo 'This plugin replaces all four and carries their settings across.</p></div>';
    }
}

register_activation_hook(__FILE__, [SAT_Plugin::class, 'activate']);
add_action('plugins_loaded', [SAT_Plugin::class, 'boot']);
