<?php

return [
    'enabled' => (bool) env('ANTIVIRUS_ENABLED', true),
    'driver' => env('ANTIVIRUS_DRIVER', 'clamav'),
    'fail_open' => (bool) env('ANTIVIRUS_FAIL_OPEN', false),
    'quarantine_prefix' => env('ANTIVIRUS_QUARANTINE_PREFIX', 'quarantine'),

    'clamav' => [
        'host' => env('ANTIVIRUS_CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('ANTIVIRUS_CLAMAV_PORT', 3310),
        'timeout' => (int) env('ANTIVIRUS_CLAMAV_TIMEOUT', 10),
    ],
];

