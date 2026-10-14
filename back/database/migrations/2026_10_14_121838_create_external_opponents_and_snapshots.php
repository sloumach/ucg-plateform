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
            throw new RuntimeException('TEAM-006 requires SQLite or PostgreSQL guards.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::create('external_opponents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('key', 80);
            $table->enum('kind', ['organization', 'team', 'player']);
            $table->string('name', 120);
            $table->string('short_name', 24)->nullable();
            $table->string('organization_name', 120)->nullable();
            $table->foreignUuid('game_id')->nullable()->constrained('game_catalogue_entries')->restrictOnDelete();
            $table->string('source_label', 160);
            $table->string('source_url', 1000)->nullable();
            $table->timestampTz('observed_at');
            $table->enum('status', ['active', 'archived']);
            $table->uuid('version');
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'name', 'id'], 'opponents_name_page');
            $table->index(['organization_id', 'kind', 'status', 'id'], 'opponents_kind_page');
            $table->index(['organization_id', 'game_id', 'status', 'id'], 'opponents_game_page');
        });
        Schema::create('external_opponent_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('opponent_id');
            $table->foreign(['organization_id', 'opponent_id'], 'opponent_snapshot_source')
                ->references(['organization_id', 'id'])->on('external_opponents')->restrictOnDelete();
            $table->uuid('opponent_version');
            $table->string('context_label', 120);
            $table->enum('kind', ['organization', 'team', 'player']);
            $table->string('name', 120);
            $table->string('short_name', 24)->nullable();
            $table->string('organization_name', 120)->nullable();
            $table->string('game_key', 64)->nullable();
            $table->string('game_name', 120)->nullable();
            $table->string('source_label', 160);
            $table->string('source_url', 1000)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('captured_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'opponent_id', 'captured_at', 'id'], 'opponent_snapshots_page');
        });
        $this->guards();
    }

    private function guards(): void
    {
        $postgres = DB::getDriverName() === 'pgsql';
        $diff = $postgres ? 'IS DISTINCT FROM' : 'IS NOT';
        $same = $postgres ? 'IS NOT DISTINCT FROM' : 'IS';
        $identity = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$diff} OLD.{$field}", ['id', 'organization_id', 'key', 'kind', 'game_id']));
        $invalid = "LENGTH(TRIM(NEW.name))=0 OR LENGTH(TRIM(NEW.source_label))=0
            OR (NEW.game_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM game_catalogue_entries WHERE id=NEW.game_id AND kind='game'))";
        $copy = implode(' AND ', array_map(fn (string $field): string => "NEW.{$field} {$same} o.{$field}", ['kind', 'name', 'short_name', 'organization_name', 'source_label', 'source_url', 'observed_at']));
        $source = "LENGTH(TRIM(NEW.context_label))=0 OR NOT EXISTS (
            SELECT 1 FROM external_opponents o LEFT JOIN game_catalogue_entries g ON g.id=o.game_id
            WHERE o.organization_id=NEW.organization_id AND o.id=NEW.opponent_id AND o.version=NEW.opponent_version
            AND o.status='active' AND {$copy} AND NEW.game_key {$same} g.key AND NEW.game_name {$same} g.name)";
        if ($postgres) {
            DB::unprepared("CREATE FUNCTION ucg_external_opponents_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'External history must be retained' USING ERRCODE='23514'; END IF;
                IF ({$invalid}) THEN RAISE EXCEPTION 'Invalid external reference' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (({$identity}) OR OLD.status='archived') THEN
                    RAISE EXCEPTION 'External identity or archive is immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER external_opponents_guard BEFORE INSERT OR UPDATE OR DELETE ON external_opponents
                    FOR EACH ROW EXECUTE FUNCTION ucg_external_opponents_guard();
                CREATE FUNCTION ucg_opponent_snapshots_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP!='INSERT' THEN RAISE EXCEPTION 'External snapshot is immutable' USING ERRCODE='23514'; END IF;
                PERFORM 1 FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                IF ({$source}) THEN RAISE EXCEPTION 'External snapshot must match its tenant source' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER opponent_snapshots_guard BEFORE INSERT OR UPDATE OR DELETE ON external_opponent_snapshots
                    FOR EACH ROW EXECUTE FUNCTION ucg_opponent_snapshots_guard()");
        } else {
            DB::unprepared("CREATE TRIGGER external_opponents_insert BEFORE INSERT ON external_opponents WHEN {$invalid}
                BEGIN SELECT RAISE(ABORT, 'Invalid external reference'); END");
            DB::unprepared("CREATE TRIGGER external_opponents_update BEFORE UPDATE ON external_opponents
                WHEN ({$invalid}) OR ({$identity}) OR OLD.status='archived'
                BEGIN SELECT RAISE(ABORT, 'External identity or archive is immutable'); END");
            DB::unprepared("CREATE TRIGGER external_opponents_delete BEFORE DELETE ON external_opponents
                BEGIN SELECT RAISE(ABORT, 'External history must be retained'); END");
            DB::unprepared("CREATE TRIGGER opponent_snapshots_insert BEFORE INSERT ON external_opponent_snapshots WHEN {$source}
                BEGIN SELECT RAISE(ABORT, 'External snapshot must match its tenant source'); END");
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = strtolower($operation);
                DB::unprepared("CREATE TRIGGER opponent_snapshots_{$name} BEFORE {$operation} ON external_opponent_snapshots
                    BEGIN SELECT RAISE(ABORT, 'External snapshot is immutable'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('external_opponents')->exists() || DB::table('external_opponent_snapshots')->exists()) {
            throw new RuntimeException('TEAM-006 rollback cannot discard external history. Use a forward fix.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::drop('external_opponent_snapshots');
        Schema::drop('external_opponents');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION ucg_opponent_snapshots_guard(); DROP FUNCTION ucg_external_opponents_guard()');
        }
    }
};
