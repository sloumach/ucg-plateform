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
            throw new RuntimeException('Match lineups require SQLite or PostgreSQL.');
        }
        Schema::create('match_lineup_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('match_id');
            $t->uuid('participant_id');
            $t->uuid('match_version');
            $t->unsignedSmallInteger('number');
            $t->enum('action', ['assign', 'substitute']);
            $t->enum('phase', ['proposed', 'confirmed', 'in_progress']);
            $t->text('snapshot');
            $t->text('assessment');
            $t->text('reason');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->foreign(['organization_id', 'match_id'])->references(['organization_id', 'id'])->on('matches')->restrictOnDelete();
            $t->foreign(['organization_id', 'participant_id'])->references(['organization_id', 'id'])->on('match_participants')->restrictOnDelete();
            $t->unique(['organization_id', 'match_id', 'match_version'], 'match_lineup_version');
            $t->unique(['organization_id', 'match_id', 'participant_id', 'number'], 'match_lineup_history');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER match_lineup_insert BEFORE INSERT ON match_lineup_revisions WHEN
                NOT EXISTS (SELECT 1 FROM matches m JOIN match_participants p ON p.organization_id=m.organization_id AND p.match_id=m.id
                    WHERE m.organization_id=NEW.organization_id AND m.id=NEW.match_id AND p.id=NEW.participant_id AND p.kind='team'
                    AND m.version=NEW.match_version AND m.status=NEW.phase AND m.status IN ('proposed','confirmed','in_progress'))
                OR (NEW.action='assign' AND NEW.phase='in_progress')
                OR (NEW.action='substitute' AND NEW.number=1)
                OR NEW.number<>(SELECT COALESCE(MAX(number),0)+1 FROM match_lineup_revisions WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id AND participant_id=NEW.participant_id)
                OR (SELECT COUNT(*) FROM match_lineup_revisions WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id)>=500
                BEGIN SELECT RAISE(ABORT,'Invalid match lineup revision'); END");
            DB::unprepared("CREATE TRIGGER match_lineup_update BEFORE UPDATE ON match_lineup_revisions BEGIN SELECT RAISE(ABORT,'Immutable match lineup revision'); END");
            DB::unprepared("CREATE TRIGGER match_lineup_delete BEFORE DELETE ON match_lineup_revisions BEGIN SELECT RAISE(ABORT,'Immutable match lineup revision'); END");
        } else {
            DB::unprepared("CREATE FUNCTION match_lineup_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable match lineup revision'; END IF;
                PERFORM 1 FROM matches WHERE organization_id=NEW.organization_id AND id=NEW.match_id FOR UPDATE;
                IF NOT EXISTS (SELECT 1 FROM matches m JOIN match_participants p ON p.organization_id=m.organization_id AND p.match_id=m.id
                    WHERE m.organization_id=NEW.organization_id AND m.id=NEW.match_id AND p.id=NEW.participant_id AND p.kind='team'
                    AND m.version=NEW.match_version AND m.status=NEW.phase AND m.status IN ('proposed','confirmed','in_progress'))
                    OR (NEW.action='assign' AND NEW.phase='in_progress') OR (NEW.action='substitute' AND NEW.number=1)
                    OR NEW.number<>(SELECT COALESCE(MAX(number),0)+1 FROM match_lineup_revisions WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id AND participant_id=NEW.participant_id)
                    OR (SELECT COUNT(*) FROM match_lineup_revisions WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id)>=500
                    THEN RAISE EXCEPTION 'Invalid match lineup revision'; END IF;
                RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER match_lineup_guard BEFORE INSERT OR UPDATE OR DELETE ON match_lineup_revisions FOR EACH ROW EXECUTE FUNCTION match_lineup_guard()');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('match_lineup_revisions') && DB::table('match_lineup_revisions')->exists()) {
            throw new RuntimeException('Match lineup history must be retained.');
        }
        Schema::dropIfExists('match_lineup_revisions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS match_lineup_guard()');
        }
    }
};
