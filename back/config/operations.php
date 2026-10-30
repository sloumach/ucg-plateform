<?php

return [
    'resource_namespace' => env('OPS_RESOURCE_NAMESPACE'),
    'public_domain' => env('OPS_PUBLIC_DOMAIN'),
    'secrets_source' => env('OPS_SECRETS_SOURCE'),
    'data_classification' => env('OPS_DATA_CLASSIFICATION'),
    'monitoring' => [
        'queue_sample_limit' => 1000,
        'ready_wait_warning_seconds' => 300,
    ],
];
