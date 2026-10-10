<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_lifecycle_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('action', ['created', 'status_changed']);
            $table->enum('from_status', ['active', 'suspended', 'closing', 'archived'])->nullable();
            $table->enum('to_status', ['active', 'suspended', 'closing', 'archived']);
            $table->string('reason', 1000);
            $table->uuid('request_id');
            $table->timestampTz('occurred_at');
            $table->index(['organization_id', 'occurred_at', 'id'], 'organization_lifecycle_timeline_index');
            $table->index('actor_user_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION ucg_organization_audit_guard() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Organization audit is immutable' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER organization_audit_guard BEFORE UPDATE OR DELETE ON organization_lifecycle_events
                    FOR EACH ROW EXECUTE FUNCTION ucg_organization_audit_guard();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER organization_audit_guard_{$operation} BEFORE {$operation} ON organization_lifecycle_events BEGIN SELECT RAISE(ABORT, 'Organization audit is immutable'); END;");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_lifecycle_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ucg_organization_audit_guard()');
        }
    }
};
