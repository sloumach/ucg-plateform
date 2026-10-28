<?php

return [
    // Server operators explicitly scoped by actor ID => tenant UUID list. No implicit owner access.
    'review_operators' => [],
    // Reviewed, versioned configuration by tenant UUID; no business duration is inferred.
    'review_policies' => [],
    // Explicit operational write/access scope, separate from preview operators. Disabled by default.
    'control_enabled' => false,
    'control_operators' => [],
    // Manually reviewed restoration receipts, bound to tenant, quarantine, version and policy hash.
    'restoration_approvals' => [],
    'erasure' => [
        'enabled' => false,
        'operators' => [],
        // Keep this connection, installation identifier and independent key outside source backups.
        'journal_connection' => env('RETENTION_JOURNAL_CONNECTION'),
        'installation_id' => env('RETENTION_INSTALLATION_ID'),
        'journal_key' => env('RETENTION_JOURNAL_KEY'),
        'journal_independence_reviewed' => false,
        'restore_mode' => env('RETENTION_RESTORE_MODE', false),
        'restore_epoch' => env('RETENTION_RESTORE_EPOCH'),
    ],
];
