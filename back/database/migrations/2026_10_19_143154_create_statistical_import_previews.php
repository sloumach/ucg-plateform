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
            throw new RuntimeException('STAT-003A requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('statistical_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('artifact_id');
            $table->foreign(['organization_id', 'artifact_id'])->references(['organization_id', 'id'])->on('tenant_artifacts')->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->string('delimiter', 1);
            $table->text('headers');
            $table->text('preview');
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('valid_count');
            $table->unsignedInteger('invalid_count');
            $table->uuid('request_id');
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'actor_user_id', 'fingerprint'], 'stat_import_fingerprint');
            $table->index(['organization_id', 'actor_user_id', 'id'], 'stat_import_actor_page');
        });
        $invalid = "NEW.row_count < 1 OR NEW.row_count > 100 OR NEW.valid_count < 0 OR NEW.invalid_count < 0 OR NEW.valid_count + NEW.invalid_count <> NEW.row_count OR NEW.delimiter NOT IN (',',';')";
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE statistical_imports ADD CONSTRAINT stat_import_shape CHECK (NOT ('.str_replace('NEW.', '', $invalid).'))');
        }
        // Preview snapshots are immutable and cannot become executable in this delivery.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION ucg_stat_import_immutable() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Statistical previews are immutable' USING ERRCODE='23514'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER stat_import_immutable BEFORE UPDATE OR DELETE ON statistical_imports FOR EACH ROW EXECUTE FUNCTION ucg_stat_import_immutable()");
        } else {
            DB::unprepared("CREATE TRIGGER stat_import_shape BEFORE INSERT ON statistical_imports WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Invalid statistical preview'); END");
            foreach (['UPDATE', 'DELETE'] as $action) {
                $name = strtolower($action);
                DB::unprepared("CREATE TRIGGER stat_import_{$name} BEFORE {$action} ON statistical_imports BEGIN SELECT RAISE(ABORT, 'Statistical previews are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('statistical_imports')->exists()) {
            throw new RuntimeException('STAT-003A rollback refuses to discard private previews.');
        }
        Schema::drop('statistical_imports');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION ucg_stat_import_immutable()');
        }
    }
};
