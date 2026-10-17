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
            throw new RuntimeException('Calendar changes require SQLite or PostgreSQL.');
        }
        Schema::table('calendar_activities', fn (Blueprint $t) => $t->timestampTz('cancelled_at')->nullable());
        Schema::create('calendar_changes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('activity_id');
            $t->uuid('previous_version');
            $t->uuid('activity_version');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->enum('kind', ['rescheduled', 'cancelled']);
            $t->text('title');
            $t->text('reason');
            $t->timestampTz('previous_starts_at');
            $t->timestampTz('previous_ends_at');
            $t->string('previous_timezone', 100);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('origin_timezone', 100);
            $t->timestampTz('created_at');
            $t->foreign(['organization_id', 'activity_id'])->references(['organization_id', 'id'])->on('calendar_activities')->restrictOnDelete();
            $t->unique(['organization_id', 'id']);
            $t->unique(['organization_id', 'activity_id', 'activity_version'], 'calendar_change_version');
            $t->index(['organization_id', 'activity_id', 'created_at', 'id'], 'calendar_change_history');
        });
        Schema::create('calendar_change_recipients', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('change_id');
            $t->uuid('person_id');
            $t->foreignId('recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->timestampTz('checked_at')->nullable();
            $t->timestampTz('delivered_at')->nullable();
            $t->uuid('run_id')->nullable();
            $t->enum('blocked_reason', ['issuer_unavailable', 'recipient_unavailable', 'hidden_scope'])->nullable();
            $t->foreign(['organization_id', 'change_id'])->references(['organization_id', 'id'])->on('calendar_changes')->restrictOnDelete();
            $t->foreign(['organization_id', 'person_id'])->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $t->unique(['organization_id', 'change_id', 'person_id'], 'calendar_change_person_once');
            $t->unique(['organization_id', 'change_id', 'recipient_id'], 'calendar_change_account_once');
            $t->index(['organization_id', 'recipient_id', 'person_id', 'delivered_at', 'id'], 'calendar_change_inbox');
            $t->index(['organization_id', 'delivered_at', 'checked_at', 'id'], 'calendar_change_sweep');
        });
        $identity = ['id', 'organization_id', 'change_id', 'person_id', 'recipient_id', 'created_at'];
        $cancel = 'NEW.cancelled_at IS NOT NULL AND (NEW.archived_at IS NULL OR NEW.archived_at <> NEW.cancelled_at)';
        $shape = '((NEW.delivered_at IS NULL) <> (NEW.run_id IS NULL))';
        $period = 'NEW.ends_at <= NEW.starts_at OR NEW.previous_ends_at <= NEW.previous_starts_at OR NEW.previous_version = NEW.activity_version';
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $op) {
                DB::unprepared('CREATE TRIGGER calendar_cancel_'.strtolower($op)." BEFORE $op ON calendar_activities WHEN $cancel BEGIN SELECT RAISE(ABORT,'Invalid calendar cancellation'); END");
                DB::unprepared('CREATE TRIGGER calendar_change_recipient_shape_'.strtolower($op)." BEFORE $op ON calendar_change_recipients WHEN $shape BEGIN SELECT RAISE(ABORT,'Invalid calendar change delivery'); END");
            }
            DB::unprepared("CREATE TRIGGER calendar_change_shape BEFORE INSERT ON calendar_changes WHEN $period BEGIN SELECT RAISE(ABORT,'Invalid calendar change period'); END");
            foreach (['UPDATE', 'DELETE'] as $op) {
                DB::unprepared('CREATE TRIGGER calendar_change_no_'.strtolower($op)." BEFORE $op ON calendar_changes BEGIN SELECT RAISE(ABORT,'Immutable calendar change'); END");
            }
            $changes = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS NOT OLD.$c", $identity));
            DB::unprepared("CREATE TRIGGER calendar_change_recipient_identity BEFORE UPDATE ON calendar_change_recipients WHEN $changes OR (OLD.delivered_at IS NOT NULL AND (NEW.delivered_at IS NOT OLD.delivered_at OR NEW.run_id IS NOT OLD.run_id)) BEGIN SELECT RAISE(ABORT,'Immutable calendar change delivery'); END");
            DB::unprepared("CREATE TRIGGER calendar_change_recipient_no_delete BEFORE DELETE ON calendar_change_recipients BEGIN SELECT RAISE(ABORT,'Retain calendar change delivery'); END");
        } else {
            DB::unprepared("CREATE FUNCTION calendar_cancel_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $cancel THEN RAISE EXCEPTION 'Invalid calendar cancellation'; END IF; RETURN NEW; END; \$\$;
                CREATE TRIGGER calendar_cancel_guard BEFORE INSERT OR UPDATE ON calendar_activities FOR EACH ROW EXECUTE FUNCTION calendar_cancel_guard()");
            DB::unprepared("CREATE FUNCTION calendar_change_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable calendar change'; END IF;
                IF $period THEN RAISE EXCEPTION 'Invalid calendar change period'; END IF; RETURN NEW; END; \$\$;
                CREATE TRIGGER calendar_change_guard BEFORE INSERT OR UPDATE OR DELETE ON calendar_changes FOR EACH ROW EXECUTE FUNCTION calendar_change_guard()");
            $changes = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS DISTINCT FROM OLD.$c", $identity));
            DB::unprepared("CREATE FUNCTION calendar_change_recipient_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain calendar change delivery'; END IF;
                IF $shape THEN RAISE EXCEPTION 'Invalid calendar change delivery'; END IF;
                IF TG_OP='UPDATE' AND ($changes OR (OLD.delivered_at IS NOT NULL AND (NEW.delivered_at IS DISTINCT FROM OLD.delivered_at OR NEW.run_id IS DISTINCT FROM OLD.run_id))) THEN RAISE EXCEPTION 'Immutable calendar change delivery'; END IF;
                RETURN NEW; END; \$\$;
                CREATE TRIGGER calendar_change_recipient_guard BEFORE INSERT OR UPDATE OR DELETE ON calendar_change_recipients FOR EACH ROW EXECUTE FUNCTION calendar_change_recipient_guard()");
        }
    }

    public function down(): void
    {
        foreach (['calendar_change_recipients', 'calendar_changes'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Calendar change history exists. Use a forward migration.');
            }
        }
        if (Schema::hasColumn('calendar_activities', 'cancelled_at') && DB::table('calendar_activities')->whereNotNull('cancelled_at')->exists()) {
            throw new RuntimeException('Calendar cancellations exist. Use a forward migration.');
        }
        Schema::dropIfExists('calendar_change_recipients');
        Schema::dropIfExists('calendar_changes');
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS calendar_cancel_insert; DROP TRIGGER IF EXISTS calendar_cancel_update');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS calendar_cancel_guard ON calendar_activities; DROP FUNCTION IF EXISTS calendar_cancel_guard(); DROP FUNCTION IF EXISTS calendar_change_guard(); DROP FUNCTION IF EXISTS calendar_change_recipient_guard()');
        }
        Schema::table('calendar_activities', fn (Blueprint $t) => $t->dropColumn('cancelled_at'));
    }
};
