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
            throw new RuntimeException('Media contexts require SQLite or PostgreSQL.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::table('media_items', fn (Blueprint $table) => $table->unique(['organization_id', 'id'], 'media_context_parent_key'));
        Schema::create('media_contexts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('media_id');
            $table->foreign(['organization_id', 'media_id'], 'media_context_parent')->references(['organization_id', 'id'])->on('media_items')->restrictOnDelete();
            foreach (['activity' => 'calendar_activities', 'match' => 'matches'] as $kind => $source) {
                $table->uuid($kind.'_id')->nullable();
                $table->foreign(['organization_id', $kind.'_id'], 'media_context_'.$kind)->references(['organization_id', 'id'])->on($source)->restrictOnDelete();
                $table->index(['organization_id', $kind.'_id'], 'media_context_'.$kind.'_lookup');
            }
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'media_id'], 'media_context_once');
        });

        $shape = '(CASE WHEN NEW.activity_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN NEW.match_id IS NOT NULL THEN 1 ELSE 0 END) = 1';
        $parent = 'EXISTS (SELECT 1 FROM media_items m WHERE m.organization_id = NEW.organization_id AND m.id = NEW.media_id AND m.archived_at IS NULL)';
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION media_context_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' AND ucg_manual_erasure_allowed(TG_TABLE_NAME, to_jsonb(OLD), NULL, TG_OP) THEN RETURN OLD; END IF;
                    IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Immutable media context'; END IF;
                    IF NOT ($shape) OR NOT ($parent) THEN RAISE EXCEPTION 'Invalid media context'; END IF;
                    RETURN NEW;
                END; \$\$;
                CREATE TRIGGER media_context_guard BEFORE INSERT OR UPDATE OR DELETE ON media_contexts FOR EACH ROW EXECUTE FUNCTION media_context_guard();
                CREATE TRIGGER ucg_retention_business_guard BEFORE INSERT OR UPDATE OR DELETE ON media_contexts FOR EACH ROW EXECUTE FUNCTION ucg_retention_business_guard()");
            $function = DB::selectOne("SELECT n.nspname || '.media_context_guard()' AS identity, pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname = 'media_context_guard'");
            $this->register($function->identity, 'function', null, $function->definition);
            foreach (DB::select("SELECT t.tgname, pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = 'media_contexts' AND NOT t.tgisinternal") as $trigger) {
                $this->register('media_contexts.'.$trigger->tgname, 'pg_trigger', 'media_contexts', $trigger->definition);
            }
        } else {
            DB::unprepared("CREATE TRIGGER media_context_shape BEFORE INSERT ON media_contexts WHEN NOT ($shape) OR NOT ($parent) BEGIN SELECT RAISE(ABORT, 'Invalid media context'); END");
            $columns = Schema::getColumnListing('media_contexts');
            $old = 'json_object('.implode(', ', array_map(fn (string $column): string => "'".$column."', OLD.\"".$column.'"', $columns)).')';
            DB::unprepared("CREATE TRIGGER media_context_update BEFORE UPDATE ON media_contexts BEGIN SELECT RAISE(ABORT, 'Immutable media context'); END");
            DB::connection()->getPdo()->exec("CREATE TRIGGER media_context_delete BEFORE DELETE ON media_contexts WHEN ucg_manual_erasure_allowed('media_contexts', $old, NULL, 'DELETE') <> 1 BEGIN SELECT RAISE(ABORT, 'Immutable media context'); END");
            $blocked = "EXISTS (SELECT 1 FROM organization_retention_events e WHERE e.organization_id = NEW.organization_id AND e.version = (SELECT MAX(version) FROM organization_retention_events WHERE organization_id = NEW.organization_id) AND (json_type(e.state, '$.holds') IS NOT 'array' OR json_array_length(e.state, '$.holds') > 0 OR json_type(e.state, '$.quarantine') IS NOT 'null' OR json_extract(e.state, '$.version') IS NOT e.version)) OR (SELECT retention_version FROM organizations WHERE id = NEW.organization_id) IS NOT COALESCE((SELECT MAX(version) FROM organization_retention_events WHERE organization_id = NEW.organization_id), 0)";
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                $condition = $operation === 'DELETE' ? str_replace('NEW.', 'OLD.', $blocked) : $blocked;
                if ($operation === 'UPDATE') {
                    $condition = '('.$condition.') OR ('.str_replace('NEW.', 'OLD.', $blocked).')';
                }
                $allowed = $operation === 'DELETE' ? " AND ucg_manual_erasure_allowed('media_contexts', $old, NULL, 'DELETE') <> 1" : '';
                DB::connection()->getPdo()->exec("CREATE TRIGGER ucg_retention_media_contexts_$operation BEFORE $operation ON media_contexts WHEN ($condition)$allowed BEGIN SELECT RAISE(ABORT, 'Retention preservation blocks business writes'); END");
            }
            foreach (DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'media_contexts')->get() as $trigger) {
                $this->register($trigger->name, 'trigger', 'media_contexts', $trigger->sql);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('media_contexts')->exists()) {
            throw new RuntimeException('Media contexts exist. Use a forward migration instead.');
        }
        Schema::dropIfExists('media_contexts');
        Schema::table('media_items', fn (Blueprint $table) => $table->dropUnique('media_context_parent_key'));
        DB::table('retention_guard_definitions')->where('table_name', 'media_contexts')->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::table('retention_guard_definitions')->where('id', 'like', '%.media_context_guard()')->delete();
            DB::statement('DROP FUNCTION IF EXISTS media_context_guard()');
        }
    }

    private function register(string $id, string $kind, ?string $table, string $definition): void
    {
        DB::table('retention_guard_definitions')->insert(['id' => $id, 'kind' => $kind, 'table_name' => $table,
            'original_sql' => $definition, 'wrapped_hash' => hash('sha256', $definition)]);
    }
};
