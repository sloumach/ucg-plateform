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
            throw new RuntimeException('Calendar booking constraints require SQLite or PostgreSQL.');
        }
        Schema::create('calendar_resources', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->string('key', 80);
            $t->text('label');
            $t->enum('kind', ['room', 'equipment', 'other']);
            $t->enum('status', ['active', 'archived'])->default('active');
            $t->uuid('version');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'id'], 'calendar_resource_tenant');
            $t->unique(['organization_id', 'key'], 'calendar_resource_key');
            $t->index(['organization_id', 'status', 'key', 'id'], 'calendar_resource_list');
        });
        Schema::create('calendar_reservations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('activity_id');
            $t->uuid('resource_id');
            $t->timestampTz('created_at');
            $t->foreign(['organization_id', 'activity_id'], 'calendar_reservation_activity')->references(['organization_id', 'id'])->on('calendar_activities')->restrictOnDelete();
            $t->foreign(['organization_id', 'resource_id'], 'calendar_reservation_resource')->references(['organization_id', 'id'])->on('calendar_resources')->restrictOnDelete();
            $t->unique(['organization_id', 'activity_id', 'resource_id'], 'calendar_reservation_once');
            $t->index(['organization_id', 'resource_id', 'activity_id'], 'calendar_resource_bookings');
        });
        Schema::create('calendar_conflict_overrides', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('activity_id');
            $t->uuid('activity_version');
            $t->foreign(['organization_id', 'activity_id'], 'calendar_override_activity')->references(['organization_id', 'id'])->on('calendar_activities')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->text('snapshot');
            $t->unsignedInteger('conflict_count');
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'activity_id', 'activity_version'], 'calendar_override_version');
            $t->index(['organization_id', 'activity_id', 'created_at', 'id'], 'calendar_override_history');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER calendar_resource_identity BEFORE UPDATE ON calendar_resources
                WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.key IS NOT OLD.key
                  OR NEW.kind IS NOT OLD.kind OR NEW.created_by IS NOT OLD.created_by OR NEW.created_at IS NOT OLD.created_at OR OLD.status='archived'
                BEGIN SELECT RAISE(ABORT,'Immutable calendar resource'); END");
            DB::unprepared("CREATE TRIGGER calendar_resource_no_delete BEFORE DELETE ON calendar_resources BEGIN SELECT RAISE(ABORT,'Archive resource instead'); END");
            DB::unprepared("CREATE TRIGGER calendar_reservation_no_update BEFORE UPDATE ON calendar_reservations BEGIN SELECT RAISE(ABORT,'Replace reservation explicitly'); END");
            foreach (['INSERT' => 'NEW', 'DELETE' => 'OLD'] as $op => $row) {
                DB::unprepared('CREATE TRIGGER calendar_reservation_'.strtolower($op)." BEFORE $op ON calendar_reservations
                    WHEN EXISTS(SELECT 1 FROM calendar_activities WHERE id=$row.activity_id AND organization_id=$row.organization_id AND archived_at IS NOT NULL)
                    BEGIN SELECT RAISE(ABORT,'Archived reservations are retained'); END");
            }
            foreach (['UPDATE', 'DELETE'] as $op) {
                DB::unprepared('CREATE TRIGGER calendar_override_no_'.strtolower($op)." BEFORE $op ON calendar_conflict_overrides BEGIN SELECT RAISE(ABORT,'Immutable conflict exception'); END");
            }
            DB::unprepared("CREATE TRIGGER calendar_override_shape BEFORE INSERT ON calendar_conflict_overrides WHEN NEW.conflict_count<1 OR NEW.conflict_count>100
                BEGIN SELECT RAISE(ABORT,'Invalid conflict snapshot'); END");
        } else {
            DB::unprepared("CREATE FUNCTION calendar_resource_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Archive resource instead'; END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.key IS DISTINCT FROM OLD.key
                    OR NEW.kind IS DISTINCT FROM OLD.kind OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at OR OLD.status='archived'
                    THEN RAISE EXCEPTION 'Immutable calendar resource'; END IF; RETURN NEW; END; \$\$;
                CREATE TRIGGER calendar_resource_guard BEFORE UPDATE OR DELETE ON calendar_resources FOR EACH ROW EXECUTE FUNCTION calendar_resource_guard()");
            DB::unprepared("CREATE FUNCTION calendar_reservation_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                DECLARE parent_id uuid; tenant_id uuid;
                BEGIN
                    IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Replace reservation explicitly'; END IF;
                    IF TG_OP='DELETE' THEN parent_id=OLD.activity_id; tenant_id=OLD.organization_id; ELSE parent_id=NEW.activity_id; tenant_id=NEW.organization_id; END IF;
                    IF EXISTS(SELECT 1 FROM calendar_activities WHERE id=parent_id AND organization_id=tenant_id AND archived_at IS NOT NULL)
                        THEN RAISE EXCEPTION 'Archived reservations are retained'; END IF;
                    IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW; END; \$\$;
                CREATE TRIGGER calendar_reservation_guard BEFORE INSERT OR UPDATE OR DELETE ON calendar_reservations FOR EACH ROW EXECUTE FUNCTION calendar_reservation_guard()");
            DB::unprepared("CREATE FUNCTION calendar_override_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable conflict exception'; END IF;
                IF NEW.conflict_count<1 OR NEW.conflict_count>100 THEN RAISE EXCEPTION 'Invalid conflict snapshot'; END IF;
                RETURN NEW; END; \$\$;
                CREATE TRIGGER calendar_override_guard BEFORE INSERT OR UPDATE OR DELETE ON calendar_conflict_overrides FOR EACH ROW EXECUTE FUNCTION calendar_override_guard()");
        }
    }

    public function down(): void
    {
        foreach (['calendar_conflict_overrides', 'calendar_reservations', 'calendar_resources'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Calendar booking data exists. Use a forward migration.');
            }
        }
        Schema::dropIfExists('calendar_conflict_overrides');
        Schema::dropIfExists('calendar_reservations');
        Schema::dropIfExists('calendar_resources');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS calendar_resource_guard(); DROP FUNCTION IF EXISTS calendar_reservation_guard(); DROP FUNCTION IF EXISTS calendar_override_guard()');
        }
    }
};
