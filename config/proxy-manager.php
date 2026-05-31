<?php

return [
    'check' => [
        'url' => env('PROXY_CHECK_URL', 'https://example.com/'),
        'interval_minutes' => (int) env('PROXY_CHECK_INTERVAL_MINUTES', 5),
        'timeout_seconds' => (int) env('PROXY_CHECK_TIMEOUT_SECONDS', 8),
        'connect_timeout_seconds' => (int) env('PROXY_CHECK_CONNECT_TIMEOUT_SECONDS', 3),
        'success_status_codes' => array_map('intval', explode(',', env('PROXY_CHECK_SUCCESS_CODES', '200,204,301,302'))),
        'stale_after_seconds' => (int) env('PROXY_CHECK_STALE_AFTER_SECONDS', 120),
        'queue' => env('PROXY_CHECK_QUEUE', 'proxy-checks'),
        'unique_for_seconds' => (int) env('PROXY_CHECK_UNIQUE_FOR_SECONDS', 300),
    ],
];
