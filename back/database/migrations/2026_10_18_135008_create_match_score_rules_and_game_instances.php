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
            throw new RuntimeException('Match scores require SQLite or PostgreSQL.');
        }
        foreach (['match_score_rules', 'match_game_instances'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name): void {
                $t->uuid('id')->primary();
                $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
                $t->uuid('match_id');
                $t->uuid('match_version');
                $t->text('reason');
                $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
                $t->timestampTz('created_at');
                $t->foreign(['organization_id', 'match_id'])->references(['organization_id', 'id'])->on('matches')->restrictOnDelete();
                if ($name === 'match_score_rules') {
                    $t->enum('format', ['bo1', 'bo3', 'bo5', 'lobby', 'free_for_all']);
                    $t->unsignedSmallInteger('game_limit');
                    $t->boolean('allow_draw');
                    $t->unique(['organization_id', 'match_id']);
                } else {
                    $t->unsignedSmallInteger('number');
                    $t->enum('outcome', ['played', 'draw', 'forfeit', 'abandon', 'remake']);
                    $t->text('map')->nullable();
                    $t->text('mode')->nullable();
                    $t->text('game_patch');
                    $t->text('results');
                    $t->unique(['organization_id', 'match_id', 'number'], 'match_game_number');
                    $t->unique(['organization_id', 'match_id', 'match_version'], 'match_game_version');
                    $t->foreign(['organization_id', 'match_id'], 'match_game_rules_parent')->references(['organization_id', 'match_id'])->on('match_score_rules')->restrictOnDelete();
                }
            });
        }
        foreach (['match_score_rules', 'match_game_instances'] as $name) {
            $condition = "NOT EXISTS (SELECT 1 FROM matches m WHERE m.organization_id=NEW.organization_id AND m.id=NEW.match_id AND m.version=NEW.match_version AND m.status IN ('in_progress','awaiting_validation'))";
            if ($name === 'match_score_rules') {
                $condition .= " OR NEW.game_limit<1 OR NEW.game_limit>100 OR NOT EXISTS (SELECT 1 FROM matches m WHERE m.organization_id=NEW.organization_id AND m.id=NEW.match_id AND m.format=NEW.format) OR (NEW.format='bo1' AND NEW.game_limit<>1) OR (NEW.format='bo3' AND NEW.game_limit<>3) OR (NEW.format='bo5' AND NEW.game_limit<>5)";
            } else {
                $condition .= " OR NEW.number<>(SELECT COALESCE(MAX(number),0)+1 FROM match_game_instances WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id) OR NEW.number>100 OR (NEW.map IS NULL AND NEW.mode IS NULL) OR NOT EXISTS (SELECT 1 FROM match_score_rules r WHERE r.organization_id=NEW.organization_id AND r.match_id=NEW.match_id AND (NEW.outcome<>'draw' OR r.allow_draw=".(DB::getDriverName() === 'pgsql' ? 'true' : '1').")) OR (NEW.outcome<>'remake' AND (SELECT COUNT(*) FROM match_game_instances WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id AND outcome<>'remake') >= (SELECT game_limit FROM match_score_rules WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id))";
            }
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name}_insert BEFORE INSERT ON {$name} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'Invalid match score'); END");
                DB::unprepared("CREATE TRIGGER {$name}_update BEFORE UPDATE ON {$name} BEGIN SELECT RAISE(ABORT,'Immutable match score'); END");
                DB::unprepared("CREATE TRIGGER {$name}_delete BEFORE DELETE ON {$name} BEGIN SELECT RAISE(ABORT,'Immutable match score'); END");
            } else {
                DB::unprepared("CREATE FUNCTION {$name}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable match score'; END IF;
                    PERFORM 1 FROM matches WHERE organization_id=NEW.organization_id AND id=NEW.match_id FOR UPDATE;
                    IF {$condition} THEN RAISE EXCEPTION 'Invalid match score'; END IF;
                    RETURN NEW; END \$\$");
                DB::unprepared("CREATE TRIGGER {$name}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION {$name}_guard()");
            }
        }
    }

    public function down(): void
    {
        foreach (['match_game_instances', 'match_score_rules'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('Match score history must be retained.');
            }
        }
        foreach (['match_game_instances', 'match_score_rules'] as $name) {
            Schema::dropIfExists($name);
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION IF EXISTS {$name}_guard()");
            }
        }
    }
};
