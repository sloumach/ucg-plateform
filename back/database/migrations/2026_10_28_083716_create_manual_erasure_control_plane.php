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
            throw new LogicException('Manual erasure requires PostgreSQL or SQLite.');
        }
        Schema::create('tenant_erasure_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->enum('scope', ['person', 'organization']);
            $table->uuid('target_id');
            $table->string('reference', 120);
            $table->char('source_digest', 64);
            $table->char('schema_digest', 64);
            $table->json('manifest');
            $table->uuid('source_intent_id')->nullable();
            $table->uuid('restore_epoch')->nullable();
            $table->enum('status', ['erasing', 'awaiting_copies', 'completed']);
            $table->json('progress');
            $table->json('copy_receipts');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'tenant_erasure_identity');
            $table->unique(['organization_id', 'request_id'], 'tenant_erasure_request');
            $table->unique(['organization_id', 'restore_epoch', 'source_intent_id'], 'tenant_erasure_replay');
            $table->index(['organization_id', 'status', 'id'], 'tenant_erasure_pending');
        });
        Schema::create('tenant_erasure_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('operation_id');
            $table->foreign(['organization_id', 'operation_id'])->references(['organization_id', 'id'])->on('tenant_erasure_operations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->unsignedInteger('version');
            $table->string('action', 40);
            $table->json('evidence');
            $table->timestampTz('occurred_at');
            $table->unique(['organization_id', 'operation_id', 'version'], 'tenant_erasure_event_sequence');
        });
        Schema::create('tenant_erasure_permits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('operation_id');
            $table->foreign(['organization_id', 'operation_id'])->references(['organization_id', 'id'])->on('tenant_erasure_operations')->restrictOnDelete();
            $table->string('table_name', 100);
            $table->string('key_column', 80);
            $table->string('row_key', 160);
            $table->json('expected')->nullable();
            $table->string('transaction_id', 40);
            $table->unsignedInteger('backend_id');
            $table->unsignedInteger('control_version');
            $table->index(['organization_id', 'operation_id'], 'tenant_erasure_permit_scope');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION ucg_erasure_audit_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_TABLE_NAME = 'tenant_erasure_events' OR TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Erasure audit is immutable' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.actor_user_id IS DISTINCT FROM OLD.actor_user_id OR NEW.request_id IS DISTINCT FROM OLD.request_id
                        OR NEW.scope IS DISTINCT FROM OLD.scope OR NEW.target_id IS DISTINCT FROM OLD.target_id
                        OR NEW.reference IS DISTINCT FROM OLD.reference OR NEW.source_digest IS DISTINCT FROM OLD.source_digest
                        OR NEW.schema_digest IS DISTINCT FROM OLD.schema_digest OR NEW.manifest::text IS DISTINCT FROM OLD.manifest::text
                        OR NEW.source_intent_id IS DISTINCT FROM OLD.source_intent_id OR NEW.restore_epoch IS DISTINCT FROM OLD.restore_epoch
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.version <> OLD.version + 1 OR OLD.status = 'completed' THEN
                        RAISE EXCEPTION 'Erasure audit is immutable' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER erasure_operation_guard BEFORE UPDATE OR DELETE ON tenant_erasure_operations FOR EACH ROW EXECUTE FUNCTION ucg_erasure_audit_guard();
                CREATE TRIGGER erasure_event_guard BEFORE UPDATE OR DELETE ON tenant_erasure_events FOR EACH ROW EXECUTE FUNCTION ucg_erasure_audit_guard();
                SQL);
        } else {
            DB::unprepared("CREATE TRIGGER erasure_operation_delete_guard BEFORE DELETE ON tenant_erasure_operations BEGIN SELECT RAISE(ABORT, 'Erasure audit is immutable'); END;");
            DB::unprepared("CREATE TRIGGER erasure_operation_update_guard BEFORE UPDATE ON tenant_erasure_operations WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.actor_user_id IS NOT OLD.actor_user_id OR NEW.request_id IS NOT OLD.request_id OR NEW.scope IS NOT OLD.scope OR NEW.target_id IS NOT OLD.target_id OR NEW.reference IS NOT OLD.reference OR NEW.source_digest IS NOT OLD.source_digest OR NEW.schema_digest IS NOT OLD.schema_digest OR NEW.manifest IS NOT OLD.manifest OR NEW.source_intent_id IS NOT OLD.source_intent_id OR NEW.restore_epoch IS NOT OLD.restore_epoch OR NEW.created_at IS NOT OLD.created_at OR NEW.version <> OLD.version + 1 OR OLD.status = 'completed' BEGIN SELECT RAISE(ABORT, 'Erasure audit is immutable'); END;");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER erasure_event_{$operation}_guard BEFORE {$operation} ON tenant_erasure_events BEGIN SELECT RAISE(ABORT, 'Erasure audit is immutable'); END;");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('tenant_erasure_operations')->exists()) {
            throw new LogicException('Erasure decisions cannot be discarded by rollback.');
        }
        Schema::dropIfExists('tenant_erasure_permits');
        Schema::dropIfExists('tenant_erasure_events');
        Schema::dropIfExists('tenant_erasure_operations');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ucg_erasure_audit_guard()');
        }
    }
};
