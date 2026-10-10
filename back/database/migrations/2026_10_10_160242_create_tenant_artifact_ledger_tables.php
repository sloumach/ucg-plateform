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
            throw new LogicException('Tenant artifact accounting requires PostgreSQL or SQLite.');
        }
        Schema::create('tenant_storage_usage', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->primary()->constrained('organizations')->restrictOnDelete()->restrictOnUpdate();
            $table->bigInteger('bytes')->default(0);
        });
        Schema::create('tenant_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete()->restrictOnUpdate();
            $table->string('name', 255);
            $table->string('disk', 100);
            $table->bigInteger('bytes');
            $table->boolean('ready')->default(false);
            $table->timestampsTz();
            $table->index(['organization_id', 'id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_ten005_immutable_organization() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN RAISE EXCEPTION 'Artifact organization is immutable' USING ERRCODE = '23514'; END IF; RETURN NEW; END; $$");
            DB::statement('ALTER TABLE tenant_storage_usage ADD CONSTRAINT tenant_storage_bytes_nonnegative CHECK (bytes >= 0)');
            DB::statement('ALTER TABLE tenant_artifacts ADD CONSTRAINT tenant_artifact_bytes_nonnegative CHECK (bytes >= 0)');
            foreach (['tenant_storage_usage', 'tenant_artifacts'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_organization BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_ten005_immutable_organization()");
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['tenant_storage_usage', 'tenant_artifacts'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_organization BEFORE UPDATE OF organization_id ON {$table} WHEN NEW.organization_id IS NOT OLD.organization_id BEGIN SELECT RAISE(ABORT, 'Artifact organization is immutable'); END");
                foreach (['INSERT', 'UPDATE'] as $event) {
                    $trigger = $table.'_bytes_'.strtolower($event);
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$event} ON {$table} WHEN NEW.bytes < 0 BEGIN SELECT RAISE(ABORT, 'Negative resource usage is forbidden'); END");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_artifacts');
        Schema::dropIfExists('tenant_storage_usage');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ucg_ten005_immutable_organization()');
        }
    }
};
