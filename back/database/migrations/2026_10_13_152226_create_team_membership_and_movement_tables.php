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
            throw new RuntimeException('TEAM-003 requires PostgreSQL or SQLite.');
        }
        if ($postgres) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('team_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('team_id');
            $table->uuid('person_id');
            $table->enum('role_key', ['player', 'substitute', 'captain', 'coach', 'analyst']);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->enum('status', ['active', 'closed', 'cancelled']);
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'team_id'])->references(['organization_id', 'id'])->on('teams')->restrictOnDelete();
            $table->foreign(['organization_id', 'person_id'])->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $table->index(['organization_id', 'team_id', 'starts_at', 'id'], 'team_memberships_list_index');
            $table->index(['organization_id', 'person_id', 'team_id', 'role_key', 'starts_at'], 'team_memberships_overlap_index');
        });
        Schema::create('team_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('team_id');
            $table->uuid('membership_id');
            $table->uuid('destination_team_id')->nullable();
            $table->uuid('destination_membership_id')->nullable();
            $table->enum('action', ['created', 'close', 'cancel', 'transfer', 'promotion', 'person_departure']);
            $table->enum('reason_key', ['initial', 'sporting_decision', 'promotion', 'transfer', 'end_of_period', 'person_departure', 'other']);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('effective_at');
            $table->uuid('request_id');
            $table->timestamp('created_at');
            foreach (['team_id' => 'teams', 'membership_id' => 'team_memberships', 'destination_team_id' => 'teams', 'destination_membership_id' => 'team_memberships'] as $column => $parent) {
                $table->foreign(['organization_id', $column])->references(['organization_id', 'id'])->on($parent)->restrictOnDelete();
            }
            $table->index(['organization_id', 'team_id', 'created_at', 'id'], 'team_movements_source_index');
            $table->index(['organization_id', 'destination_team_id', 'created_at', 'id'], 'team_movements_destination_index');
        });
        Schema::create('team_scope_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('role_assignment_id');
            $table->uuid('team_membership_id');
            $table->timestamp('created_at');
            $table->unique(['organization_id', 'role_assignment_id']);
            $table->foreign(['organization_id', 'role_assignment_id'])->references(['organization_id', 'id'])->on('organization_role_assignments')->restrictOnDelete();
            $table->foreign(['organization_id', 'team_membership_id'])->references(['organization_id', 'id'])->on('team_memberships')->restrictOnDelete();
            $table->index(['organization_id', 'team_membership_id']);
        });
        $diff = $postgres ? 'IS DISTINCT FROM' : 'IS NOT';
        $identity = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$diff} OLD.{$field}", ['id', 'organization_id', 'team_id', 'person_id', 'role_key', 'starts_at']));
        $invalid = "(NEW.ends_at IS NOT NULL AND NEW.ends_at<=NEW.starts_at) OR (NEW.status='closed' AND NEW.ends_at IS NULL)";
        $unavailable = "NOT EXISTS(SELECT 1 FROM teams WHERE id=NEW.team_id AND organization_id=NEW.organization_id AND status!='archived')
            OR NOT EXISTS(SELECT 1 FROM people WHERE id=NEW.person_id AND organization_id=NEW.organization_id AND status!='former')
            OR EXISTS(SELECT 1 FROM person_departures WHERE person_id=NEW.person_id AND organization_id=NEW.organization_id)";
        $overlap = "NEW.status!='cancelled' AND EXISTS(SELECT 1 FROM team_memberships m WHERE m.organization_id=NEW.organization_id
            AND m.team_id=NEW.team_id AND m.person_id=NEW.person_id AND m.id!=NEW.id AND m.status!='cancelled'
            AND (m.role_key=NEW.role_key OR (m.role_key IN('player','substitute') AND NEW.role_key IN('player','substitute')))
            AND (NEW.ends_at IS NULL OR m.starts_at<NEW.ends_at) AND (m.ends_at IS NULL OR m.ends_at>NEW.starts_at))";
        $changed = "({$identity}) OR OLD.status!='active' OR (OLD.ends_at IS NOT NULL AND (NEW.ends_at IS NULL OR NEW.ends_at>OLD.ends_at))";
        if ($postgres) {
            DB::unprepared("CREATE FUNCTION ucg_team_memberships_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Team history must be retained' USING ERRCODE='23514'; END IF;
                PERFORM id FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                IF ({$invalid}) OR ({$overlap}) THEN RAISE EXCEPTION 'Invalid team membership period' USING ERRCODE='23514'; END IF;
                IF TG_OP='INSERT' AND ({$unavailable}) THEN RAISE EXCEPTION 'Team membership context unavailable' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND ({$changed}) THEN RAISE EXCEPTION 'Team membership history is immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER team_memberships_guard BEFORE INSERT OR UPDATE OR DELETE ON team_memberships FOR EACH ROW EXECUTE FUNCTION ucg_team_memberships_guard();
                CREATE FUNCTION ucg_team_movements_guard() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Team movements are append-only' USING ERRCODE='23514'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER team_movements_guard BEFORE UPDATE OR DELETE ON team_movements FOR EACH ROW EXECUTE FUNCTION ucg_team_movements_guard();
                CREATE TRIGGER team_scope_grants_guard BEFORE UPDATE OR DELETE ON team_scope_grants FOR EACH ROW EXECUTE FUNCTION ucg_team_movements_guard()");
        } else {
            DB::unprepared("CREATE TRIGGER team_memberships_insert BEFORE INSERT ON team_memberships WHEN ({$invalid}) OR ({$unavailable}) OR ({$overlap})
                BEGIN SELECT RAISE(ABORT,'Invalid team membership period'); END");
            DB::unprepared("CREATE TRIGGER team_memberships_update BEFORE UPDATE ON team_memberships WHEN ({$invalid}) OR ({$changed}) OR ({$overlap})
                BEGIN SELECT RAISE(ABORT,'Team membership history is immutable'); END");
            DB::unprepared("CREATE TRIGGER team_memberships_delete BEFORE DELETE ON team_memberships BEGIN SELECT RAISE(ABORT,'Team history must be retained'); END");
            foreach (['team_movements', 'team_scope_grants'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    $key = strtolower($operation);
                    DB::unprepared("CREATE TRIGGER {$table}_{$key} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT,'Team movements and bindings are append-only'); END");
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::table('team_memberships')->exists() || DB::table('team_movements')->exists() || DB::table('team_scope_grants')->exists()) {
            throw new RuntimeException('TEAM-003 rollback cannot discard sporting history. Use a forward fix.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::drop('team_scope_grants');
        Schema::drop('team_movements');
        Schema::drop('team_memberships');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION ucg_team_movements_guard(); DROP FUNCTION ucg_team_memberships_guard()');
        }
    }
};
