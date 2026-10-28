<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Reverse the newest dependent guards before exercising a historical partial schema rollback. */
    protected function withoutRetentionGuards(\Closure $roundTrip): void
    {
        $wrappers = require database_path('migrations/2026_10_28_083717_authorize_scoped_manual_erasure_guards.php');
        $freeze = require database_path('migrations/2026_10_28_080328_enforce_tenant_retention_business_freeze.php');
        $wrappers->down();
        try {
            $roundTrip();
        } finally {
            $freeze->down();
            $freeze->up();
            $wrappers->up();
        }
    }
}
