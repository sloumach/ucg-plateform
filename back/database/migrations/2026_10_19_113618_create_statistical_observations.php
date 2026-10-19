<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Statistical observations require PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::table('match_game_instances', fn (Blueprint $t) => $t->unique(['organization_id', 'match_id', 'id'], 'so_part_reference'));
        Schema::create('statistical_observations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('metric_id');
            $t->uuid('metric_revision_id');
            $t->uuid('game_id');
            $t->uuid('person_id');
            $t->uuid('match_id')->nullable();
            $t->uuid('match_game_id')->nullable();
            $t->string('source_kind', 16);
            $t->string('acquired_via', 12);
            $t->string('origin_reference', 160)->nullable();
            $t->text('source_label');
            $t->date('period_start');
            $t->date('period_end');
            $t->date('observed_on');
            $t->unsignedInteger('sample_size');
            $t->text('context');
            $t->unsignedBigInteger('created_by');
            $t->uuid('request_id');
            $t->timestampTz('created_at');
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign('metric_id')->references('id')->on('metric_definitions')->restrictOnDelete();
            $t->foreign('metric_revision_id')->references('id')->on('metric_revisions')->restrictOnDelete();
            $t->foreign('game_id')->references('id')->on('game_catalogue_entries')->restrictOnDelete();
            $t->foreign(['organization_id', 'person_id'], 'so_person')->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $t->foreign(['organization_id', 'match_id'], 'so_match')->references(['organization_id', 'id'])->on('matches')->restrictOnDelete();
            $t->foreign(['organization_id', 'match_id', 'match_game_id'], 'so_part')->references(['organization_id', 'match_id', 'id'])->on('match_game_instances')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'id'], 'so_tenant');
            $t->index(['organization_id', 'person_id', 'period_start', 'id'], 'so_person_period');
            $t->index(['organization_id', 'metric_id', 'period_start', 'id'], 'so_metric_period');
            $t->index(['organization_id', 'match_id'], 'so_match_audience');
        });
        Schema::create('observation_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('observation_id');
            $t->unsignedSmallInteger('number');
            $t->uuid('base_version')->nullable();
            $t->string('availability', 12);
            $t->text('value')->nullable();
            $t->text('missing_reason')->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 12);
            $t->text('reason');
            $t->unsignedBigInteger('actor_user_id');
            $t->uuid('request_id');
            $t->timestampTz('created_at');
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign(['organization_id', 'observation_id'], 'or_observation')->references(['organization_id', 'id'])->on('statistical_observations')->restrictOnDelete();
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'observation_id', 'number'], 'or_sequence');
        });
        $root = "NEW.source_kind NOT IN ('official','scrim','training','assessment') OR NEW.acquired_via NOT IN ('manual','import') OR (NEW.acquired_via='import' AND NEW.origin_reference IS NULL) OR NEW.sample_size<1 OR NEW.sample_size>1000000 OR NEW.period_start>NEW.period_end OR NEW.period_end>NEW.observed_on OR NOT EXISTS(SELECT 1 FROM metric_revisions r JOIN metric_definitions d ON d.id=r.metric_id WHERE r.id=NEW.metric_revision_id AND d.id=NEW.metric_id AND d.game_id=NEW.game_id) OR (NEW.match_game_id IS NOT NULL AND (NEW.match_id IS NULL OR NOT EXISTS(SELECT 1 FROM match_game_instances g WHERE g.id=NEW.match_game_id AND g.organization_id=NEW.organization_id AND g.match_id=NEW.match_id))) OR (NEW.match_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM matches m WHERE m.id=NEW.match_id AND m.organization_id=NEW.organization_id AND m.game_id=NEW.game_id AND (NEW.source_kind='assessment' OR m.kind=NEW.source_kind)))";
        $revision = "NEW.number<1 OR NEW.number>1000 OR NEW.number<>(SELECT COALESCE(MAX(r.number),0)+1 FROM observation_revisions r WHERE r.organization_id=NEW.organization_id AND r.observation_id=NEW.observation_id) OR (NEW.number=1 AND NEW.base_version IS NOT NULL) OR (NEW.number>1 AND (NEW.base_version IS NULL OR NOT EXISTS(SELECT 1 FROM observation_revisions r WHERE r.organization_id=NEW.organization_id AND r.observation_id=NEW.observation_id AND r.number=NEW.number-1 AND r.id=NEW.base_version))) OR NEW.status NOT IN ('active','voided') OR NEW.availability NOT IN ('available','unavailable') OR (NEW.availability='available' AND (NEW.value IS NULL OR NEW.missing_reason IS NOT NULL)) OR (NEW.availability='unavailable' AND (NEW.value IS NOT NULL OR NEW.missing_reason IS NULL))";
        foreach (['so' => ['statistical_observations', $root], 'orv' => ['observation_revisions', $revision]] as $p => [$table, $condition]) {
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$p}_insert BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'Invalid statistical observation'); END");
                foreach (['UPDATE', 'DELETE'] as $action) {
                    DB::unprepared('CREATE TRIGGER '.$p.'_'.strtolower($action)." BEFORE {$action} ON {$table} BEGIN SELECT RAISE(ABORT,'Retain statistical history'); END");
                }
            } else {
                $lock = $p === 'orv' ? 'PERFORM 1 FROM statistical_observations WHERE organization_id=NEW.organization_id AND id=NEW.observation_id FOR UPDATE;' : '';
                DB::unprepared("CREATE FUNCTION {$p}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Retain statistical history'; END IF; {$lock} IF {$condition} THEN RAISE EXCEPTION 'Invalid statistical observation'; END IF; RETURN NEW; END \$\$");
                DB::unprepared("CREATE TRIGGER {$p}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$p}_guard()");
            }
        }
        $game = 'NEW.game_id<>OLD.game_id AND EXISTS(SELECT 1 FROM statistical_observations o WHERE o.organization_id=OLD.organization_id AND o.match_id=OLD.id)';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER so_match_game BEFORE UPDATE OF game_id ON matches WHEN {$game} BEGIN SELECT RAISE(ABORT,'Retain observation game'); END");
        } else {
            DB::unprepared("CREATE FUNCTION so_match_game() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$game} THEN RAISE EXCEPTION 'Retain observation game'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER so_match_game BEFORE UPDATE OF game_id ON matches FOR EACH ROW EXECUTE FUNCTION so_match_game()');
        }
    }

    public function down(): void
    {
        foreach (['statistical_observations', 'observation_revisions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Statistical observation history must be retained.');
            }
        }
        DB::unprepared('DROP TRIGGER IF EXISTS so_match_game'.(DB::getDriverName() === 'pgsql' ? ' ON matches' : ''));
        Schema::dropIfExists('observation_revisions');
        Schema::dropIfExists('statistical_observations');
        Schema::table('match_game_instances', fn (Blueprint $t) => $t->dropUnique('so_part_reference'));
        if (DB::getDriverName() === 'pgsql') {
            foreach (['so_guard', 'orv_guard', 'so_match_game'] as $f) {
                DB::unprepared("DROP FUNCTION IF EXISTS {$f}()");
            }
        }
    }
};
