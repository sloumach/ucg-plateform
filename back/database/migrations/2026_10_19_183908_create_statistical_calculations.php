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
            throw new RuntimeException('STAT-004 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::create('statistical_calculations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('metric_id')->constrained('metric_definitions')->restrictOnDelete();
            $table->foreignUuid('metric_revision_id')->constrained('metric_revisions')->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->string('status', 12);
            $table->uuid('version');
            $table->unsignedSmallInteger('source_count');
            $table->text('definition');
            $table->string('failure_code', 40)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'sc_tenant');
            $table->unique(['organization_id', 'actor_user_id', 'fingerprint'], 'sc_fingerprint');
            $table->index(['organization_id', 'actor_user_id', 'id'], 'sc_actor_page');
        });
        Schema::create('statistical_calculation_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('calculation_id');
            $table->uuid('observation_id');
            $table->foreign(['organization_id', 'calculation_id'], 'scs_calculation')->references(['organization_id', 'id'])->on('statistical_calculations')->restrictOnDelete();
            $table->foreign(['organization_id', 'observation_id'], 'scs_observation')->references(['organization_id', 'id'])->on('statistical_observations')->restrictOnDelete();
            $table->unique(['organization_id', 'calculation_id', 'observation_id'], 'scs_source');
            $table->index(['organization_id', 'observation_id', 'calculation_id'], 'scs_invalidation');
        });
        Schema::create('statistical_calculation_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('calculation_id');
            $table->unsignedSmallInteger('number');
            $table->uuid('generation');
            $table->string('engine_version', 32);
            $table->text('result');
            $table->text('source_versions');
            $table->uuid('request_id');
            $table->timestampTz('created_at');
            $table->foreign(['organization_id', 'calculation_id'], 'scr_calculation')->references(['organization_id', 'id'])->on('statistical_calculations')->restrictOnDelete();
            $table->unique(['organization_id', 'calculation_id', 'number'], 'scr_number');
            $table->unique(['organization_id', 'calculation_id', 'generation'], 'scr_generation');
        });
        $shape = "NEW.source_count<1 OR NEW.source_count>1000 OR NEW.status NOT IN ('queued','stale','completed','failed') OR NOT EXISTS(SELECT 1 FROM metric_revisions WHERE id=NEW.metric_revision_id AND metric_id=NEW.metric_id)";
        $identity = 'NEW.id<>OLD.id OR NEW.organization_id<>OLD.organization_id OR NEW.actor_user_id<>OLD.actor_user_id OR NEW.metric_id<>OLD.metric_id OR NEW.metric_revision_id<>OLD.metric_revision_id OR NEW.source_count<>OLD.source_count OR NEW.definition<>OLD.definition OR NEW.fingerprint<>OLD.fingerprint';
        $revision = 'NEW.number<1 OR NEW.number>1000 OR NEW.number<>(SELECT COALESCE(MAX(number),0)+1 FROM statistical_calculation_revisions WHERE organization_id=NEW.organization_id AND calculation_id=NEW.calculation_id)';
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION sc_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain calculation'; END IF; IF TG_OP='UPDATE' AND ({$identity}) THEN RAISE EXCEPTION 'Retain calculation identity'; END IF; IF {$shape} THEN RAISE EXCEPTION 'Invalid calculation'; END IF; RETURN NEW; END; $$;
                CREATE TRIGGER sc_guard BEFORE INSERT OR UPDATE OR DELETE ON statistical_calculations FOR EACH ROW EXECUTE FUNCTION sc_guard();
                CREATE FUNCTION scr_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Retain calculation history'; END IF; PERFORM 1 FROM statistical_calculations WHERE id=NEW.calculation_id AND organization_id=NEW.organization_id FOR UPDATE; IF {$revision} THEN RAISE EXCEPTION 'Invalid calculation revision'; END IF; RETURN NEW; END; $$;
                CREATE TRIGGER scr_guard BEFORE INSERT OR UPDATE OR DELETE ON statistical_calculation_revisions FOR EACH ROW EXECUTE FUNCTION scr_guard();
                CREATE TRIGGER scs_immutable BEFORE UPDATE OR DELETE ON statistical_calculation_sources FOR EACH ROW EXECUTE FUNCTION ucg_stat_import_immutable()");
        } else {
            foreach (['INSERT', 'UPDATE'] as $action) {
                DB::unprepared('CREATE TRIGGER sc_'.strtolower($action)." BEFORE {$action} ON statistical_calculations WHEN {$shape} BEGIN SELECT RAISE(ABORT,'Invalid calculation'); END");
            }
            DB::unprepared("CREATE TRIGGER sc_identity BEFORE UPDATE ON statistical_calculations WHEN {$identity} BEGIN SELECT RAISE(ABORT,'Retain calculation identity'); END");
            DB::unprepared("CREATE TRIGGER sc_delete BEFORE DELETE ON statistical_calculations BEGIN SELECT RAISE(ABORT,'Retain calculation'); END");
            DB::unprepared("CREATE TRIGGER scr_insert BEFORE INSERT ON statistical_calculation_revisions WHEN {$revision} BEGIN SELECT RAISE(ABORT,'Invalid calculation revision'); END");
            foreach (['statistical_calculation_sources', 'statistical_calculation_revisions'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $action) {
                    DB::unprepared('CREATE TRIGGER '.$table.'_'.strtolower($action)." BEFORE {$action} ON {$table} BEGIN SELECT RAISE(ABORT,'Retain calculation history'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['statistical_calculations', 'statistical_calculation_sources', 'statistical_calculation_revisions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('STAT-004 refuses to discard statistical calculation history.');
            }
        }
        Schema::dropIfExists('statistical_calculation_revisions');
        Schema::dropIfExists('statistical_calculation_sources');
        Schema::dropIfExists('statistical_calculations');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS scr_guard()');
            DB::statement('DROP FUNCTION IF EXISTS sc_guard()');
        }
    }
};
