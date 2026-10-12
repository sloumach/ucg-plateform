<?php

return [
    // Additional per-file ceilings; tenant quotas can only make them stricter.
    'max_bytes' => 1048576,
    'max_rows' => 1000,
    'max_columns' => 32,
    'chunk_size' => 25,
];
