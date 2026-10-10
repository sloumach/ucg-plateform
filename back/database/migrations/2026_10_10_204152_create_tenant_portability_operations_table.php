<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new LogicException('Portability requires PostgreSQL or SQLite.');
        }
        Schema::create('tenant_portability_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->enum('kind', ['export', 'purge', 'recovery']);
            $table->enum('status', ['pending', 'ready', 'expired', 'failed', 'completed']);
            $table->string('reason', 1000);
            $table->string('disk', 100)->nullable();
            $table->bigInteger('bytes')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->json('details');
            $table->timestampsTz();
            $table->index(['organization_id', 'id']);
            $table->index(['kind', 'status', 'expires_at', 'id'], 'portability_expiry_index');
        });
        $columns = ['id', 'organization_id', 'actor_user_id', 'request_id', 'kind', 'reason', 'disk', 'expires_at', 'created_at'];
        if (DB::getDriverName() === 'pgsql') {
            $changes = implode(' OR ', array_map(fn ($column) => "NEW.{$column} IS DISTINCT FROM OLD.{$column}", $columns));
            DB::unprepared("CREATE FUNCTION ucg_portability_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN IF {$changes} THEN RAISE EXCEPTION 'Portability identity is immutable' USING ERRCODE = '23514'; END IF; RETURN NEW; END; $$");
            DB::unprepared('CREATE TRIGGER portability_identity_guard BEFORE UPDATE ON tenant_portability_operations FOR EACH ROW EXECUTE FUNCTION ucg_portability_identity_guard()');
            DB::unprepared('CREATE TRIGGER portability_delete_guard BEFORE DELETE ON tenant_portability_operations FOR EACH ROW EXECUTE FUNCTION ucg_organization_audit_guard()');
            DB::statement('ALTER TABLE tenant_portability_operations ADD CONSTRAINT portability_bytes_nonnegative CHECK (bytes >= 0)');
        } else {
            $changes = implode(' OR ', array_map(fn ($column) => "NEW.{$column} IS NOT OLD.{$column}", $columns));
            DB::unprepared("CREATE TRIGGER portability_identity_guard BEFORE UPDATE ON tenant_portability_operations WHEN {$changes} BEGIN SELECT RAISE(ABORT, 'Portability identity is immutable'); END");
            DB::unprepared("CREATE TRIGGER portability_delete_guard BEFORE DELETE ON tenant_portability_operations BEGIN SELECT RAISE(ABORT, 'Portability history is immutable'); END");
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared("CREATE TRIGGER portability_bytes_{$event} BEFORE {$event} ON tenant_portability_operations WHEN NEW.bytes < 0 BEGIN SELECT RAISE(ABORT, 'Negative portability size'); END");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_portability_operations');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ucg_portability_identity_guard()');
        }
    }
};
