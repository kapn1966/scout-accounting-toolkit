<?php

return [

    'database' => [
        'host'     => 'localhost',
        'name'     => 'scout_accounting',
        'user'     => '',
        'password' => '',
    ],

    'imap' => [
        'host'     => '',
        'port'     => 993,
        'user'     => '',
        'password' => '',
    ],

    'logging' => [
        'directory' => '/var/log/scout-accounting',
    ],

    'ofx' => [

        'accounts' => [

            '952161CHEQCAD2' => [
                'account_key' => 'operating',
                'account_name' => 'Operating',
                'source_system' => 'ofx_operating',
            ],

            '952161CHEQCAD1' => [
                'account_key' => 'restricted_funds',
                'account_name' => 'CG Funds',
                'source_system' => 'ofx_cg_funds',
            ],

        ],
    ],

];