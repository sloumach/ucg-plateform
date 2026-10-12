<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('HUM-005 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        // Existing artifact UUID primary keys already guarantee uniqueness.
        Schema::table('tenant_artifacts', fn (Blueprint $table) => $table->unique(['organization_id', 'id'], 'artifacts_tenant_id_unique'));
        Schema::create('person_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('execution_actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('artifact_id');
            $table->foreign(['organization_id', 'artifact_id'])->references(['organization_id', 'id'])->on('tenant_artifacts')->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->string('delimiter', 1);
            $table->text('headers');
            $table->json('mapping');
            $table->json('defaults');
            $table->unsignedInteger('row_count');
            $table->enum('status', ['uploaded', 'previewed', 'queued', 'processing', 'completed', 'failed']);
            $table->uuid('version');
            $table->uuid('confirmed_version')->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'fingerprint']);
            $table->index(['organization_id', 'status', 'id']);
        });
        Schema::create('person_import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('import_id');
            $table->foreign(['organization_id', 'import_id'])->references(['organization_id', 'id'])->on('person_imports')->restrictOnDelete();
            $table->unsignedInteger('line');
            $table->enum('status', ['ready', 'invalid', 'duplicate', 'created', 'skipped']);
            $table->text('values');
            $table->text('errors');
            $table->uuid('person_id')->nullable();
            $table->foreign(['organization_id', 'person_id'])->references(['organization_id', 'id'])->on('people')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'import_id', 'line']);
            $table->index(['organization_id', 'import_id', 'status', 'line']);
        });
        // This expression matches the conservative existing-person lookup exactly.
        DB::statement('CREATE INDEX people_import_pseudonym ON people (organization_id, LOWER(TRIM(pseudonym)))');
        foreach (['person_imports', 'person_import_rows'] as $table) {
            $identity = $table === 'person_imports' ? 'artifact_id' : 'import_id';
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_identity() RETURNS trigger AS $$ BEGIN
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.{$identity} IS DISTINCT FROM OLD.{$identity} THEN
                    RAISE EXCEPTION 'Import identity is immutable' USING ERRCODE='23514'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_identity BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_identity()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_identity BEFORE UPDATE ON {$table}
                    WHEN NEW.id IS NOT OLD.id OR NEW.organization_id IS NOT OLD.organization_id OR NEW.{$identity} IS NOT OLD.{$identity}
                    BEGIN SELECT RAISE(ABORT, 'Import identity is immutable'); END");
            }
        }
        $this->historyGuards();
    }

    private function historyGuards(): void
    {
        $postgres = DB::getDriverName() === 'pgsql';
        $different = $postgres ? 'IS DISTINCT FROM' : 'IS NOT';
        $runFrozen = [];
        foreach (['fingerprint', 'delimiter', 'headers', 'row_count', 'actor_user_id'] as $field) {
            $runFrozen[] = "NEW.{$field} {$different} OLD.{$field}";
        }
        $jsonCast = $postgres ? '::text' : '';
        $runFrozen[] = "(OLD.confirmed_version IS NOT NULL AND (NEW.confirmed_version {$different} OLD.confirmed_version
            OR NEW.status IN ('uploaded','previewed') OR NEW.mapping{$jsonCast} {$different} OLD.mapping{$jsonCast}
            OR NEW.defaults{$jsonCast} {$different} OLD.defaults{$jsonCast}))";
        $runFrozen[] = "(OLD.status = 'completed' AND NEW.status != 'completed')";
        $rowFrozen = "NEW.line {$different} OLD.line OR (OLD.status = 'created' AND
            (NEW.status != 'created' OR NEW.person_id {$different} OLD.person_id OR NEW.\"values\" {$different} OLD.\"values\" OR NEW.errors {$different} OLD.errors))";
        foreach (['person_imports' => implode(' OR ', $runFrozen), 'person_import_rows' => $rowFrozen] as $table => $invalid) {
            $deleteInvalid = $table === 'person_imports' ? ($postgres ? 'TRUE' : '1')
                : "EXISTS (SELECT 1 FROM person_imports WHERE id=OLD.import_id AND organization_id=OLD.organization_id AND status NOT IN ('uploaded','previewed'))";
            if ($postgres) {
                DB::unprepared("CREATE FUNCTION ucg_{$table}_history() RETURNS trigger AS $$ BEGIN
                    IF TG_OP='DELETE' THEN IF {$deleteInvalid} THEN RAISE EXCEPTION 'Import history must be retained' USING ERRCODE='23514'; END IF; RETURN OLD;
                    ELSE IF {$invalid} THEN RAISE EXCEPTION 'Confirmed import is immutable' USING ERRCODE='23514'; END IF; RETURN NEW; END IF; END; $$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ucg_{$table}_history()");
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_history_update BEFORE UPDATE ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Confirmed import is immutable'); END");
                DB::unprepared("CREATE TRIGGER {$table}_history_delete BEFORE DELETE ON {$table} WHEN {$deleteInvalid} BEGIN SELECT RAISE(ABORT, 'Import history must be retained'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('person_imports')->exists() || DB::table('person_import_rows')->exists()) {
            throw new RuntimeException('HUM-005 rollback cannot discard import history. Use a forward fix.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::drop('person_import_rows');
        Schema::drop('person_imports');
        DB::statement('DROP INDEX people_import_pseudonym');
        Schema::table('tenant_artifacts', fn (Blueprint $table) => $table->dropUnique('artifacts_tenant_id_unique'));
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION ucg_person_import_rows_identity(); DROP FUNCTION ucg_person_imports_identity()');
            DB::unprepared('DROP FUNCTION ucg_person_import_rows_history(); DROP FUNCTION ucg_person_imports_history()');
        }
    }
};
