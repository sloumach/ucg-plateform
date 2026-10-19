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
            throw new RuntimeException('Competition outcomes require PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::create('competition_outcome_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('competition_id');
            $t->string('stream', 12);
            $t->unsignedSmallInteger('number');
            $t->text('payload');
            $t->text('reason');
            $t->unsignedBigInteger('actor_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign(['organization_id', 'competition_id'], 'cor_competition')->references(['organization_id', 'id'])->on('external_competitions')->restrictOnDelete();
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'competition_id', 'stream', 'number'], 'cor_sequence');
            $t->unique(['organization_id', 'competition_id', 'id'], 'cor_tenant_id');
        });
        Schema::create('competition_outcome_matches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('competition_id');
            $t->uuid('revision_id');
            $t->uuid('reference_id');
            $t->uuid('match_id');
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign(['organization_id', 'competition_id', 'revision_id'], 'com_revision')->references(['organization_id', 'competition_id', 'id'])->on('competition_outcome_revisions')->restrictOnDelete();
            $t->foreign(['organization_id', 'match_id'], 'com_match')->references(['organization_id', 'id'])->on('matches')->restrictOnDelete();
            $t->unique(['organization_id', 'revision_id', 'match_id'], 'com_unique_match');
            $t->unique(['organization_id', 'revision_id', 'reference_id'], 'com_unique_reference');
            $t->index(['organization_id', 'match_id'], 'com_match_history');
        });
        $revisions = "NEW.stream NOT IN ('sport','awards') OR NEW.number<1 OR NEW.number>1000 OR NEW.number<>(SELECT COALESCE(MAX(r.number),0)+1 FROM competition_outcome_revisions r WHERE r.organization_id=NEW.organization_id AND r.competition_id=NEW.competition_id AND r.stream=NEW.stream) OR NOT EXISTS(SELECT 1 FROM external_competitions p WHERE p.organization_id=NEW.organization_id AND p.id=NEW.competition_id AND p.status='active')";
        $matches = "(SELECT COUNT(*) FROM competition_outcome_matches c WHERE c.organization_id=NEW.organization_id AND c.revision_id=NEW.revision_id)>=20 OR NOT EXISTS(SELECT 1 FROM competition_outcome_revisions r JOIN external_competitions p ON p.organization_id=r.organization_id AND p.id=r.competition_id JOIN matches m ON m.organization_id=p.organization_id AND m.game_id=p.game_id WHERE r.organization_id=NEW.organization_id AND r.competition_id=NEW.competition_id AND r.id=NEW.revision_id AND r.stream='sport' AND m.id=NEW.match_id AND p.status='active' AND r.number=(SELECT MAX(n.number) FROM competition_outcome_revisions n WHERE n.organization_id=r.organization_id AND n.competition_id=r.competition_id AND n.stream='sport'))";
        foreach (['cor' => ['competition_outcome_revisions', $revisions], 'com' => ['competition_outcome_matches', $matches]] as $prefix => $entry) {
            [$table,$condition] = $entry;
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$prefix}_insert BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'Invalid competition outcome'); END");
                foreach (['UPDATE', 'DELETE'] as $action) {
                    DB::unprepared('CREATE TRIGGER '.$prefix.'_'.strtolower($action)." BEFORE {$action} ON {$table} BEGIN SELECT RAISE(ABORT,'Retain competition outcome history'); END");
                }
            } else {
                DB::unprepared("CREATE FUNCTION {$prefix}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Retain competition outcome history'; END IF;
                PERFORM 1 FROM external_competitions WHERE organization_id=NEW.organization_id AND id=NEW.competition_id FOR UPDATE;
                IF {$condition} THEN RAISE EXCEPTION 'Invalid competition outcome'; END IF; RETURN NEW; END \$\$");
                DB::unprepared("CREATE TRIGGER {$prefix}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$prefix}_guard()");
            }
        }
        $linkedGame = 'NEW.game_id<>OLD.game_id AND EXISTS(SELECT 1 FROM competition_outcome_matches c WHERE c.organization_id=OLD.organization_id AND c.match_id=OLD.id)';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER com_match_game BEFORE UPDATE OF game_id ON matches WHEN {$linkedGame} BEGIN SELECT RAISE(ABORT,'Retain linked match game'); END");
        } else {
            DB::unprepared("CREATE FUNCTION com_match_game_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF {$linkedGame} THEN RAISE EXCEPTION 'Retain linked match game'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER com_match_game BEFORE UPDATE OF game_id ON matches FOR EACH ROW EXECUTE FUNCTION com_match_game_guard()');
        }
    }

    public function down(): void
    {
        foreach (['competition_outcome_matches', 'competition_outcome_revisions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Competition outcome history must be retained.');
            }
        }
        DB::unprepared('DROP TRIGGER IF EXISTS com_match_game'.(DB::getDriverName() === 'pgsql' ? ' ON matches' : ''));
        Schema::dropIfExists('competition_outcome_matches');
        Schema::dropIfExists('competition_outcome_revisions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS com_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS com_match_game_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS cor_guard()');
        }
    }
};
