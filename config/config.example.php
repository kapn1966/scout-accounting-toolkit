<?php
/**
 * Command-line configuration (Interac importer and weekly report).
 * Copy to config/config.php and fill in. config.php is ignored by git: never commit it.
 * The WordPress plugin is configured separately in wp-config.php and Settings → Scout Accounting.
 */

return [
    'database' => [
        'host' => 'localhost',
        'name' => 'scout_payments',
        'user' => '',
        'password' => '',
    ],

    'imap' => [
        'host' => '',
        'port' => 993,
        'user' => '',
        'password' => '',
    ],

    'interac' => [
        // Routing section used for direct deposits that have no [Section income] subject prefix.
        'default_section' => 'Admin',
        // Subject prefixes that should be recorded under another section name.
        'section_aliases' => [
            // 'Venturers' => 'Sea Venturers',
        ],
    ],

    'report' => [
        'to' => '',
        'from' => '',
        'subject' => 'Scout Accounting weekly Interac report',
        // Sections expected in subject prefixes; anything else is reported as unknown.
        'known_sections' => ['Beavers', 'Cubs', 'Scouts', 'Venturers', 'Rovers', 'Admin'],
        // Display labels, e.g. 'Admin' => 'Admin / non-section'.
        'section_labels' => [
            'Admin' => 'Admin / non-section',
        ],
    ],
];
