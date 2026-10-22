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
            throw new RuntimeException('ACA-002 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::table('academy_enrollments', fn (Blueprint $table) => $table->unique(['organization_id', 'cycle_id', 'id'], 'academy_enrollment_cycle_identity'));
        Schema::create('academy_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('cohort_id');
            $table->uuid('cycle_id');
            $table->foreign(['organization_id', 'cohort_id', 'cycle_id'], 'academy_session_cycle_fk')->references(['organization_id', 'cohort_id', 'id'])->on('academy_cycles')->restrictOnDelete();
            $table->uuid('activity_id');
            $table->foreign(['organization_id', 'activity_id'], 'academy_session_activity_fk')->references(['organization_id', 'id'])->on('calendar_activities')->restrictOnDelete();
            $table->uuid('activity_version');
            $table->uuid('responsible_enrollment_id');
            $table->foreign(['organization_id', 'cycle_id', 'responsible_enrollment_id'], 'academy_session_mentor_fk')->references(['organization_id', 'cycle_id', 'id'])->on('academy_enrollments')->restrictOnDelete();
            $table->text('title');
            $table->text('exercises');
            $table->text('report')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->enum('status', ['planned', 'completed', 'cancelled']);
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'academy_session_identity');
            $table->unique(['organization_id', 'cycle_id', 'id'], 'academy_session_cycle_identity');
            $table->unique(['organization_id', 'activity_id'], 'academy_session_activity_once');
            $table->index(['organization_id', 'cycle_id', 'starts_at', 'id'], 'academy_session_page');
        });
        Schema::create('academy_attendances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('cycle_id');
            $table->uuid('session_id');
            $table->uuid('enrollment_id');
            $table->foreign(['organization_id', 'cycle_id', 'session_id'], 'academy_attendance_session_fk')->references(['organization_id', 'cycle_id', 'id'])->on('academy_sessions')->restrictOnDelete();
            $table->foreign(['organization_id', 'cycle_id', 'enrollment_id'], 'academy_attendance_enrollment_fk')->references(['organization_id', 'cycle_id', 'id'])->on('academy_enrollments')->restrictOnDelete();
            $table->enum('status', ['present', 'absent', 'excused', 'late']);
            $table->unsignedSmallInteger('late_minutes')->nullable();
            $table->text('change_reason')->nullable();
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'session_id', 'enrollment_id'], 'academy_attendance_once');
            $table->index(['organization_id', 'session_id', 'created_at', 'id'], 'academy_attendance_page');
        });
        Schema::create('academy_session_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('session_id');
            $table->foreign(['organization_id', 'session_id'], 'academy_revision_session_fk')->references(['organization_id', 'id'])->on('academy_sessions')->restrictOnDelete();
            $table->enum('kind', ['session', 'attendance']);
            $table->uuid('resource_id');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('snapshot');
            $table->timestampTz('recorded_at');
            $table->index(['organization_id', 'session_id', 'recorded_at', 'id'], 'academy_session_revision_page');
        });
        $this->guards();
    }

    private function guards(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        $different = $pg ? 'IS DISTINCT FROM' : 'IS NOT';
        foreach (['academy_sessions', 'academy_attendances', 'academy_session_revisions'] as $table) {
            $fields = $table === 'academy_sessions' ? ['id', 'organization_id', 'cohort_id', 'cycle_id'] : ['id', 'organization_id', 'session_id'];
            if ($table === 'academy_attendances') {
                $fields = [...$fields, 'cycle_id', 'enrollment_id'];
            }
            $identity = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$different} OLD.{$field}", $fields));
            $invalid = match ($table) {
                'academy_sessions' => 'NEW.starts_at>=NEW.ends_at',
                'academy_attendances' => "(NEW.status='late' AND (NEW.late_minutes IS NULL OR NEW.late_minutes<1 OR NEW.late_minutes>240)) OR (NEW.status!='late' AND NEW.late_minutes IS NOT NULL)",
                default => 'LENGTH(NEW.snapshot)=0',
            };
            $update = $table === 'academy_session_revisions' ? '1=1' : "({$identity}) OR ({$invalid})";
            if ($table === 'academy_sessions') {
                $frozen = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$different} OLD.{$field}", ['activity_id', 'responsible_enrollment_id', 'starts_at', 'ends_at']));
                $update .= " OR OLD.status='cancelled' OR (({$frozen}) AND EXISTS (SELECT 1 FROM academy_attendances WHERE organization_id=OLD.organization_id AND session_id=OLD.id))";
            }
            if ($pg) {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_guard() RETURNS trigger AS $$ BEGIN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Academy session history is retained' USING ERRCODE='23514'; END IF;
                    IF TG_OP='UPDATE' THEN IF ({$update}) THEN RAISE EXCEPTION 'Immutable academy session identity or history' USING ERRCODE='23514'; END IF; END IF;
                    IF ({$invalid}) THEN RAISE EXCEPTION 'Invalid academy session record' USING ERRCODE='23514'; END IF;
                    RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_guard()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Invalid academy session record'); END");
                DB::unprepared("CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN {$update} BEGIN SELECT RAISE(ABORT, 'Immutable academy session identity or history'); END");
                DB::unprepared("CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Academy session history is retained'); END");
            }
        }
    }

    public function down(): void
    {
        $tables = ['academy_session_revisions', 'academy_attendances', 'academy_sessions'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('ACA-002 rollback cannot discard session history. Use a forward fix.');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        foreach ($tables as $table) {
            Schema::drop($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP FUNCTION ucg_{$table}_guard()");
            }
        }
        Schema::table('academy_enrollments', fn (Blueprint $table) => $table->dropUnique('academy_enrollment_cycle_identity'));
    }
};
