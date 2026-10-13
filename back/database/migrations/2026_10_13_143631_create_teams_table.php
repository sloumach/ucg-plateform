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
            throw new RuntimeException('TEAM-002 requires PostgreSQL or SQLite.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('game_id')->constrained('game_catalogue_entries')->restrictOnDelete();
            $table->string('slug', 80);
            $table->string('season', 64);
            $table->string('name', 120);
            $table->enum('kind', ['first', 'academy', 'reserve', 'event', 'custom']);
            $table->string('custom_category', 80)->nullable();
            $table->string('short_name', 16)->nullable();
            $table->text('public_description')->nullable();
            $table->string('color', 7)->nullable();
            $table->enum('status', ['draft', 'active', 'archived']);
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'name', 'id']);
            $table->index(['organization_id', 'game_id', 'status', 'id']);
        });
        $postgres = DB::getDriverName() === 'pgsql';
        $diff = $postgres ? 'IS DISTINCT FROM' : 'IS NOT';
        $frozen = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$diff} OLD.{$field}", ['id', 'organization_id', 'game_id', 'slug', 'season']));
        $archive = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$diff} OLD.{$field}",
            ['name', 'kind', 'custom_category', 'short_name', 'public_description', 'color', 'status', 'version']));
        $invalid = "NOT EXISTS (SELECT 1 FROM game_catalogue_entries WHERE id=NEW.game_id AND kind='game')
            OR (NEW.kind='custom' AND (NEW.custom_category IS NULL OR LENGTH(TRIM(NEW.custom_category))=0))
            OR (NEW.kind!='custom' AND NEW.custom_category IS NOT NULL)";
        if ($postgres) {
            DB::unprepared("CREATE FUNCTION ucg_teams_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Team history must be retained' USING ERRCODE='23514'; END IF;
                IF ({$invalid}) THEN RAISE EXCEPTION 'Invalid team context' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (({$frozen}) OR (OLD.status='archived' AND ({$archive}))) THEN
                    RAISE EXCEPTION 'Team identity or archive is immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER teams_guard BEFORE INSERT OR UPDATE OR DELETE ON teams FOR EACH ROW EXECUTE FUNCTION ucg_teams_guard()");
        } else {
            DB::unprepared("CREATE TRIGGER teams_insert BEFORE INSERT ON teams WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Invalid team context'); END");
            DB::unprepared("CREATE TRIGGER teams_update BEFORE UPDATE ON teams WHEN ({$invalid}) OR ({$frozen}) OR (OLD.status='archived' AND ({$archive}))
                BEGIN SELECT RAISE(ABORT, 'Team identity or archive is immutable'); END");
            DB::unprepared("CREATE TRIGGER teams_delete BEFORE DELETE ON teams BEGIN SELECT RAISE(ABORT, 'Team history must be retained'); END");
        }
    }

    public function down(): void
    {
        if (DB::table('teams')->exists()) {
            throw new RuntimeException('TEAM-002 rollback cannot discard team history. Use a forward fix.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::drop('teams');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION ucg_teams_guard()');
        }
    }
};
