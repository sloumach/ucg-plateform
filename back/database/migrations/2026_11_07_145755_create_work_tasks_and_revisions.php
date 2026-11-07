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
            throw new RuntimeException('Work tasks require SQLite or PostgreSQL.');
        }
        Schema::create('work_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('announcement_id');
            $table->foreign(['organization_id', 'announcement_id'])->references(['organization_id', 'id'])->on('announcements')->restrictOnDelete();
            $table->uuid('assignee_id')->nullable();
            $table->foreign(['organization_id', 'assignee_id'])->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $table->text('title');
            $table->text('description')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('todo');
            $table->uuid('version');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'announcement_id']);
            $table->index(['organization_id', 'status', 'due_on', 'id']);
            $table->index(['organization_id', 'assignee_id', 'id']);
        });
        Schema::create('work_task_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('task_id');
            $table->foreign(['organization_id', 'task_id'])->references(['organization_id', 'id'])->on('work_tasks')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->text('snapshot');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'task_id', 'number']);
        });
        $taskShape = "NEW.status IN ('todo', 'in_progress', 'done')";
        $parent = "EXISTS (SELECT 1 FROM announcements a WHERE a.organization_id = NEW.organization_id AND a.id = NEW.announcement_id AND a.status = 'draft' AND a.target_type = 'organization') AND NOT EXISTS (SELECT 1 FROM communication_contexts c WHERE c.organization_id = NEW.organization_id AND c.announcement_id = NEW.announcement_id)";
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION work_task_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' AND ucg_manual_erasure_allowed(TG_TABLE_NAME, to_jsonb(OLD), NULL, TG_OP) THEN RETURN OLD; END IF;
                    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Task history must be retained'; END IF;
                    IF NOT ($taskShape) THEN RAISE EXCEPTION 'Invalid task status'; END IF;
                    IF TG_OP = 'INSERT' AND NOT ($parent) THEN RAISE EXCEPTION 'Invalid task discussion'; END IF;
                    IF TG_OP = 'UPDATE' AND (NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.announcement_id IS DISTINCT FROM OLD.announcement_id OR NEW.created_by IS DISTINCT FROM OLD.created_by OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.version IS NOT DISTINCT FROM OLD.version) THEN RAISE EXCEPTION 'Immutable task identity or stale version'; END IF;
                    RETURN NEW;
                END; \$\$;
                CREATE FUNCTION work_task_revision_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' AND ucg_manual_erasure_allowed(TG_TABLE_NAME, to_jsonb(OLD), NULL, TG_OP) THEN RETURN OLD; END IF;
                    IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Immutable task history'; END IF;
                    IF NEW.number <> COALESCE((SELECT MAX(number) FROM work_task_revisions WHERE organization_id = NEW.organization_id AND task_id = NEW.task_id), 0) + 1 THEN RAISE EXCEPTION 'Invalid task revision sequence'; END IF;
                    RETURN NEW;
                END; \$\$;
                CREATE TRIGGER work_task_guard BEFORE INSERT OR UPDATE OR DELETE ON work_tasks FOR EACH ROW EXECUTE FUNCTION work_task_guard();
                CREATE TRIGGER work_task_revision_guard BEFORE INSERT OR UPDATE OR DELETE ON work_task_revisions FOR EACH ROW EXECUTE FUNCTION work_task_revision_guard()");
            foreach (['work_task_guard', 'work_task_revision_guard'] as $function) {
                $row = DB::selectOne("SELECT n.nspname || '.' || p.proname || '()' AS identity, pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname = ?", [$function]);
                $this->register($row->identity, 'function', null, $row->definition);
            }
        } else {
            DB::unprepared("CREATE TRIGGER work_task_shape BEFORE INSERT ON work_tasks WHEN NOT ($taskShape) OR NOT ($parent) BEGIN SELECT RAISE(ABORT, 'Invalid task or discussion'); END;
                CREATE TRIGGER work_task_identity BEFORE UPDATE ON work_tasks WHEN NOT ($taskShape) OR NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.announcement_id IS NOT OLD.announcement_id OR NEW.created_by IS NOT OLD.created_by OR NEW.created_at IS NOT OLD.created_at OR NEW.version IS OLD.version BEGIN SELECT RAISE(ABORT, 'Immutable task identity or stale version'); END;
                CREATE TRIGGER work_task_revision_shape BEFORE INSERT ON work_task_revisions WHEN NEW.number <> COALESCE((SELECT MAX(number) FROM work_task_revisions WHERE organization_id = NEW.organization_id AND task_id = NEW.task_id), 0) + 1 BEGIN SELECT RAISE(ABORT, 'Invalid task revision sequence'); END;
                CREATE TRIGGER work_task_revision_update BEFORE UPDATE ON work_task_revisions BEGIN SELECT RAISE(ABORT, 'Immutable task history'); END");
        }
        foreach (['work_tasks', 'work_task_revisions'] as $table) {
            $this->preservation($table);
        }
    }

    public function down(): void
    {
        if (DB::table('work_tasks')->exists() || DB::table('work_task_revisions')->exists()) {
            throw new RuntimeException('Work task rollback cannot discard history.');
        }
        foreach (['work_task_revisions', 'work_tasks'] as $table) {
            Schema::dropIfExists($table);
            DB::table('retention_guard_definitions')->where('table_name', $table)->delete();
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (['work_task_guard', 'work_task_revision_guard'] as $function) {
                DB::table('retention_guard_definitions')->where('id', 'like', '%.'.$function.'()')->delete();
                DB::statement("DROP FUNCTION IF EXISTS $function()");
            }
        }
    }

    private function preservation(string $table): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE TRIGGER ucg_retention_business_guard BEFORE INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION ucg_retention_business_guard()");
            foreach (DB::select('SELECT t.tgname, pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ? AND NOT t.tgisinternal', [$table]) as $trigger) {
                $this->register($table.'.'.$trigger->tgname, 'pg_trigger', $table, $trigger->definition);
            }

            return;
        }
        $old = 'json_object('.implode(', ', array_map(fn (string $column): string => "'".$column."', OLD.\"".$column.'"', Schema::getColumnListing($table))).')';
        $allowed = "ucg_manual_erasure_allowed('$table', $old, NULL, 'DELETE')";
        DB::connection()->getPdo()->exec("CREATE TRIGGER {$table}_delete BEFORE DELETE ON $table WHEN $allowed <> 1 BEGIN SELECT RAISE(ABORT, 'Task history must be retained'); END");
        $blocked = "EXISTS (SELECT 1 FROM organization_retention_events e WHERE e.organization_id = NEW.organization_id AND e.version = (SELECT MAX(version) FROM organization_retention_events WHERE organization_id = NEW.organization_id) AND (json_type(e.state, '$.holds') IS NOT 'array' OR json_array_length(e.state, '$.holds') > 0 OR json_type(e.state, '$.quarantine') IS NOT 'null' OR json_extract(e.state, '$.version') IS NOT e.version)) OR (SELECT retention_version FROM organizations WHERE id = NEW.organization_id) IS NOT COALESCE((SELECT MAX(version) FROM organization_retention_events WHERE organization_id = NEW.organization_id), 0)";
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
            $condition = $operation === 'DELETE' ? str_replace('NEW.', 'OLD.', $blocked) : $blocked;
            if ($operation === 'UPDATE') {
                $condition = '('.$condition.') OR ('.str_replace('NEW.', 'OLD.', $blocked).')';
            }
            $exemption = $operation === 'DELETE' ? " AND $allowed <> 1" : '';
            DB::connection()->getPdo()->exec("CREATE TRIGGER ucg_retention_{$table}_$operation BEFORE $operation ON $table WHEN ($condition)$exemption BEGIN SELECT RAISE(ABORT, 'Retention preservation blocks business writes'); END");
        }
        foreach (DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->get() as $trigger) {
            $this->register($trigger->name, 'trigger', $table, $trigger->sql);
        }
    }

    private function register(string $id, string $kind, ?string $table, string $definition): void
    {
        DB::table('retention_guard_definitions')->insert(['id' => $id, 'kind' => $kind, 'table_name' => $table,
            'original_sql' => $definition, 'wrapped_hash' => hash('sha256', $definition)]);
    }
};
