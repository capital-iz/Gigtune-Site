<?php

return [
    'wordpress' => [
        'bridge_enabled' => env('GIGTUNE_WORDPRESS_BRIDGE_ENABLED', true),
        'base_url' => env('GIGTUNE_WORDPRESS_BASE_URL', 'http://127.0.0.1:8010'),
        'root' => env('GIGTUNE_WORDPRESS_ROOT', 'C:\\Users\\reama\\Local Sites\\gigtune\\app\\public'),
        'timeout_seconds' => (int) env('GIGTUNE_WORDPRESS_TIMEOUT', 180),
        'execution_mode' => env('GIGTUNE_WORDPRESS_EXECUTION_MODE', 'cgi'),
        'cgi_binary' => env('GIGTUNE_WORDPRESS_CGI_BINARY', 'php-cgi'),
        'database_connection' => env('GIGTUNE_WORDPRESS_DB_CONNECTION', 'wordpress'),
        'table_prefix' => env('GIGTUNE_WORDPRESS_TABLE_PREFIX', 'wpqx_'),
    ],
    'policy' => [
        'versions' => [
            'terms' => env('GIGTUNE_POLICY_VERSION_TERMS', 'v1.1'),
            'aup' => env('GIGTUNE_POLICY_VERSION_AUP', 'v1.1'),
            'privacy' => env('GIGTUNE_POLICY_VERSION_PRIVACY', 'v1.1'),
            'refund' => env('GIGTUNE_POLICY_VERSION_REFUND', 'v1.1'),
        ],
        'document_paths' => [
            'terms' => '/terms-and-conditions/',
            'aup' => '/acceptable-use-policy/',
            'privacy' => '/privacy-policy/',
            'refund' => '/return-policy/',
        ],
        'consent_path' => '/policy-consent/',
    ],
    'payments' => [
        'paystack' => [
            'mode' => env('GIGTUNE_PAYSTACK_MODE', 'test'),
            'public_key' => env('GIGTUNE_PAYSTACK_PUBLIC_KEY', ''),
            'secret_key' => env('GIGTUNE_PAYSTACK_SECRET_KEY', ''),
        ],
        'yoco' => [
            'mode' => env('GIGTUNE_YOCO_MODE', 'test'),
            'test_public_key' => env('GIGTUNE_YOCO_TEST_PUBLIC_KEY', ''),
            'test_secret_key' => env('GIGTUNE_YOCO_TEST_SECRET_KEY', ''),
            'live_public_key' => env('GIGTUNE_YOCO_LIVE_PUBLIC_KEY', ''),
            'live_secret_key' => env('GIGTUNE_YOCO_LIVE_SECRET_KEY', ''),
            'webhook_secret' => env('GIGTUNE_YOCO_WEBHOOK_SECRET', ''),
        ],
    ],
    'push' => [
        'enabled' => env('GIGTUNE_PUSH_ENABLED', true),
        'vapid_subject' => env('GIGTUNE_PUSH_VAPID_SUBJECT', ''),
        'vapid_public_key' => env('GIGTUNE_PUSH_VAPID_PUBLIC_KEY', ''),
        'vapid_private_key' => env('GIGTUNE_PUSH_VAPID_PRIVATE_KEY', ''),
    ],
];
