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
            throw new RuntimeException('Match results require SQLite or PostgreSQL.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::create('match_result_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('match_id');
            $t->uuid('base_version');
            $t->uuid('match_version');
            $t->unsignedSmallInteger('number');
            $t->enum('action', ['submit', 'approve', 'dispute']);
            $t->enum('outcome', ['played', 'forfeit', 'abandon']);
            $t->string('from_status', 32);
            $t->string('to_status', 32);
            $t->uuid('submission_id')->nullable();
            $t->text('proof_reference')->nullable();
            $t->text('snapshot')->nullable();
            $t->text('contributors')->nullable();
            $t->text('reason');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->foreign(['organization_id', 'match_id'])->references(['organization_id', 'id'])->on('matches')->restrictOnDelete();
            $t->unique(['organization_id', 'match_id', 'id'], 'match_result_identity');
            $t->foreign(['organization_id', 'match_id', 'submission_id'], 'match_result_submission')->references(['organization_id', 'match_id', 'id'])->on('match_result_events')->restrictOnDelete();
            $t->unique(['organization_id', 'match_id', 'number'], 'match_result_history');
            $t->unique(['organization_id', 'match_id', 'match_version'], 'match_result_version');
        });
        $latest = '(SELECT MAX(e.number) FROM match_result_events e WHERE e.organization_id=NEW.organization_id AND e.match_id=NEW.match_id)';
        $invalid = "NEW.base_version=NEW.match_version OR NEW.number<>COALESCE($latest,0)+1 OR NEW.number>200
            OR NOT EXISTS (SELECT 1 FROM matches m WHERE m.organization_id=NEW.organization_id AND m.id=NEW.match_id AND m.version=NEW.base_version AND m.status=NEW.from_status)
            OR NOT (
                (NEW.action='submit' AND NEW.submission_id IS NULL AND NEW.proof_reference IS NOT NULL AND NEW.snapshot IS NOT NULL AND NEW.contributors IS NOT NULL
                    AND NEW.from_status IN ('confirmed','in_progress','awaiting_validation','disputed') AND NEW.to_status='awaiting_validation'
                    AND ($latest IS NULL OR EXISTS (SELECT 1 FROM match_result_events e WHERE e.organization_id=NEW.organization_id AND e.match_id=NEW.match_id AND e.number=$latest AND e.action='dispute')))
                OR (NEW.action IN ('approve','dispute') AND NEW.proof_reference IS NULL AND NEW.snapshot IS NULL AND NEW.contributors IS NULL AND EXISTS (
                    SELECT 1 FROM match_result_events e JOIN match_result_events s ON s.organization_id=e.organization_id AND s.match_id=e.match_id
                        AND s.id=NEW.submission_id AND s.action='submit' AND s.outcome=NEW.outcome
                    WHERE e.organization_id=NEW.organization_id AND e.match_id=NEW.match_id AND e.number=$latest
                        AND (s.id=e.id OR s.id=e.submission_id)
                        AND ((NEW.action='approve' AND e.action='submit' AND NEW.from_status='awaiting_validation' AND NEW.actor_user_id<>s.actor_user_id
                            AND ((NEW.outcome='forfeit' AND NEW.to_status='forfeited') OR (NEW.outcome<>'forfeit' AND NEW.to_status='validated')))
                            OR (NEW.action='dispute' AND e.action IN ('submit','approve') AND NEW.from_status IN ('awaiting_validation','validated','forfeited') AND NEW.to_status='disputed'))))
            )";
        $parentInvalid = "(EXISTS (SELECT 1 FROM match_result_events e WHERE e.organization_id=NEW.organization_id AND e.match_id=NEW.id)
                OR NEW.status IN ('validated','disputed','forfeited'))
            AND NOT EXISTS (SELECT 1 FROM match_result_events e WHERE e.organization_id=NEW.organization_id AND e.match_id=NEW.id
                AND e.number=(SELECT MAX(number) FROM match_result_events WHERE organization_id=NEW.organization_id AND match_id=NEW.id)
                AND e.base_version=OLD.version AND e.match_version=NEW.version AND e.from_status=OLD.status AND e.to_status=NEW.status)";
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER match_result_insert BEFORE INSERT ON match_result_events WHEN $invalid BEGIN SELECT RAISE(ABORT,'Invalid match result'); END");
            DB::unprepared("CREATE TRIGGER match_result_update BEFORE UPDATE ON match_result_events BEGIN SELECT RAISE(ABORT,'Immutable match result'); END");
            DB::unprepared("CREATE TRIGGER match_result_delete BEFORE DELETE ON match_result_events BEGIN SELECT RAISE(ABORT,'Immutable match result'); END");
            DB::unprepared("CREATE TRIGGER match_result_parent BEFORE UPDATE ON matches WHEN $parentInvalid BEGIN SELECT RAISE(ABORT,'Match result transition required'); END");
            foreach (['match_score_rules', 'match_game_instances'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_result_freeze BEFORE INSERT ON {$table} WHEN EXISTS (SELECT 1 FROM match_result_events WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id) BEGIN SELECT RAISE(ABORT,'Match result locks scores'); END");
            }
        } else {
            DB::unprepared("CREATE FUNCTION match_result_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable match result'; END IF;
                PERFORM 1 FROM matches WHERE organization_id=NEW.organization_id AND id=NEW.match_id FOR UPDATE;
                IF $invalid THEN RAISE EXCEPTION 'Invalid match result'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER match_result_guard BEFORE INSERT OR UPDATE OR DELETE ON match_result_events FOR EACH ROW EXECUTE FUNCTION match_result_guard()");
            DB::unprepared("CREATE FUNCTION match_result_parent_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF $parentInvalid THEN RAISE EXCEPTION 'Match result transition required'; END IF; RETURN NEW; END \$\$;
                CREATE TRIGGER match_result_parent BEFORE UPDATE ON matches FOR EACH ROW EXECUTE FUNCTION match_result_parent_guard()");
            DB::unprepared("CREATE FUNCTION match_result_score_freeze() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                PERFORM 1 FROM matches WHERE organization_id=NEW.organization_id AND id=NEW.match_id FOR UPDATE;
                IF EXISTS (SELECT 1 FROM match_result_events WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id) THEN RAISE EXCEPTION 'Match result locks scores'; END IF; RETURN NEW; END \$\$");
            foreach (['match_score_rules', 'match_game_instances'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_result_freeze BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION match_result_score_freeze()");
            }
        }
        $this->matchGuards(true);
    }

    public function down(): void
    {
        if (Schema::hasTable('match_result_events') && DB::table('match_result_events')->exists()) {
            throw new RuntimeException('Match result history must be retained.');
        }
        $this->matchGuards(false);
        DB::unprepared('DROP TRIGGER IF EXISTS match_result_parent'.(DB::getDriverName() === 'pgsql' ? ' ON matches' : ''));
        foreach (['match_score_rules', 'match_game_instances'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_result_freeze".(DB::getDriverName() === 'pgsql' ? " ON {$table}" : ''));
        }
        Schema::dropIfExists('match_result_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS match_result_guard(); DROP FUNCTION IF EXISTS match_result_parent_guard(); DROP FUNCTION IF EXISTS match_result_score_freeze()');
        }
    }

    /** Preserve the original Match invariants and add only transitions backed by a result event. */
    private function matchGuards(bool $results): void
    {
        $identity = ['id', 'organization_id', 'game_id', 'game_key', 'game_name', 'created_by', 'created_at'];
        $core = ['title', 'kind', 'format', 'starts_at', 'ends_at', 'origin_timezone', 'responsible_person_id', 'responsible_label', 'visibility', 'sharing_authorized_by', 'sharing_authorized_at'];
        $graph = "(OLD.status='proposed' AND NEW.status IN ('confirmed','cancelled'))
            OR (OLD.status='confirmed' AND NEW.status IN ('proposed','in_progress','cancelled','forfeited'))
            OR (OLD.status='in_progress' AND NEW.status IN ('awaiting_validation','cancelled','forfeited'))
            OR (OLD.status='awaiting_validation' AND NEW.status IN ('validated','disputed'))
            OR (OLD.status='validated' AND NEW.status='disputed')
            OR (OLD.status='disputed' AND NEW.status IN ('awaiting_validation','validated','cancelled'))";
        $shape = "NEW.ends_at <= NEW.starts_at OR ((NEW.sharing_authorized_at IS NULL) <> (NEW.sharing_authorized_by IS NULL))
            OR (NEW.visibility<>'private' AND NEW.sharing_authorized_at IS NULL)";
        $count = '(SELECT COUNT(*) FROM match_participants p WHERE p.organization_id=NEW.organization_id AND p.match_id=NEW.id)';
        $cardinality = "(NEW.format IN ('bo1','bo3','bo5') AND $count <> 2) OR $count < 2 OR $count > 100";

        $duration = DB::getDriverName() === 'sqlite' ? '(julianday(NEW.ends_at)-julianday(NEW.starts_at))*86400' : 'EXTRACT(EPOCH FROM (NEW.ends_at-NEW.starts_at))';
        $shape .= " OR $duration > 2678400 OR NOT EXISTS (SELECT 1 FROM game_catalogue_entries WHERE id=NEW.game_id AND kind='game')";
        $eventGraph = "(NEW.from_status='proposed' AND NEW.to_status IN ('confirmed','cancelled'))
            OR (NEW.from_status='confirmed' AND NEW.to_status IN ('proposed','in_progress','cancelled','forfeited'))
            OR (NEW.from_status='in_progress' AND NEW.to_status IN ('awaiting_validation','cancelled','forfeited'))
            OR (NEW.from_status='awaiting_validation' AND NEW.to_status IN ('validated','disputed'))
            OR (NEW.from_status='validated' AND NEW.to_status='disputed')
            OR (NEW.from_status='disputed' AND NEW.to_status IN ('awaiting_validation','validated','cancelled'))";
        if ($results) {
            $graph .= ' OR EXISTS (SELECT 1 FROM match_result_events e WHERE e.organization_id=NEW.organization_id AND e.match_id=NEW.id AND e.base_version=OLD.version AND e.match_version=NEW.version AND e.from_status=OLD.status AND e.to_status=NEW.status)';
            $eventGraph .= ' OR EXISTS (SELECT 1 FROM match_result_events e WHERE e.organization_id=NEW.organization_id AND e.match_id=NEW.match_id AND e.match_version=NEW.match_version AND e.from_status=NEW.from_status AND e.to_status=NEW.to_status)';
        }
        $event = "NOT EXISTS (SELECT 1 FROM matches m WHERE m.organization_id=NEW.organization_id AND m.id=NEW.match_id AND m.status=NEW.to_status AND m.version=NEW.match_version)
            OR (NEW.from_status IS NULL AND (NEW.to_status<>'proposed' OR NEW.reason IS NOT NULL))
            OR (NEW.from_status IS NOT NULL AND NEW.reason IS NULL) OR (NEW.from_status IS NOT NULL AND NOT ($eventGraph))";
        if (DB::getDriverName() === 'sqlite') {
            $immutable = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS NOT OLD.$c", $identity));
            $frozen = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS NOT OLD.$c", $core));
            DB::unprepared('DROP TRIGGER matches_update; DROP TRIGGER match_event_insert');
            DB::unprepared("CREATE TRIGGER matches_update BEFORE UPDATE ON matches WHEN $shape OR $immutable OR NEW.version=OLD.version
                OR ((OLD.status<>'proposed' OR NEW.status<>'proposed') AND ($frozen)) OR (NEW.status<>OLD.status AND NOT ($graph))
                OR (NEW.status<>'proposed' AND ($cardinality)) BEGIN SELECT RAISE(ABORT,'Invalid or immutable match'); END");
            DB::unprepared("CREATE TRIGGER match_event_insert BEFORE INSERT ON match_status_events WHEN $event BEGIN SELECT RAISE(ABORT,'Invalid match event'); END");
        } else {
            $immutable = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS DISTINCT FROM OLD.$c", $identity));
            $frozen = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS DISTINCT FROM OLD.$c", $core));
            DB::unprepared("CREATE OR REPLACE FUNCTION matches_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain match'; END IF;
                IF $shape THEN RAISE EXCEPTION 'Invalid match'; END IF;
                IF TG_OP='INSERT' THEN IF NEW.status<>'proposed' THEN RAISE EXCEPTION 'Initial match must be proposed'; END IF;
                ELSE IF $immutable OR NEW.version=OLD.version OR ((OLD.status<>'proposed' OR NEW.status<>'proposed') AND ($frozen))
                    OR (NEW.status<>OLD.status AND NOT ($graph)) OR (NEW.status<>'proposed' AND ($cardinality)) THEN RAISE EXCEPTION 'Invalid or immutable match'; END IF;
                END IF; RETURN NEW; END; \$\$");
            DB::unprepared("CREATE OR REPLACE FUNCTION match_event_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable match history'; END IF;
                IF $event THEN RAISE EXCEPTION 'Invalid match event'; END IF; RETURN NEW; END; \$\$");
        }
    }
};
