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
            throw new RuntimeException('Match notebook requires SQLite or PostgreSQL.');
        }
        Schema::create('match_notebook_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('match_id');
            $t->uuid('entry_id');
            $t->unsignedSmallInteger('number');
            $t->enum('kind', ['objective', 'note', 'vod', 'debrief', 'action']);
            $t->enum('audience', ['internal', 'strategy', 'shared']);
            $t->text('title');
            $t->text('body');
            $t->text('vod_url')->nullable();
            $t->unsignedInteger('timestamp_seconds')->nullable();
            $t->enum('state', ['open', 'done'])->nullable();
            $t->timestampTz('due_at')->nullable();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'match_id', 'id'], 'match_notebook_identity');
            $t->unique(['organization_id', 'match_id', 'entry_id', 'number'], 'match_notebook_sequence');
            $t->foreign(['organization_id', 'match_id'])->references(['organization_id', 'id'])->on('matches')->restrictOnDelete();
            $t->foreign(['organization_id', 'match_id', 'entry_id'], 'match_notebook_root')->references(['organization_id', 'match_id', 'id'])->on('match_notebook_revisions')->restrictOnDelete();
        });
        $invalid = "NEW.number<1 OR NEW.number>100 OR NEW.number<>(SELECT COALESCE(MAX(r.number),0)+1 FROM match_notebook_revisions r WHERE r.organization_id=NEW.organization_id AND r.match_id=NEW.match_id AND r.entry_id=NEW.entry_id)
            OR (NEW.number=1 AND (NEW.entry_id<>NEW.id OR (SELECT COUNT(*) FROM match_notebook_revisions r WHERE r.organization_id=NEW.organization_id AND r.match_id=NEW.match_id AND r.number=1)>=500))
            OR (NEW.number>1 AND NOT EXISTS (SELECT 1 FROM match_notebook_revisions r WHERE r.organization_id=NEW.organization_id AND r.match_id=NEW.match_id AND r.id=NEW.entry_id AND r.number=1 AND r.kind=NEW.kind AND r.audience=NEW.audience))
            OR (NEW.kind='vod' AND NEW.vod_url IS NULL) OR (NEW.kind<>'vod' AND (NEW.vod_url IS NOT NULL OR NEW.timestamp_seconds IS NOT NULL))
            OR (NEW.timestamp_seconds IS NOT NULL AND (NEW.timestamp_seconds<0 OR NEW.timestamp_seconds>604800))
            OR (NEW.kind='action' AND NEW.state IS NULL) OR (NEW.kind<>'action' AND (NEW.state IS NOT NULL OR NEW.due_at IS NOT NULL))";
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER match_notebook_insert BEFORE INSERT ON match_notebook_revisions WHEN {$invalid} BEGIN SELECT RAISE(ABORT,'Invalid match notebook revision'); END");
            DB::unprepared("CREATE TRIGGER match_notebook_update BEFORE UPDATE ON match_notebook_revisions BEGIN SELECT RAISE(ABORT,'Immutable match notebook revision'); END");
            DB::unprepared("CREATE TRIGGER match_notebook_delete BEFORE DELETE ON match_notebook_revisions BEGIN SELECT RAISE(ABORT,'Immutable match notebook revision'); END");
        } else {
            DB::statement("SET LOCAL lock_timeout='5s'");
            DB::unprepared("CREATE FUNCTION match_notebook_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable match notebook revision'; END IF;
                PERFORM 1 FROM matches WHERE organization_id=NEW.organization_id AND id=NEW.match_id FOR UPDATE;
                IF {$invalid} THEN RAISE EXCEPTION 'Invalid match notebook revision'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER match_notebook_guard BEFORE INSERT OR UPDATE OR DELETE ON match_notebook_revisions FOR EACH ROW EXECUTE FUNCTION match_notebook_guard()');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('match_notebook_revisions') && DB::table('match_notebook_revisions')->exists()) {
            throw new RuntimeException('Match notebook history must be retained.');
        }
        Schema::dropIfExists('match_notebook_revisions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS match_notebook_guard()');
        }
    }
};
