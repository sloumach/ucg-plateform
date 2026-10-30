<?php

return [
    'resource_namespace' => env('OPS_RESOURCE_NAMESPACE'),
    'public_domain' => env('OPS_PUBLIC_DOMAIN'),
    'secrets_source' => env('OPS_SECRETS_SOURCE'),
    'data_classification' => env('OPS_DATA_CLASSIFICATION'),
    'monitoring' => [
        'queue_sample_limit' => 1000,
        'ready_wait_warning_seconds' => 300,
        'reminder_sweep_warning_seconds' => 1800,
    ],
    'background' => [
        'enabled' => env('OPS_BACKGROUND_MONITORING_ENABLED', false),
        'cache_store' => env('OPS_BACKGROUND_CACHE_STORE', 'redis'),
        'expected_queues' => ['critical', 'default', 'notifications', 'broadcasts', 'reports'],
        'heartbeat_warning_seconds' => 180,
        'work_warning_seconds' => 900,
        'sample_limit' => 1000,
    ],
    'api' => [
        'enabled' => env('OPS_API_TELEMETRY_ENABLED', false),
        'cache_store' => env('OPS_API_CACHE_STORE', 'redis'),
        'slow_ms' => 1000,
        'window_minutes' => 5,
        'minimum_samples' => 20,
        'error_percent' => 5,
        'slow_percent' => 10,
        'log_limit_per_minute' => 20,
    ],
    'alerts' => [
        'enabled' => env('OPS_ALERT_LEDGER_ENABLED', false),
        'escalation_seconds' => 1800,
    ],
];
