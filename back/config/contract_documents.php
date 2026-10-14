<?php

return [
    'clamav' => [
        'enabled' => filter_var(env('DOCUMENT_CLAMAV_ENABLED', false), FILTER_VALIDATE_BOOL),
        'host' => env('DOCUMENT_CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('DOCUMENT_CLAMAV_PORT', 3310),
        'connect_seconds' => (int) env('DOCUMENT_CLAMAV_CONNECT_SECONDS', 3),
        'operation_seconds' => (int) env('DOCUMENT_CLAMAV_OPERATION_SECONDS', 20),
        'max_database_age_seconds' => (int) env('DOCUMENT_CLAMAV_MAX_DATABASE_AGE_SECONDS', 604800),
    ],
];
