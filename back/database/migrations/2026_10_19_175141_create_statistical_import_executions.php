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
            throw new RuntimeException('STAT-003B requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('statistical_import_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('import_id');
            $table->foreign(['organization_id', 'import_id'])->references(['organization_id', 'id'])->on('statistical_imports')->restrictOnDelete();
            $table->unique(['organization_id', 'import_id'], 'sie_import');
            $table->string('status', 12);
            $table->uuid('version');
            $table->unsignedSmallInteger('processed_count')->default(0);
            $table->text('results');
            $table->string('failure_code', 40)->nullable();
            $table->timestampsTz();
        });
        Schema::create('statistical_import_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('import_id');
            $table->uuid('observation_id');
            $table->string('identity_key', 64);
            $table->string('payload_hash', 64);
            $table->foreign(['organization_id', 'import_id'])->references(['organization_id', 'id'])->on('statistical_imports')->restrictOnDelete();
            $table->foreign(['organization_id', 'observation_id'])->references(['organization_id', 'id'])->on('statistical_observations')->restrictOnDelete();
            $table->unique(['organization_id', 'identity_key'], 'sir_identity');
            $table->timestampTz('created_at');
        });
        $condition = "NEW.status NOT IN ('queued','completed','failed') OR NEW.processed_count<0 OR NEW.processed_count>100 OR NEW.processed_count>(SELECT row_count FROM statistical_imports WHERE id=NEW.import_id AND organization_id=NEW.organization_id)";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION sie_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain import execution'; END IF; IF TG_OP='UPDATE' AND (NEW.id<>OLD.id OR NEW.organization_id<>OLD.organization_id OR NEW.import_id<>OLD.import_id OR NEW.processed_count<OLD.processed_count) THEN RAISE EXCEPTION 'Retain import execution identity'; END IF; IF {$condition} THEN RAISE EXCEPTION 'Invalid import execution'; END IF; RETURN NEW; END; $$;
                CREATE TRIGGER sie_guard BEFORE INSERT OR UPDATE OR DELETE ON statistical_import_executions FOR EACH ROW EXECUTE FUNCTION sie_guard();
                CREATE TRIGGER sir_immutable BEFORE UPDATE OR DELETE ON statistical_import_receipts FOR EACH ROW EXECUTE FUNCTION ucg_stat_import_immutable()");
        } else {
            foreach (['INSERT', 'UPDATE'] as $action) {
                DB::unprepared('CREATE TRIGGER sie_'.strtolower($action)." BEFORE {$action} ON statistical_import_executions WHEN {$condition} BEGIN SELECT RAISE(ABORT,'Invalid import execution'); END");
            }
            DB::unprepared("CREATE TRIGGER sie_identity BEFORE UPDATE ON statistical_import_executions WHEN NEW.id<>OLD.id OR NEW.organization_id<>OLD.organization_id OR NEW.import_id<>OLD.import_id OR NEW.processed_count<OLD.processed_count BEGIN SELECT RAISE(ABORT,'Retain import execution identity'); END");
            DB::unprepared("CREATE TRIGGER sie_delete BEFORE DELETE ON statistical_import_executions BEGIN SELECT RAISE(ABORT,'Retain import execution'); END");
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared('CREATE TRIGGER sir_'.strtolower($action)." BEFORE {$action} ON statistical_import_receipts BEGIN SELECT RAISE(ABORT,'Retain import receipts'); END");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['statistical_import_executions', 'statistical_import_receipts'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('STAT-003B rollback refuses to discard import history.');
            }
        }
        Schema::dropIfExists('statistical_import_receipts');
        Schema::dropIfExists('statistical_import_executions');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS sie_guard()');
        }
    }
};
