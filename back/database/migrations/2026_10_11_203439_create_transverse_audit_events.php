<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('IAM-006 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        foreach (['identity_audit_events', 'organization_audit_events'] as $table) {
            Schema::create($table, function (Blueprint $schema) use ($table): void {
                $schema->uuid('id')->primary();
                if ($table === 'organization_audit_events') {
                    $schema->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
                }
                $schema->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $schema->enum('action', ['journal.viewed', 'access.denied', 'domain.changed']);
                $schema->enum('target_type', ['journal', 'route', 'organization', 'account', 'membership', 'invitation', 'role', 'role_assignment', 'support_access']);
                $schema->string('target_id', 128);
                $schema->enum('reason_code', ['authentication_event', 'governance_change', 'support_operation', 'authorized_review', 'authorization_refused', 'domain_change']);
                $schema->json('before');
                $schema->json('after');
                $schema->uuid('request_id');
                $schema->string('ip_address', 45)->nullable();
                $schema->timestampTz('occurred_at');
                $prefix = $table === 'organization_audit_events' ? ['organization_id'] : [];
                $schema->index([...$prefix, 'occurred_at', 'id'], $table.'_timeline');
                $schema->index([...$prefix, 'actor_user_id', 'occurred_at', 'id'], $table.'_actor');
            });
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_append_only() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Audit is append-only' USING ERRCODE='23514'; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_append_only()");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_state_shape CHECK (jsonb_typeof(\"before\"::jsonb)='object' AND jsonb_typeof(\"after\"::jsonb)='object' AND octet_length(\"before\"::text)<=4096 AND octet_length(\"after\"::text)<=4096)");
            } else {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_append_only_{$operation} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT,'Audit is append-only'); END");
                }
                DB::unprepared("CREATE TRIGGER {$table}_state_shape BEFORE INSERT ON {$table} WHEN NOT(json_valid(NEW.\"before\") AND json_valid(NEW.\"after\") AND json_type(NEW.\"before\")='object' AND json_type(NEW.\"after\")='object' AND length(CAST(NEW.\"before\" AS BLOB))<=4096 AND length(CAST(NEW.\"after\" AS BLOB))<=4096) BEGIN SELECT RAISE(ABORT,'Invalid audit state'); END");
            }
        }
        Schema::table('identity_security_events', fn (Blueprint $table) => $table->index(['occurred_at', 'id'], 'identity_security_events_timeline'));
        Schema::table('platform_role_events', fn (Blueprint $table) => $table->index(['occurred_at', 'id'], 'platform_role_events_timeline'));
        Schema::table('organization_support_events', fn (Blueprint $table) => $table->index(['organization_id', 'occurred_at', 'id'], 'organization_support_events_timeline'));
    }

    public function down(): void
    {
        foreach (['identity_audit_events', 'organization_audit_events'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('IAM-006 rollback cannot discard audited events. Use a forward fix.');
            }
        }
        Schema::table('identity_security_events', fn (Blueprint $table) => $table->dropIndex('identity_security_events_timeline'));
        Schema::table('platform_role_events', fn (Blueprint $table) => $table->dropIndex('platform_role_events_timeline'));
        Schema::table('organization_support_events', fn (Blueprint $table) => $table->dropIndex('organization_support_events_timeline'));
        foreach (['organization_audit_events', 'identity_audit_events'] as $table) {
            Schema::dropIfExists($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION IF EXISTS ucg_{$table}_append_only()");
            }
        }
    }
};
