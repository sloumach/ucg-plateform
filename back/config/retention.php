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
];
