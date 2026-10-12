<?php
/**
 * Shared connection to the inbox database (separate from the WordPress database).
 *
 * wp-config.php:
 *   define('SAT_DB_HOST', 'localhost');
 *   define('SAT_DB_NAME', 'scout_payments');
 *   define('SAT_DB_USER', '...');
 *   define('SAT_DB_PASSWORD', '...');
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SAT_DB
{
    public const CONSTANTS = ['SAT_DB_HOST', 'SAT_DB_NAME', 'SAT_DB_USER', 'SAT_DB_PASSWORD'];

    public const TABLES = [
        'inbox_import_batches',
        'inbox_observations',
        'inbox_matches',
        'inbox_allocations',
        'inbox_reviews',
        'processor_state',
    ];

    private static ?PDO $pdo = null;

    public static function missing_constants(): array
    {
        $missing = [];
        foreach (self::CONSTANTS as $name) {
            if (!defined($name) || (string)constant($name) === '') {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $missing = self::missing_constants();
        if ($missing) {
            throw new RuntimeException('Missing wp-config.php setting: ' . implode(', ', $missing));
        }
        self::$pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', SAT_DB_HOST, SAT_DB_NAME),
            SAT_DB_USER,
            SAT_DB_PASSWORD,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return self::$pdo;
    }

    /** Tables the plugin needs that the database user cannot see. */
    public static function missing_tables(): array
    {
        $missing = [];
        foreach (self::TABLES as $table) {
            try {
                self::pdo()->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            } catch (Throwable $e) {
                $missing[] = $table;
            }
        }
        return $missing;
    }
}
