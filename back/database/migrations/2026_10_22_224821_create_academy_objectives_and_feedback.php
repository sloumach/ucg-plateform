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
            throw new RuntimeException('ACA-003 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('academy_objectives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('cohort_id');
            $table->uuid('cycle_id');
            $table->foreign(['organization_id', 'cohort_id', 'cycle_id'], 'academy_objective_cycle_fk')->references(['organization_id', 'cohort_id', 'id'])->on('academy_cycles')->restrictOnDelete();
            $table->uuid('target_enrollment_id')->nullable();
            $table->uuid('responsible_enrollment_id');
            foreach (['target_enrollment_id' => 'target', 'responsible_enrollment_id' => 'mentor'] as $column => $suffix) {
                $table->foreign(['organization_id', 'cycle_id', $column], 'academy_objective_'.$suffix.'_fk')->references(['organization_id', 'cycle_id', 'id'])->on('academy_enrollments')->restrictOnDelete();
            }
            $table->text('title');
            $table->text('description')->nullable();
            $table->date('due_on');
            $table->enum('status', ['active', 'completed', 'cancelled']);
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'cycle_id', 'id'], 'academy_objective_cycle_identity');
            $table->unique(['organization_id', 'id'], 'academy_objective_identity');
            $table->index(['organization_id', 'cycle_id', 'due_on', 'id'], 'academy_objective_page');
        });
        Schema::create('academy_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('cycle_id');
            $table->uuid('objective_id');
            $table->foreign(['organization_id', 'cycle_id', 'objective_id'], 'academy_feedback_objective_fk')->references(['organization_id', 'cycle_id', 'id'])->on('academy_objectives')->restrictOnDelete();
            $table->enum('audience', ['shared', 'internal']);
            $table->text('content');
            $table->text('justification')->nullable();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['active', 'withdrawn']);
            $table->uuid('version');
            $table->timestamps();
            $table->index(['organization_id', 'objective_id', 'audience', 'status', 'created_at', 'id'], 'academy_feedback_page');
        });
        Schema::create('academy_coaching_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('objective_id');
            $table->foreign(['organization_id', 'objective_id'], 'academy_coaching_revision_objective_fk')->references(['organization_id', 'id'])->on('academy_objectives')->restrictOnDelete();
            $table->enum('kind', ['objective', 'feedback']);
            $table->uuid('resource_id');
            $table->enum('audience', ['shared', 'internal']);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('snapshot');
            $table->timestampTz('recorded_at');
            $table->index(['organization_id', 'objective_id', 'audience', 'recorded_at', 'id'], 'academy_coaching_revision_page');
        });
        $this->guards();
    }

    private function guards(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        $different = $pg ? 'IS DISTINCT FROM' : 'IS NOT';
        foreach (['academy_objectives', 'academy_feedback', 'academy_coaching_revisions'] as $table) {
            $fields = match ($table) {
                'academy_objectives' => ['id', 'organization_id', 'cohort_id', 'cycle_id', 'target_enrollment_id'],
                'academy_feedback' => ['id', 'organization_id', 'cycle_id', 'objective_id', 'audience', 'author_user_id'],
                default => ['id', 'organization_id', 'objective_id'],
            };
            $identity = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$different} OLD.{$field}", $fields));
            $invalid = $table === 'academy_feedback' ? "(NEW.audience='internal' AND (NEW.justification IS NULL OR LENGTH(NEW.justification)=0)) OR (NEW.audience='shared' AND NEW.justification IS NOT NULL)" : '1=0';
            $update = $table === 'academy_coaching_revisions' ? '1=1' : "({$identity}) OR OLD.status!='active' OR ({$invalid})";
            if ($pg) {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_guard() RETURNS trigger AS $$ BEGIN
                    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Academy coaching history is retained' USING ERRCODE='23514'; END IF;
                    IF TG_OP='UPDATE' THEN IF ({$update}) THEN RAISE EXCEPTION 'Immutable academy coaching identity or history' USING ERRCODE='23514'; END IF; END IF;
                    IF ({$invalid}) THEN RAISE EXCEPTION 'Invalid coaching audience' USING ERRCODE='23514'; END IF;
                    RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_guard()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Invalid coaching audience'); END");
                DB::unprepared("CREATE TRIGGER {$table}_update BEFORE UPDATE ON {$table} WHEN {$update} BEGIN SELECT RAISE(ABORT, 'Immutable academy coaching identity or history'); END");
                DB::unprepared("CREATE TRIGGER {$table}_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Academy coaching history is retained'); END");
            }
        }
    }

    public function down(): void
    {
        $tables = ['academy_coaching_revisions', 'academy_feedback', 'academy_objectives'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('ACA-003 rollback cannot discard coaching history. Use a forward fix.');
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
    }
};
