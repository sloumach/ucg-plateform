<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $postgres = DB::getDriverName() === 'pgsql';
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('TEAM-004 requires PostgreSQL or SQLite.');
        }
        if ($postgres) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('team_lineups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('team_id');
            $table->foreignUuid('game_id')->constrained('game_catalogue_entries')->restrictOnDelete();
            $table->enum('purpose', ['match', 'registration']);
            $table->string('label', 120);
            $table->timestamp('effective_at');
            $table->enum('status', ['draft', 'finalized', 'cancelled']);
            $table->uuid('version');
            $table->string('team_name', 120);
            $table->string('game_key', 64);
            $table->string('game_name', 120);
            $table->string('season', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'team_id'])->references(['organization_id', 'id'])->on('teams')->restrictOnDelete();
            $table->index(['organization_id', 'team_id', 'effective_at', 'id'], 'team_lineups_list_index');
        });
        Schema::create('team_lineup_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('lineup_id');
            $table->uuid('membership_id');
            $table->uuid('membership_version');
            $table->uuid('person_id');
            $table->string('pseudonym', 80);
            $table->enum('membership_role_key', ['player', 'substitute']);
            $table->enum('slot_key', ['starter', 'substitute']);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            foreach (['lineup_id' => 'team_lineups', 'membership_id' => 'team_memberships', 'person_id' => 'people'] as $column => $parent) {
                $table->foreign(['organization_id', $column])->references(['organization_id', 'id'])->on($parent)->restrictOnDelete();
            }
            $table->unique(['organization_id', 'lineup_id', 'person_id'], 'team_lineup_entries_person_unique');
            $table->unique(['organization_id', 'lineup_id', 'position'], 'team_lineup_entries_position_unique');
            $table->index(['organization_id', 'membership_id']);
        });
        $diff = $postgres ? 'IS DISTINCT FROM' : 'IS NOT';
        $identity = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$diff} OLD.{$field}", ['id', 'organization_id', 'team_id', 'game_id', 'purpose', 'created_by', 'created_at']));
        $invalid = "(NEW.status='finalized' AND (NEW.finalized_at IS NULL OR NEW.finalized_by IS NULL))
            OR (NEW.status!='finalized' AND (NEW.finalized_at IS NOT NULL OR NEW.finalized_by IS NOT NULL))
            OR NOT EXISTS(SELECT 1 FROM teams t WHERE t.id=NEW.team_id AND t.organization_id=NEW.organization_id AND t.game_id=NEW.game_id)";
        $final = "NEW.status='finalized' AND (
            NOT EXISTS(SELECT 1 FROM team_lineup_entries e WHERE e.organization_id=NEW.organization_id AND e.lineup_id=NEW.id AND e.slot_key='starter')
            OR EXISTS(SELECT 1 FROM team_lineup_entries e JOIN team_memberships m ON m.id=e.membership_id AND m.organization_id=e.organization_id
                WHERE e.organization_id=NEW.organization_id AND e.lineup_id=NEW.id AND (m.team_id!=NEW.team_id OR m.person_id!=e.person_id
                    OR m.version!=e.membership_version OR m.role_key!=e.membership_role_key OR m.status='cancelled'
                    OR m.starts_at>NEW.effective_at OR (m.ends_at IS NOT NULL AND m.ends_at<=NEW.effective_at))))";
        $entryInvalid = "NEW.position<1 OR NEW.position>50 OR NOT EXISTS(SELECT 1 FROM team_lineups l JOIN team_memberships m
            ON m.organization_id=l.organization_id AND m.team_id=l.team_id WHERE l.id=NEW.lineup_id AND l.organization_id=NEW.organization_id
            AND l.status='draft' AND m.id=NEW.membership_id AND m.person_id=NEW.person_id AND m.version=NEW.membership_version AND m.role_key=NEW.membership_role_key)";
        $capacity = '(SELECT COUNT(*) FROM team_lineups l WHERE l.organization_id=NEW.organization_id AND l.team_id=NEW.team_id)>=1000';
        if ($postgres) {
            DB::unprepared("CREATE FUNCTION ucg_team_lineups_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Lineup history must be retained' USING ERRCODE='23514'; END IF;
                PERFORM id FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                IF ({$invalid}) OR ({$final}) THEN RAISE EXCEPTION 'Invalid lineup snapshot' USING ERRCODE='23514'; END IF;
                IF TG_OP='INSERT' AND (NEW.status!='draft' OR ({$capacity})) THEN RAISE EXCEPTION 'Lineup must start as a bounded draft' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (OLD.status!='draft' OR ({$identity})) THEN RAISE EXCEPTION 'Lineup snapshot is immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER team_lineups_guard BEFORE INSERT OR UPDATE OR DELETE ON team_lineups FOR EACH ROW EXECUTE FUNCTION ucg_team_lineups_guard();
                CREATE FUNCTION ucg_team_lineup_entries_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Replace draft entries atomically' USING ERRCODE='23514'; END IF;
                IF TG_OP='DELETE' THEN
                    PERFORM id FROM organizations WHERE id=OLD.organization_id FOR UPDATE;
                    IF NOT EXISTS(SELECT 1 FROM team_lineups WHERE id=OLD.lineup_id AND organization_id=OLD.organization_id AND status='draft')
                    THEN RAISE EXCEPTION 'Frozen participants must be retained' USING ERRCODE='23514'; END IF;
                    RETURN OLD;
                END IF;
                PERFORM id FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                IF ({$entryInvalid}) THEN RAISE EXCEPTION 'Invalid lineup participant' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER team_lineup_entries_guard BEFORE INSERT OR UPDATE OR DELETE ON team_lineup_entries FOR EACH ROW EXECUTE FUNCTION ucg_team_lineup_entries_guard()");
        } else {
            DB::unprepared("CREATE TRIGGER team_lineups_insert BEFORE INSERT ON team_lineups WHEN NEW.status!='draft' OR ({$invalid}) OR ({$capacity})
                BEGIN SELECT RAISE(ABORT,'Invalid lineup draft'); END");
            DB::unprepared("CREATE TRIGGER team_lineups_update BEFORE UPDATE ON team_lineups WHEN OLD.status!='draft' OR ({$identity}) OR ({$invalid}) OR ({$final})
                BEGIN SELECT RAISE(ABORT,'Lineup snapshot is immutable or invalid'); END");
            DB::unprepared("CREATE TRIGGER team_lineups_delete BEFORE DELETE ON team_lineups BEGIN SELECT RAISE(ABORT,'Lineup history must be retained'); END");
            DB::unprepared("CREATE TRIGGER team_lineup_entries_insert BEFORE INSERT ON team_lineup_entries WHEN ({$entryInvalid})
                BEGIN SELECT RAISE(ABORT,'Invalid lineup participant'); END");
            DB::unprepared("CREATE TRIGGER team_lineup_entries_update BEFORE UPDATE ON team_lineup_entries BEGIN SELECT RAISE(ABORT,'Replace draft entries atomically'); END");
            DB::unprepared("CREATE TRIGGER team_lineup_entries_delete BEFORE DELETE ON team_lineup_entries
                WHEN NOT EXISTS(SELECT 1 FROM team_lineups WHERE id=OLD.lineup_id AND organization_id=OLD.organization_id AND status='draft')
                BEGIN SELECT RAISE(ABORT,'Frozen participants must be retained'); END");
        }
    }

    public function down(): void
    {
        if (DB::table('team_lineups')->exists() || DB::table('team_lineup_entries')->exists()) {
            throw new RuntimeException('TEAM-004 rollback cannot discard lineups. Use a forward fix.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::drop('team_lineup_entries');
        Schema::drop('team_lineups');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION ucg_team_lineup_entries_guard(); DROP FUNCTION ucg_team_lineups_guard()');
        }
    }
};
