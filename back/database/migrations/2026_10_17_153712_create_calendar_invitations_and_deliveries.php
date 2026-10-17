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
            throw new RuntimeException('Calendar invitations require SQLite or PostgreSQL.');
        }
        Schema::create('calendar_invitations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('activity_id');
            $t->uuid('activity_version');
            $t->uuid('person_id');
            $t->foreignId('recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('response_deadline');
            $t->jsonb('reminder_offsets');
            $t->enum('response', ['pending', 'present', 'absent'])->default('pending');
            $t->timestampTz('responded_at')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampTz('checked_at')->nullable();
            $t->string('blocked_reason', 40)->nullable();
            $t->uuid('version');
            $t->timestamps();
            $t->foreign(['organization_id', 'activity_id'], 'calendar_invitation_activity')->references(['organization_id', 'id'])->on('calendar_activities')->restrictOnDelete();
            $t->foreign(['organization_id', 'person_id'], 'calendar_invitation_person')->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $t->unique(['organization_id', 'id'], 'calendar_invitation_tenant');
            $t->unique(['organization_id', 'activity_id', 'activity_version', 'person_id'], 'calendar_invitation_once');
            $t->index(['organization_id', 'recipient_id', 'person_id', 'id'], 'calendar_invitation_inbox');
            $t->index(['organization_id', 'response', 'checked_at', 'id'], 'calendar_invitation_sweep');
        });
        Schema::create('calendar_invitation_deliveries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('invitation_id');
            $t->enum('kind', ['invitation', 'reminder']);
            $t->unsignedInteger('offset_minutes');
            $t->uuid('run_id');
            $t->timestampTz('created_at');
            $t->foreign(['organization_id', 'invitation_id'], 'calendar_delivery_invitation')->references(['organization_id', 'id'])->on('calendar_invitations')->restrictOnDelete();
            $t->unique(['organization_id', 'invitation_id', 'kind', 'offset_minutes'], 'calendar_delivery_once');
            $t->index(['organization_id', 'invitation_id', 'created_at', 'id'], 'calendar_delivery_history');
        });
        $identity = ['id', 'organization_id', 'activity_id', 'activity_version', 'person_id', 'recipient_id', 'issued_by', 'response_deadline', 'reminder_offsets', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            $changes = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS NOT OLD.$c", $identity));
            DB::unprepared("CREATE TRIGGER calendar_invitation_identity BEFORE UPDATE ON calendar_invitations WHEN $changes BEGIN SELECT RAISE(ABORT,'Immutable invitation identity'); END");
            DB::unprepared("CREATE TRIGGER calendar_invitation_no_delete BEFORE DELETE ON calendar_invitations BEGIN SELECT RAISE(ABORT,'Retain invitation history'); END");
            foreach (['UPDATE', 'DELETE'] as $op) {
                DB::unprepared('CREATE TRIGGER calendar_delivery_no_'.strtolower($op)." BEFORE $op ON calendar_invitation_deliveries BEGIN SELECT RAISE(ABORT,'Immutable invitation delivery'); END");
            }
            DB::unprepared("CREATE TRIGGER calendar_delivery_shape BEFORE INSERT ON calendar_invitation_deliveries WHEN (NEW.kind='invitation' AND NEW.offset_minutes<>0) OR (NEW.kind='reminder' AND (NEW.offset_minutes<1 OR NEW.offset_minutes>10080)) BEGIN SELECT RAISE(ABORT,'Invalid invitation delivery'); END");
        } else {
            $changes = implode(' OR ', array_map(fn (string $c): string => "NEW.$c IS DISTINCT FROM OLD.$c", $identity));
            DB::unprepared("CREATE FUNCTION calendar_invitation_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain invitation history'; END IF;
                IF $changes THEN RAISE EXCEPTION 'Immutable invitation identity'; END IF; RETURN NEW; END; \$\$;
                CREATE TRIGGER calendar_invitation_guard BEFORE UPDATE OR DELETE ON calendar_invitations FOR EACH ROW EXECUTE FUNCTION calendar_invitation_guard()");
            DB::unprepared("CREATE FUNCTION calendar_delivery_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable invitation delivery'; END IF;
                IF (NEW.kind='invitation' AND NEW.offset_minutes<>0) OR (NEW.kind='reminder' AND (NEW.offset_minutes<1 OR NEW.offset_minutes>10080)) THEN RAISE EXCEPTION 'Invalid invitation delivery'; END IF;
                RETURN NEW; END; \$\$;
                CREATE TRIGGER calendar_delivery_guard BEFORE INSERT OR UPDATE OR DELETE ON calendar_invitation_deliveries FOR EACH ROW EXECUTE FUNCTION calendar_delivery_guard()");
        }
    }

    public function down(): void
    {
        foreach (['calendar_invitation_deliveries', 'calendar_invitations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Invitation history exists. Use a forward migration.');
            }
        }
        Schema::dropIfExists('calendar_invitation_deliveries');
        Schema::dropIfExists('calendar_invitations');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS calendar_invitation_guard(); DROP FUNCTION IF EXISTS calendar_delivery_guard()');
        }
    }
};
