<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Metric dictionary requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::create('metric_catalogue_state', function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            $t->uuid('version');
            $t->unsignedBigInteger('number');
        });
        DB::table('metric_catalogue_state')->insert(['id' => 1, 'version' => (string) Str::uuid(), 'number' => 0]);
        Schema::create('metric_definitions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('game_id');
            $t->string('code', 64);
            $t->unsignedBigInteger('created_by');
            $t->timestampTz('created_at');
            $t->foreign('game_id')->references('id')->on('game_catalogue_entries')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['game_id', 'code'], 'metric_code');
        });
        Schema::create('metric_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('metric_id');
            $t->unsignedSmallInteger('number');
            $t->unsignedBigInteger('catalogue_number')->unique();
            $t->uuid('mode_id')->nullable();
            $t->uuid('role_id')->nullable();
            $t->uuid('release_id')->nullable();
            $t->text('scope');
            $t->string('title', 160);
            $t->text('definition');
            $t->string('unit', 40);
            $t->string('aggregation', 20);
            $t->text('formula');
            $t->string('zero_denominator', 20);
            $t->string('measurement_kind', 16);
            $t->string('availability', 20);
            $t->text('availability_note');
            $t->string('status', 12);
            $t->text('reason');
            $t->unsignedBigInteger('actor_user_id');
            $t->uuid('request_id');
            $t->timestampTz('created_at');
            $t->foreign('metric_id')->references('id')->on('metric_definitions')->restrictOnDelete();
            foreach (['mode_id', 'role_id', 'release_id'] as $c) {
                $t->foreign($c)->references('id')->on('game_catalogue_entries')->restrictOnDelete();
            }
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['metric_id', 'number'], 'metric_sequence');
            $t->index(['status', 'metric_id', 'number'], 'metric_status');
        });
        $identity = "(SELECT COUNT(*) FROM metric_definitions)>=10000 OR NOT EXISTS(SELECT 1 FROM game_catalogue_entries g WHERE g.id=NEW.game_id AND g.kind='game')";
        $revision = "NEW.number<1 OR NEW.number>1000 OR NEW.number<>(SELECT COALESCE(MAX(r.number),0)+1 FROM metric_revisions r WHERE r.metric_id=NEW.metric_id) OR NEW.catalogue_number<>(SELECT number+1 FROM metric_catalogue_state WHERE id=1) OR NEW.status NOT IN ('active','retired') OR NEW.aggregation NOT IN ('sum','mean','min','max','last','ratio','manual') OR NEW.zero_denominator NOT IN ('unavailable','zero','not_applicable') OR (NEW.aggregation='ratio' AND NEW.zero_denominator='not_applicable') OR (NEW.aggregation<>'ratio' AND NEW.zero_denominator<>'not_applicable') OR NEW.availability NOT IN ('manual','source_required','unavailable') OR NEW.measurement_kind NOT IN ('objective','subjective')";
        foreach (['mode_id' => 'mode', 'role_id' => 'role', 'release_id' => 'release'] as $c => $kind) {
            $revision .= " OR (NEW.{$c} IS NOT NULL AND NOT EXISTS(SELECT 1 FROM game_catalogue_entries c JOIN metric_definitions m ON m.game_id=c.game_id WHERE m.id=NEW.metric_id AND c.id=NEW.{$c} AND c.kind='{$kind}'))";
        }
        foreach (['md' => ['metric_definitions', $identity], 'mr' => ['metric_revisions', $revision]] as $p => $entry) {
            [$table,$condition] = $entry;
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$p}_insert BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'Invalid metric dictionary entry'); END");
                foreach (['UPDATE', 'DELETE'] as $action) {
                    DB::unprepared('CREATE TRIGGER '.$p.'_'.strtolower($action)." BEFORE {$action} ON {$table} BEGIN SELECT RAISE(ABORT,'Retain metric definition history'); END");
                }
            } else {
                DB::unprepared("CREATE FUNCTION {$p}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Retain metric definition history'; END IF; PERFORM 1 FROM metric_catalogue_state WHERE id=1 FOR UPDATE; IF {$condition} THEN RAISE EXCEPTION 'Invalid metric dictionary entry'; END IF; RETURN NEW; END \$\$");
                DB::unprepared("CREATE TRIGGER {$p}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$p}_guard()");
            }
        }
        $state = 'NEW.id<>OLD.id OR NEW.number<>OLD.number+1 OR NOT EXISTS(SELECT 1 FROM metric_revisions r WHERE r.id=NEW.version AND r.catalogue_number=NEW.number)';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER mc_update BEFORE UPDATE ON metric_catalogue_state WHEN {$state} BEGIN SELECT RAISE(ABORT,'Invalid metric catalogue version'); END");
            DB::unprepared("CREATE TRIGGER mc_insert BEFORE INSERT ON metric_catalogue_state BEGIN SELECT RAISE(ABORT,'Retain metric catalogue state'); END");
            DB::unprepared("CREATE TRIGGER mc_delete BEFORE DELETE ON metric_catalogue_state BEGIN SELECT RAISE(ABORT,'Retain metric catalogue state'); END");
            DB::unprepared('CREATE TRIGGER mr_publish AFTER INSERT ON metric_revisions BEGIN UPDATE metric_catalogue_state SET version=NEW.id, number=NEW.catalogue_number WHERE id=1; END');
        } else {
            DB::unprepared("CREATE FUNCTION mc_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Retain metric catalogue state'; END IF; IF {$state} THEN RAISE EXCEPTION 'Invalid metric catalogue version'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER mc_guard BEFORE INSERT OR UPDATE OR DELETE ON metric_catalogue_state FOR EACH ROW EXECUTE FUNCTION mc_guard()');
            DB::unprepared('CREATE FUNCTION mr_publish() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE metric_catalogue_state SET version=NEW.id, number=NEW.catalogue_number WHERE id=1; RETURN NEW; END $$');
            DB::unprepared('CREATE TRIGGER mr_publish AFTER INSERT ON metric_revisions FOR EACH ROW EXECUTE FUNCTION mr_publish()');
        }
    }

    public function down(): void
    {
        if ((Schema::hasTable('metric_definitions') && DB::table('metric_definitions')->exists()) || (Schema::hasTable('metric_revisions') && DB::table('metric_revisions')->exists())) {
            throw new RuntimeException('Metric definition history must be retained.');
        }
        Schema::dropIfExists('metric_revisions');
        Schema::dropIfExists('metric_definitions');
        Schema::dropIfExists('metric_catalogue_state');
        if (DB::getDriverName() === 'pgsql') {
            foreach (['mr_guard', 'md_guard', 'mc_guard', 'mr_publish'] as $f) {
                DB::unprepared("DROP FUNCTION IF EXISTS {$f}()");
            }
        }
    }
};
