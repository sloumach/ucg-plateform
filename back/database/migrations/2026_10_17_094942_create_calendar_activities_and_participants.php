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
            throw new RuntimeException('Calendar constraints require SQLite or PostgreSQL.');
        }
        Schema::create('calendar_activities', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->text('title');
            $t->text('description')->nullable();
            $t->enum('kind', ['training', 'scrim', 'match', 'meeting', 'academy', 'bootcamp', 'sponsor', 'travel']);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('origin_timezone', 100);
            $t->uuid('team_id')->nullable();
            $t->foreign(['organization_id', 'team_id'], 'calendar_team_tenant')->references(['organization_id', 'id'])->on('teams')->restrictOnDelete();
            $t->text('location')->nullable();
            $t->text('url')->nullable();
            $t->enum('visibility', ['private', 'team', 'organization'])->default('private');
            $t->foreignId('sharing_authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('sharing_authorized_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('archived_at')->nullable();
            $t->uuid('version');
            $t->timestamps();
            $t->unique(['organization_id', 'id'], 'calendar_tenant_id');
            $t->index(['organization_id', 'archived_at', 'starts_at', 'id'], 'calendar_window');
            $t->index(['organization_id', 'team_id', 'starts_at'], 'calendar_team_window');
        });
        Schema::create('calendar_participants', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('activity_id');
            $t->uuid('person_id');
            $t->foreign(['organization_id', 'activity_id'], 'calendar_participant_activity')->references(['organization_id', 'id'])->on('calendar_activities')->restrictOnDelete();
            $t->foreign(['organization_id', 'person_id'], 'calendar_participant_person')->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'activity_id', 'person_id'], 'calendar_participant_once');
            $t->index(['organization_id', 'person_id', 'activity_id'], 'calendar_person_visibility');
        });
        $period = DB::getDriverName() === 'sqlite'
            ? 'julianday(NEW.ends_at) > julianday(NEW.starts_at) AND julianday(NEW.ends_at)-julianday(NEW.starts_at) <= 31'
            : "NEW.ends_at > NEW.starts_at AND NEW.ends_at <= NEW.starts_at + INTERVAL '31 days'";
        $shape = "($period) AND (NEW.visibility <> 'team' OR NEW.team_id IS NOT NULL)
            AND ((NEW.visibility = 'private' AND NEW.sharing_authorized_by IS NULL AND NEW.sharing_authorized_at IS NULL)
                OR (NEW.visibility IN ('team','organization') AND NEW.sharing_authorized_by IS NOT NULL AND NEW.sharing_authorized_at IS NOT NULL))";
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $op) {
                DB::unprepared('CREATE TRIGGER calendar_shape_'.strtolower($op)." BEFORE $op ON calendar_activities
                    WHEN NOT ($shape) BEGIN SELECT RAISE(ABORT, 'Invalid calendar activity'); END");
            }
            DB::unprepared("CREATE TRIGGER calendar_identity BEFORE UPDATE ON calendar_activities
                WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.created_by IS NOT OLD.created_by
                  OR NEW.created_at IS NOT OLD.created_at OR OLD.archived_at IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'Immutable calendar activity'); END");
            DB::unprepared("CREATE TRIGGER calendar_no_delete BEFORE DELETE ON calendar_activities BEGIN SELECT RAISE(ABORT, 'Archive calendar activity instead'); END");
            DB::unprepared("CREATE TRIGGER calendar_participant_no_update BEFORE UPDATE ON calendar_participants BEGIN SELECT RAISE(ABORT, 'Replace calendar participant explicitly'); END");
            foreach (['INSERT' => 'NEW', 'DELETE' => 'OLD'] as $op => $row) {
                DB::unprepared('CREATE TRIGGER calendar_participant_'.strtolower($op)." BEFORE $op ON calendar_participants
                    WHEN EXISTS(SELECT 1 FROM calendar_activities WHERE organization_id=$row.organization_id AND id=$row.activity_id AND archived_at IS NOT NULL)
                    BEGIN SELECT RAISE(ABORT, 'Archived calendar participants are retained'); END");
            }
        } else {
            DB::unprepared("CREATE FUNCTION calendar_activity_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Archive calendar activity instead'; END IF;
                    IF TG_OP='UPDATE' AND (NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at OR OLD.archived_at IS NOT NULL)
                        THEN RAISE EXCEPTION 'Immutable calendar activity'; END IF;
                    IF NOT ($shape) THEN RAISE EXCEPTION 'Invalid calendar activity'; END IF;
                    RETURN NEW;
                END; \$\$;
                CREATE TRIGGER calendar_activity_guard BEFORE INSERT OR UPDATE OR DELETE ON calendar_activities FOR EACH ROW EXECUTE FUNCTION calendar_activity_guard()");
            DB::unprepared("CREATE FUNCTION calendar_participant_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                DECLARE parent_id uuid; tenant_id uuid;
                BEGIN
                    IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Replace calendar participant explicitly'; END IF;
                    IF TG_OP='DELETE' THEN parent_id=OLD.activity_id; tenant_id=OLD.organization_id;
                    ELSE parent_id=NEW.activity_id; tenant_id=NEW.organization_id; END IF;
                    IF EXISTS(SELECT 1 FROM calendar_activities WHERE id=parent_id AND organization_id=tenant_id AND archived_at IS NOT NULL)
                        THEN RAISE EXCEPTION 'Archived calendar participants are retained'; END IF;
                    IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END; \$\$;
                CREATE TRIGGER calendar_participant_guard BEFORE INSERT OR UPDATE OR DELETE ON calendar_participants FOR EACH ROW EXECUTE FUNCTION calendar_participant_guard()");
        }
    }

    public function down(): void
    {
        foreach (['calendar_participants', 'calendar_activities'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Calendar records exist. Use a forward migration instead.');
            }
        }
        Schema::dropIfExists('calendar_participants');
        Schema::dropIfExists('calendar_activities');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS calendar_activity_guard(); DROP FUNCTION IF EXISTS calendar_participant_guard()');
        }
    }
};
