<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCES = [
        'people' => ['active_people', 'unlinked_people'], 'person_imports' => ['people_imports'],
        'contracts' => ['expiring_contracts'], 'sponsorship_campaigns' => ['overdue_deliverables'],
        'sponsorship_deliverables' => ['overdue_deliverables'], 'external_competitions' => ['registration_due'],
        'competition_registration_revisions' => ['registration_due'],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('REP-002 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::create('dashboard_source_versions', function (Blueprint $t): void {
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->string('card', 32);
            $t->unsignedBigInteger('generation')->default(0);
            $t->primary(['organization_id', 'card']);
        });
        Schema::create('dashboard_indicators', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('fingerprint', 64);
            $t->string('cache_key', 160);
            $t->string('card', 32);
            $t->string('view', 24);
            $t->string('timezone', 64);
            $t->date('from_date');
            $t->date('to_date');
            $t->string('status', 12);
            $t->uuid('version');
            $t->string('failure_code', 40)->nullable();
            $t->timestampsTz();
            $t->unique(['organization_id', 'id'], 'di_tenant');
            $t->unique(['organization_id', 'actor_user_id', 'fingerprint'], 'di_fingerprint');
            $t->index(['organization_id', 'actor_user_id', 'id'], 'di_actor');
        });
        Schema::create('dashboard_indicator_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('indicator_id');
            $t->unsignedSmallInteger('number');
            $t->uuid('generation');
            $t->unsignedBigInteger('source_version');
            $t->string('engine_version', 24);
            $t->text('result');
            $t->timestampTz('evaluated_at');
            $t->timestampTz('expires_at');
            $t->uuid('request_id');
            $t->timestampTz('created_at');
            $t->foreign(['organization_id', 'indicator_id'], 'dir_parent')->references(['organization_id', 'id'])->on('dashboard_indicators')->restrictOnDelete();
            $t->unique(['organization_id', 'indicator_id', 'number'], 'dir_number');
            $t->unique(['organization_id', 'indicator_id', 'generation'], 'dir_generation');
        });
        // Invalidation shares the source transaction, including bulk SQL and rollback.
        foreach (self::SOURCES as $table => $cards) {
            $new = $this->invalidation($cards, 'NEW');
            $old = $this->invalidation($cards, 'OLD');
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION di_source_{$table}() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' THEN {$old} RETURN OLD; END IF; {$new} IF TG_OP='UPDATE' AND OLD.organization_id<>NEW.organization_id THEN {$old} END IF; RETURN NEW; END; $$; CREATE TRIGGER di_source_{$table} AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION di_source_{$table}()");
            } else {
                foreach (['INSERT' => $new, 'UPDATE' => $new, 'DELETE' => $old] as $action => $sql) {
                    if ($action === 'UPDATE') {
                        foreach ($cards as $card) {
                            $sql .= "INSERT INTO dashboard_source_versions (organization_id,card,generation) SELECT OLD.organization_id,'{$card}',1 WHERE OLD.organization_id<>NEW.organization_id ON CONFLICT (organization_id,card) DO UPDATE SET generation=dashboard_source_versions.generation+1; ";
                        }
                    }
                    DB::unprepared('CREATE TRIGGER di_'.$table.'_'.strtolower($action)." AFTER {$action} ON {$table} BEGIN {$sql} END");
                }
            }
        }
        $identity = 'NEW.id<>OLD.id OR NEW.organization_id<>OLD.organization_id OR NEW.actor_user_id<>OLD.actor_user_id OR NEW.card<>OLD.card OR NEW.view<>OLD.view OR NEW.timezone<>OLD.timezone OR NEW.from_date<>OLD.from_date OR NEW.to_date<>OLD.to_date OR NEW.fingerprint<>OLD.fingerprint OR NEW.cache_key<>OLD.cache_key';
        $shape = "NEW.status NOT IN ('queued','completed','failed') OR NEW.from_date>NEW.to_date";
        $revision = 'NEW.number<1 OR NEW.number>1000 OR NEW.number<>(SELECT COALESCE(MAX(number),0)+1 FROM dashboard_indicator_revisions WHERE organization_id=NEW.organization_id AND indicator_id=NEW.indicator_id) OR NEW.evaluated_at>=NEW.expires_at';
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION di_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retain indicator'; END IF; IF TG_OP='UPDATE' AND ({$identity}) THEN RAISE EXCEPTION 'Retain indicator identity'; END IF; IF {$shape} THEN RAISE EXCEPTION 'Invalid indicator'; END IF; RETURN NEW; END; $$; CREATE TRIGGER di_guard BEFORE INSERT OR UPDATE OR DELETE ON dashboard_indicators FOR EACH ROW EXECUTE FUNCTION di_guard(); CREATE FUNCTION dir_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Retain indicator history'; END IF; PERFORM 1 FROM dashboard_indicators WHERE id=NEW.indicator_id AND organization_id=NEW.organization_id FOR UPDATE; IF {$revision} THEN RAISE EXCEPTION 'Invalid indicator revision'; END IF; RETURN NEW; END; $$; CREATE TRIGGER dir_guard BEFORE INSERT OR UPDATE OR DELETE ON dashboard_indicator_revisions FOR EACH ROW EXECUTE FUNCTION dir_guard()");
        } else {
            foreach (['INSERT', 'UPDATE'] as $action) {
                DB::unprepared('CREATE TRIGGER di_'.strtolower($action)." BEFORE {$action} ON dashboard_indicators WHEN {$shape} BEGIN SELECT RAISE(ABORT,'Invalid indicator'); END");
            }
            DB::unprepared("CREATE TRIGGER di_identity BEFORE UPDATE ON dashboard_indicators WHEN {$identity} BEGIN SELECT RAISE(ABORT,'Retain indicator identity'); END");
            DB::unprepared("CREATE TRIGGER di_delete BEFORE DELETE ON dashboard_indicators BEGIN SELECT RAISE(ABORT,'Retain indicator'); END");
            DB::unprepared("CREATE TRIGGER dir_insert BEFORE INSERT ON dashboard_indicator_revisions WHEN {$revision} BEGIN SELECT RAISE(ABORT,'Invalid indicator revision'); END");
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared('CREATE TRIGGER dir_'.strtolower($action)." BEFORE {$action} ON dashboard_indicator_revisions BEGIN SELECT RAISE(ABORT,'Retain indicator history'); END");
            }
        }
    }

    /** @param list<literal-string> $cards
     * @param literal-string $row
     * @return literal-string */
    private function invalidation(array $cards, string $row): string
    {
        $sql = '';
        foreach ($cards as $card) {
            $sql .= "INSERT INTO dashboard_source_versions (organization_id,card,generation) VALUES ({$row}.organization_id,'{$card}',1) ON CONFLICT (organization_id,card) DO UPDATE SET generation=dashboard_source_versions.generation+1; ";
        }

        return $sql;
    }

    public function down(): void
    {
        if (Schema::hasTable('dashboard_indicators') && DB::table('dashboard_indicators')->exists()) {
            throw new RuntimeException('REP-002 refuses to discard indicator history.');
        }
        foreach (self::SOURCES as $table => $cards) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('DROP TRIGGER IF EXISTS di_source_'.$table.' ON '.$table);
                DB::statement('DROP FUNCTION IF EXISTS di_source_'.$table.'()');
            } else {
                foreach (['insert', 'update', 'delete'] as $action) {
                    DB::statement('DROP TRIGGER IF EXISTS di_'.$table.'_'.$action);
                }
            }
        }
        Schema::dropIfExists('dashboard_indicator_revisions');
        Schema::dropIfExists('dashboard_indicators');
        Schema::dropIfExists('dashboard_source_versions');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS dir_guard()');
            DB::statement('DROP FUNCTION IF EXISTS di_guard()');
        }
    }
};
