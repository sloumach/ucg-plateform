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
            throw new RuntimeException('Registration tracking requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::create('competition_registration_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('competition_id');
            $t->unsignedSmallInteger('number');
            $t->text('payload');
            $t->text('reason');
            $t->unsignedBigInteger('actor_user_id');
            $t->timestampTz('created_at');
            $t->timestampTz('next_due_at')->nullable();
            $t->timestampTz('checked_at')->nullable();
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign(['organization_id', 'competition_id'], 'crr_competition')->references(['organization_id', 'id'])->on('external_competitions')->restrictOnDelete();
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'competition_id', 'number'], 'crr_sequence');
            $t->unique(['organization_id', 'competition_id', 'id'], 'crr_tenant_id');
            $t->index(['organization_id', 'checked_at', 'next_due_at', 'id'], 'crr_reminder_sweep');
        });
        Schema::create('competition_registration_reminders', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('competition_id');
            $t->uuid('revision_id');
            $t->uuid('item_id');
            $t->unsignedBigInteger('recipient_id');
            $t->string('occurrence', 64);
            $t->string('stage', 12);
            $t->text('payload');
            $t->uuid('run_id');
            $t->timestampTz('created_at');
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->foreign(['organization_id', 'competition_id', 'revision_id'], 'crn_revision')->references(['organization_id', 'competition_id', 'id'])->on('competition_registration_revisions')->restrictOnDelete();
            $t->foreign('recipient_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organization_id', 'competition_id', 'occurrence'], 'crn_occurrence');
            $t->index(['organization_id', 'competition_id', 'recipient_id', 'created_at', 'id'], 'crn_inbox');
        });
        $condition = "NEW.number<1 OR NEW.number>1000 OR NEW.number<>(SELECT COALESCE(MAX(r.number),0)+1 FROM competition_registration_revisions r WHERE r.organization_id=NEW.organization_id AND r.competition_id=NEW.competition_id) OR NOT EXISTS(SELECT 1 FROM external_competitions p WHERE p.organization_id=NEW.organization_id AND p.id=NEW.competition_id AND p.status='active')";
        $columns = ['id', 'organization_id', 'competition_id', 'number', 'payload', 'reason', 'actor_user_id', 'created_at', 'next_due_at'];
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER crr_insert BEFORE INSERT ON competition_registration_revisions WHEN {$condition} BEGIN SELECT RAISE(ABORT,'Invalid registration revision'); END");
            $unchanged = implode(' OR ', array_map(fn (string $c): string => "NEW.{$c} IS NOT OLD.{$c}", $columns));
            DB::unprepared("CREATE TRIGGER crr_update BEFORE UPDATE ON competition_registration_revisions WHEN {$unchanged} OR NEW.checked_at IS NULL OR (OLD.checked_at IS NOT NULL AND NEW.checked_at<OLD.checked_at) BEGIN SELECT RAISE(ABORT,'Immutable registration revision'); END");
            DB::unprepared("CREATE TRIGGER crr_delete BEFORE DELETE ON competition_registration_revisions BEGIN SELECT RAISE(ABORT,'Retain registration history'); END");
            DB::unprepared("CREATE TRIGGER crn_insert BEFORE INSERT ON competition_registration_reminders WHEN NEW.stage NOT IN ('week','day','overdue') OR NOT EXISTS(SELECT 1 FROM external_competitions p WHERE p.organization_id=NEW.organization_id AND p.id=NEW.competition_id AND p.status='active') BEGIN SELECT RAISE(ABORT,'Invalid registration reminder'); END");
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared('CREATE TRIGGER crn_'.strtolower($action)." BEFORE {$action} ON competition_registration_reminders BEGIN SELECT RAISE(ABORT,'Retain registration reminder'); END");
            }
        } else {
            $unchanged = implode(' OR ', array_map(fn (string $c): string => "NEW.{$c} IS DISTINCT FROM OLD.{$c}", $columns));
            DB::unprepared("CREATE FUNCTION crr_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain registration history'; END IF;
    IF TG_OP='UPDATE' THEN IF {$unchanged} OR NEW.checked_at IS NULL OR (OLD.checked_at IS NOT NULL AND NEW.checked_at<OLD.checked_at) THEN RAISE EXCEPTION 'Immutable registration revision'; END IF;
    ELSE PERFORM 1 FROM external_competitions WHERE organization_id=NEW.organization_id AND id=NEW.competition_id FOR UPDATE;
    IF {$condition} THEN RAISE EXCEPTION 'Invalid registration revision'; END IF; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER crr_guard BEFORE INSERT OR UPDATE OR DELETE ON competition_registration_revisions FOR EACH ROW EXECUTE FUNCTION crr_guard()');
            DB::unprepared("CREATE FUNCTION crn_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Retain registration reminder'; END IF;
    PERFORM 1 FROM external_competitions WHERE organization_id=NEW.organization_id AND id=NEW.competition_id FOR UPDATE;
    IF NEW.stage NOT IN ('week','day','overdue') OR NOT EXISTS(SELECT 1 FROM external_competitions p WHERE p.organization_id=NEW.organization_id AND p.id=NEW.competition_id AND p.status='active') THEN RAISE EXCEPTION 'Invalid registration reminder'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER crn_guard BEFORE INSERT OR UPDATE OR DELETE ON competition_registration_reminders FOR EACH ROW EXECUTE FUNCTION crn_guard()');
        }
    }

    public function down(): void
    {
        foreach (['competition_registration_reminders', 'competition_registration_revisions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Registration history must be retained.');
            }
        }
        Schema::dropIfExists('competition_registration_reminders');
        Schema::dropIfExists('competition_registration_revisions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS crn_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS crr_guard()');
        }
    }
};
