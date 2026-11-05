<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /** Reverse the newest dependent guards before exercising a historical partial schema rollback. */
    protected function withoutRetentionGuards(\Closure $roundTrip): void
    {
        $contexts = require database_path('migrations/2026_11_05_123111_create_communication_contexts_table.php');
        $wrappers = require database_path('migrations/2026_10_28_083717_authorize_scoped_manual_erasure_guards.php');
        $freeze = require database_path('migrations/2026_10_28_080328_enforce_tenant_retention_business_freeze.php');
        $contexts->down();
        $wrappers->down();
        try {
            $roundTrip(static function () use ($contexts, $wrappers): void {
                if (! Schema::hasTable('communication_contexts')) {
                    $wrappers->up();
                    $contexts->up();
                }
            });
        } finally {
            if (Schema::hasTable('communication_contexts')) {
                $contexts->down();
            }
            if (Schema::hasTable('retention_guard_definitions')) {
                $wrappers->down();
            }
            $freeze->down();
            $freeze->up();
            $wrappers->up();
            $contexts->up();
        }
    }
}
