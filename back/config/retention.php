<?php

return [
    // Server operators explicitly scoped by actor ID => tenant UUID list. No implicit owner access.
    'review_operators' => [],
    // Reviewed, versioned configuration by tenant UUID; no business duration is inferred.
    'review_policies' => [],
];
