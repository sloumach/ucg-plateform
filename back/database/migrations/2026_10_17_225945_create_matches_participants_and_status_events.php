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
            throw new RuntimeException('Matches require SQLite or PostgreSQL.');
        }
        Schema::create('matches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignUuid('game_id')->constrained('game_catalogue_entries')->restrictOnDelete();
            $t->string('game_key', 64);
            $t->string('game_name', 120);
            $t->text('title');
            $t->enum('kind', ['scrim', 'official']);
            $t->enum('format', ['bo1', 'bo3', 'bo5', 'lobby', 'free_for_all']);
            $t->enum('status', ['proposed', 'confirmed', 'in_progress', 'awaiting_validation', 'validated', 'disputed', 'cancelled', 'forfeited']);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('origin_timezone', 100);
            $t->uuid('responsible_person_id');
            $t->text('responsible_label');
            $t->enum('visibility', ['private', 'team', 'organization']);
            $t->foreignId('sharing_authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('sharing_authorized_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('version');
            $t->timestamps();
            $t->foreign(['organization_id', 'responsible_person_id'])->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'starts_at', 'id'], 'match_agenda');
            $t->index(['organization_id', 'game_id', 'status', 'starts_at', 'id'], 'match_game_status');
        });
        Schema::create('match_participants', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('match_id');
            $t->unsignedSmallInteger('slot');
            $t->enum('kind', ['team', 'person', 'opponent_snapshot']);
            $t->uuid('team_id')->nullable();
            $t->uuid('person_id')->nullable();
            $t->uuid('opponent_snapshot_id')->nullable();
            $t->uuid('reference_version');
            $t->text('label');
            $t->foreign(['organization_id', 'match_id'])->references(['organization_id', 'id'])->on('matches')->restrictOnDelete();
            foreach (['team_id' => 'teams', 'person_id' => 'people', 'opponent_snapshot_id' => 'external_opponent_snapshots'] as $column => $parent) {
                $t->foreign(['organization_id', $column])->references(['organization_id', 'id'])->on($parent)->restrictOnDelete();
                $t->unique(['organization_id', 'match_id', $column]);
            }
            $t->unique(['organization_id', 'id']);
            $t->unique(['organization_id', 'match_id', 'slot']);
            $t->index(['organization_id', 'person_id', 'match_id'], 'match_person_audience');
            $t->index(['organization_id', 'team_id', 'match_id'], 'match_team_audience');
        });
        Schema::create('match_status_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('match_id');
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->uuid('match_version');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->timestampTz('created_at');
            $t->foreign(['organization_id', 'match_id'])->references(['organization_id', 'id'])->on('matches')->restrictOnDelete();
            $t->unique(['organization_id', 'match_id', 'match_version']);
            $t->index(['organization_id', 'match_id', 'created_at', 'id'], 'match_status_history');
        });

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
        $participant = "NEW.slot<1 OR NEW.slot>100 OR NOT (
            (NEW.kind='team' AND NEW.team_id IS NOT NULL AND NEW.person_id IS NULL AND NEW.opponent_snapshot_id IS NULL)
            OR (NEW.kind='person' AND NEW.person_id IS NOT NULL AND NEW.team_id IS NULL AND NEW.opponent_snapshot_id IS NULL)
            OR (NEW.kind='opponent_snapshot' AND NEW.opponent_snapshot_id IS NOT NULL AND NEW.team_id IS NULL AND NEW.person_id IS NULL))";
        $parent = 'SELECT status FROM matches WHERE organization_id=NEW.organization_id AND id=NEW.match_id';
        $event = "NOT EXISTS (SELECT 1 FROM matches m WHERE m.organization_id=NEW.organization_id AND m.id=NEW.match_id AND m.status=NEW.to_status AND m.version=NEW.match_version)
            OR (NEW.from_status IS NULL AND (NEW.to_status<>'proposed' OR NEW.reason IS NOT NULL))
            OR (NEW.from_status IS NOT NULL AND NEW.reason IS NULL)";
        $eventGraph = "(NEW.from_status='proposed' AND NEW.to_status IN ('confirmed','cancelled'))
            OR (NEW.from_status='confirmed' AND NEW.to_status IN ('proposed','in_progress','cancelled','forfeited'))
            OR (NEW.from_status='in_progress' AND NEW.to_status IN ('awaiting_validation','cancelled','forfeited'))
            OR (NEW.from_status='awaiting_validation' AND NEW.to_status IN ('validated','disputed'))
            OR (NEW.from_status='validated' AND NEW.to_status='disputed')
            OR (NEW.from_status='disputed' AND NEW.to_status IN ('awaiting_validation','validated','cancelled'))";
        $event .= " OR (NEW.from_status IS NOT NULL AND NOT ($eventGraph))";
        $duration = DB::getDriverName() === 'sqlite' ? '(julianday(NEW.ends_at)-julianday(NEW.starts_at))*86400' : 'EXTRACT(EPOCH FROM (NEW.ends_at-NEW.starts_at))';
        $shape .= " OR $duration > 2678400 OR NOT EXISTS (SELECT 1 FROM game_catalogue_entries WHERE id=NEW.game_id AND kind='game')";
        $participant .= " OR (NEW.kind='team' AND NOT EXISTS (SELECT 1 FROM teams t JOIN matches m ON m.organization_id=t.organization_id AND m.game_id=t.game_id WHERE t.organization_id=NEW.organization_id AND t.id=NEW.team_id AND m.id=NEW.match_id))
            OR (NEW.kind='opponent_snapshot' AND NOT EXISTS (SELECT 1 FROM external_opponent_snapshots s JOIN matches m ON m.organization_id=s.organization_id WHERE s.organization_id=NEW.organization_id AND s.id=NEW.opponent_snapshot_id AND m.id=NEW.match_id AND (s.game_key IS NULL OR s.game_key=m.game_key)))";
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER matches_insert BEFORE INSERT ON matches WHEN $shape OR NEW.status<>'proposed' BEGIN SELECT RAISE(ABORT,'Invalid match'); END");
            $immutable = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS NOT OLD.$c", $identity));
            $frozen = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS NOT OLD.$c", $core));
            DB::unprepared("CREATE TRIGGER matches_update BEFORE UPDATE ON matches WHEN $shape OR $immutable OR NEW.version=OLD.version
                OR ((OLD.status<>'proposed' OR NEW.status<>'proposed') AND ($frozen))
                OR (NEW.status<>OLD.status AND NOT ($graph))
                OR (NEW.status<>'proposed' AND ($cardinality))
                BEGIN SELECT RAISE(ABORT,'Invalid or immutable match'); END");
            DB::unprepared("CREATE TRIGGER matches_no_delete BEFORE DELETE ON matches BEGIN SELECT RAISE(ABORT,'Retain match'); END");
            DB::unprepared("CREATE TRIGGER match_participant_insert BEFORE INSERT ON match_participants WHEN $participant OR ($parent)<>'proposed'
                OR (SELECT COUNT(*) FROM match_participants WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id)>=100
                BEGIN SELECT RAISE(ABORT,'Invalid match participant'); END");
            DB::unprepared("CREATE TRIGGER match_participant_update BEFORE UPDATE ON match_participants BEGIN SELECT RAISE(ABORT,'Replace draft participant only'); END");
            DB::unprepared("CREATE TRIGGER match_participant_delete BEFORE DELETE ON match_participants WHEN
                (SELECT status FROM matches WHERE organization_id=OLD.organization_id AND id=OLD.match_id)<>'proposed'
                BEGIN SELECT RAISE(ABORT,'Frozen match participant'); END");
            DB::unprepared("CREATE TRIGGER match_event_insert BEFORE INSERT ON match_status_events WHEN $event BEGIN SELECT RAISE(ABORT,'Invalid match event'); END");
            foreach (['UPDATE', 'DELETE'] as $op) {
                DB::unprepared('CREATE TRIGGER match_event_no_'.strtolower($op)." BEFORE $op ON match_status_events BEGIN SELECT RAISE(ABORT,'Immutable match history'); END");
            }
        } else {
            $immutable = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS DISTINCT FROM OLD.$c", $identity));
            $frozen = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS DISTINCT FROM OLD.$c", $core));
            DB::unprepared("CREATE FUNCTION matches_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain match'; END IF;
                IF $shape THEN RAISE EXCEPTION 'Invalid match'; END IF;
                IF TG_OP='INSERT' THEN IF NEW.status<>'proposed' THEN RAISE EXCEPTION 'Initial match must be proposed'; END IF;
                ELSE IF $immutable OR NEW.version=OLD.version OR ((OLD.status<>'proposed' OR NEW.status<>'proposed') AND ($frozen))
                    OR (NEW.status<>OLD.status AND NOT ($graph)) OR (NEW.status<>'proposed' AND ($cardinality)) THEN RAISE EXCEPTION 'Invalid or immutable match'; END IF;
                END IF; RETURN NEW; END; \$\$;
                CREATE TRIGGER matches_guard BEFORE INSERT OR UPDATE OR DELETE ON matches FOR EACH ROW EXECUTE FUNCTION matches_guard()");
            DB::unprepared("CREATE FUNCTION match_participant_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Replace draft participant only'; END IF;
                IF TG_OP='DELETE' THEN
                    IF (SELECT status FROM matches WHERE organization_id=OLD.organization_id AND id=OLD.match_id)<>'proposed' THEN RAISE EXCEPTION 'Frozen match participant'; END IF;
                    RETURN OLD;
                END IF;
                IF $participant OR ($parent)<>'proposed' OR (SELECT COUNT(*) FROM match_participants WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id)>=100 THEN RAISE EXCEPTION 'Invalid match participant'; END IF;
                RETURN NEW; END; \$\$;
                CREATE TRIGGER match_participant_guard BEFORE INSERT OR UPDATE OR DELETE ON match_participants FOR EACH ROW EXECUTE FUNCTION match_participant_guard()");
            DB::unprepared("CREATE FUNCTION match_event_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable match history'; END IF;
                IF $event THEN RAISE EXCEPTION 'Invalid match event'; END IF; RETURN NEW; END; \$\$;
                CREATE TRIGGER match_event_guard BEFORE INSERT OR UPDATE OR DELETE ON match_status_events FOR EACH ROW EXECUTE FUNCTION match_event_guard()");
        }
    }

    public function down(): void
    {
        foreach (['match_status_events', 'match_participants', 'matches'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Match history exists. Use a forward migration.');
            }
        }
        Schema::dropIfExists('match_status_events');
        Schema::dropIfExists('match_participants');
        Schema::dropIfExists('matches');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS matches_guard(); DROP FUNCTION IF EXISTS match_participant_guard(); DROP FUNCTION IF EXISTS match_event_guard()');
        }
    }
};
