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
            throw new RuntimeException('Exposure contexts require SQLite or PostgreSQL.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        // An organization exposure is narrowed by this typed child, preserving deployed guards and historical rows.
        Schema::create('sponsorship_exposure_contexts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('exposure_id');
            $table->foreign(['organization_id', 'exposure_id'], 'sponsorship_context_parent')->references(['organization_id', 'id'])->on('sponsorship_exposures')->restrictOnDelete();
            foreach (['activity' => 'calendar_activities', 'media' => 'media_items'] as $kind => $source) {
                $table->uuid($kind.'_id')->nullable();
                $table->foreign(['organization_id', $kind.'_id'], 'sponsorship_context_'.$kind)->references(['organization_id', 'id'])->on($source)->restrictOnDelete();
                $table->index(['organization_id', $kind.'_id'], 'sponsorship_context_'.$kind.'_lookup');
            }
            $table->uuid('source_version');
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'exposure_id'], 'sponsorship_context_once');
        });
        $shape = '(CASE WHEN NEW.activity_id IS NOT NULL THEN 1 ELSE 0 END + CASE WHEN NEW.media_id IS NOT NULL THEN 1 ELSE 0 END) = 1';
        $parent = "EXISTS (SELECT 1 FROM sponsorship_exposures e JOIN sponsorship_agreements a ON a.id=e.agreement_id AND a.organization_id=e.organization_id WHERE e.organization_id=NEW.organization_id AND e.id=NEW.exposure_id AND e.target_type='organization' AND e.status='active' AND a.status='draft')";
        $columns = ['id', 'organization_id', 'exposure_id', 'activity_id', 'media_id', 'created_at'];
        if (DB::getDriverName() === 'pgsql') {
            $changed = implode(' OR ', array_map(fn (string $column): string => "NEW.$column IS DISTINCT FROM OLD.$column", $columns));
            DB::unprepared("CREATE FUNCTION sponsorship_context_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP='DELETE' THEN
                        IF ucg_manual_erasure_allowed(TG_TABLE_NAME, to_jsonb(OLD), NULL, TG_OP) THEN RETURN OLD; END IF;
                        RAISE EXCEPTION 'Exposure context must be retained';
                    END IF;
                    PERFORM 1 FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                    IF NOT ($shape) OR NOT ($parent) THEN RAISE EXCEPTION 'Invalid exposure context'; END IF;
                    IF TG_OP='UPDATE' AND ($changed) THEN RAISE EXCEPTION 'Immutable exposure target'; END IF;
                    RETURN NEW;
                END; \$\$;
                CREATE TRIGGER sponsorship_context_guard BEFORE INSERT OR UPDATE OR DELETE ON sponsorship_exposure_contexts FOR EACH ROW EXECUTE FUNCTION sponsorship_context_guard();
                CREATE TRIGGER ucg_retention_business_guard BEFORE INSERT OR UPDATE OR DELETE ON sponsorship_exposure_contexts FOR EACH ROW EXECUTE FUNCTION ucg_retention_business_guard()");
            $function = DB::selectOne("SELECT n.nspname || '.sponsorship_context_guard()' AS identity, pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=current_schema() AND p.proname='sponsorship_context_guard'");
            $this->register($function->identity, 'function', null, $function->definition);
            foreach (DB::select("SELECT t.tgname, pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND c.relname='sponsorship_exposure_contexts' AND NOT t.tgisinternal") as $trigger) {
                $this->register('sponsorship_exposure_contexts.'.$trigger->tgname, 'pg_trigger', 'sponsorship_exposure_contexts', $trigger->definition);
            }
        } else {
            $changed = implode(' OR ', array_map(fn (string $column): string => "NEW.$column IS NOT OLD.$column", $columns));
            DB::unprepared("CREATE TRIGGER sponsorship_context_insert BEFORE INSERT ON sponsorship_exposure_contexts WHEN NOT ($shape) OR NOT ($parent) BEGIN SELECT RAISE(ABORT, 'Invalid exposure context'); END");
            DB::unprepared("CREATE TRIGGER sponsorship_context_update BEFORE UPDATE ON sponsorship_exposure_contexts WHEN NOT ($shape) OR NOT ($parent) OR ($changed) BEGIN SELECT RAISE(ABORT, 'Immutable exposure target'); END");
            $old = 'json_object('.implode(', ', array_map(fn (string $column): string => "'".$column."', OLD.\"".$column.'"', Schema::getColumnListing('sponsorship_exposure_contexts'))).')';
            DB::connection()->getPdo()->exec("CREATE TRIGGER sponsorship_context_delete BEFORE DELETE ON sponsorship_exposure_contexts WHEN ucg_manual_erasure_allowed('sponsorship_exposure_contexts', $old, NULL, 'DELETE') <> 1 BEGIN SELECT RAISE(ABORT, 'Exposure context must be retained'); END");
            $blocked = "EXISTS (SELECT 1 FROM organization_retention_events e WHERE e.organization_id=NEW.organization_id AND e.version=(SELECT MAX(version) FROM organization_retention_events WHERE organization_id=NEW.organization_id) AND (json_type(e.state, '$.holds') IS NOT 'array' OR json_array_length(e.state, '$.holds') > 0 OR json_type(e.state, '$.quarantine') IS NOT 'null' OR json_extract(e.state, '$.version') IS NOT e.version)) OR (SELECT retention_version FROM organizations WHERE id=NEW.organization_id) IS NOT COALESCE((SELECT MAX(version) FROM organization_retention_events WHERE organization_id=NEW.organization_id), 0)";
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                $condition = $operation === 'DELETE' ? str_replace('NEW.', 'OLD.', $blocked) : $blocked;
                if ($operation === 'UPDATE') {
                    $condition = '('.$condition.') OR ('.str_replace('NEW.', 'OLD.', $blocked).')';
                }
                $allowed = $operation === 'DELETE' ? " AND ucg_manual_erasure_allowed('sponsorship_exposure_contexts', $old, NULL, 'DELETE') <> 1" : '';
                DB::connection()->getPdo()->exec("CREATE TRIGGER ucg_retention_sponsorship_exposure_contexts_$operation BEFORE $operation ON sponsorship_exposure_contexts WHEN ($condition)$allowed BEGIN SELECT RAISE(ABORT, 'Retention preservation blocks business writes'); END");
            }
            foreach (DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'sponsorship_exposure_contexts')->get() as $trigger) {
                $this->register($trigger->name, 'trigger', 'sponsorship_exposure_contexts', $trigger->sql);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('sponsorship_exposure_contexts')->exists()) {
            throw new RuntimeException('Exposure contexts exist. Use a forward migration instead.');
        }
        Schema::dropIfExists('sponsorship_exposure_contexts');
        DB::table('retention_guard_definitions')->where('table_name', 'sponsorship_exposure_contexts')->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::table('retention_guard_definitions')->where('id', 'like', '%.sponsorship_context_guard()')->delete();
            DB::statement('DROP FUNCTION IF EXISTS sponsorship_context_guard()');
        }
    }

    private function register(string $id, string $kind, ?string $table, string $definition): void
    {
        DB::table('retention_guard_definitions')->insert(['id' => $id, 'kind' => $kind, 'table_name' => $table,
            'original_sql' => $definition, 'wrapped_hash' => hash('sha256', $definition)]);
    }
};
