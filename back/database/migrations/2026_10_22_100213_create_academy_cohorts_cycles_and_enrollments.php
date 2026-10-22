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
            throw new RuntimeException('ACA-001 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('academy_cohorts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name', 120);
            $table->enum('status', ['active', 'archived']);
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'created_at', 'id'], 'academy_cohort_page');
        });
        Schema::create('academy_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('cohort_id');
            $table->foreign(['organization_id', 'cohort_id'])->references(['organization_id', 'id'])->on('academy_cohorts')->restrictOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('timezone', 64);
            $table->json('timetable');
            $table->enum('status', ['active', 'archived']);
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'cohort_id', 'id'], 'academy_cycle_tenant_key');
            $table->index(['organization_id', 'cohort_id', 'created_at', 'id'], 'academy_cycle_page');
        });
        Schema::create('academy_enrollments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('cohort_id');
            $table->uuid('cycle_id');
            $table->foreign(['organization_id', 'cohort_id', 'cycle_id'], 'academy_enrollment_cycle_fk')
                ->references(['organization_id', 'cohort_id', 'id'])->on('academy_cycles')->restrictOnDelete();
            $table->uuid('person_id');
            $table->foreign(['organization_id', 'person_id'])->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $table->enum('role', ['student', 'mentor']);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->enum('status', ['active', 'withdrawn', 'completed']);
            $table->uuid('version');
            $table->timestamps();
            $table->index(['organization_id', 'cohort_id', 'created_at', 'id'], 'academy_enrollment_page');
            $table->index(['organization_id', 'cycle_id', 'person_id', 'status', 'starts_on', 'ends_on'], 'academy_enrollment_overlap');
        });
        Schema::create('academy_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('cohort_id');
            $table->foreign(['organization_id', 'cohort_id'])->references(['organization_id', 'id'])->on('academy_cohorts')->restrictOnDelete();
            $table->enum('kind', ['cohort', 'cycle', 'enrollment']);
            $table->uuid('resource_id');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('snapshot');
            $table->timestampTz('recorded_at');
            $table->index(['organization_id', 'cohort_id', 'recorded_at', 'id'], 'academy_revision_page');
        });
        $this->guards();
    }

    private function guards(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        $different = $pg ? 'IS DISTINCT FROM' : 'IS NOT';
        foreach (['academy_cohorts', 'academy_cycles', 'academy_enrollments', 'academy_revisions'] as $table) {
            $immutable = ['id', 'organization_id'];
            if ($table !== 'academy_cohorts') {
                $immutable[] = 'cohort_id';
            }
            if ($table === 'academy_enrollments') {
                $immutable = [...$immutable, 'cycle_id', 'person_id', 'role', 'starts_on', 'ends_on'];
            }
            $identity = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$different} OLD.{$field}", $immutable));
            $invalid = match ($table) {
                'academy_cohorts' => 'LENGTH(TRIM(NEW.name))=0',
                'academy_cycles' => 'LENGTH(TRIM(NEW.name))=0 OR NEW.starts_on>NEW.ends_on',
                'academy_enrollments' => 'NEW.starts_on>NEW.ends_on OR NOT EXISTS (SELECT 1 FROM academy_cycles c WHERE c.organization_id=NEW.organization_id AND c.cohort_id=NEW.cohort_id AND c.id=NEW.cycle_id AND NEW.starts_on>=c.starts_on AND NEW.ends_on<=c.ends_on)',
                default => 'LENGTH(NEW.snapshot)=0',
            };
            $closed = $table === 'academy_enrollments' ? "OLD.status!='active'" : "OLD.status='archived'";
            $update = $table === 'academy_revisions' ? '1=1' : "({$identity}) OR ({$invalid}) OR ({$closed})";
            if ($pg) {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_guard() RETURNS trigger AS $$ BEGIN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Academy history is retained' USING ERRCODE='23514'; END IF;
                    IF TG_OP='UPDATE' THEN
                        IF ({$update}) THEN RAISE EXCEPTION 'Academy identity or history is immutable' USING ERRCODE='23514'; END IF;
                    END IF;
                    IF ({$invalid}) THEN RAISE EXCEPTION 'Invalid academy record' USING ERRCODE='23514'; END IF;
                    RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_guard()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN {$invalid}
                    BEGIN SELECT RAISE(ABORT, 'Invalid academy record'); END");
                DB::unprepared("CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN {$update}
                    BEGIN SELECT RAISE(ABORT, 'Academy identity or history is immutable'); END");
                DB::unprepared("CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table}
                    BEGIN SELECT RAISE(ABORT, 'Academy history is retained'); END");
            }
        }
    }

    public function down(): void
    {
        $tables = ['academy_revisions', 'academy_enrollments', 'academy_cycles', 'academy_cohorts'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('ACA-001 rollback cannot discard history. Use a forward fix.');
            }
        }
        foreach ($tables as $table) {
            Schema::drop($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP FUNCTION ucg_{$table}_guard()");
            }
        }
    }
};
