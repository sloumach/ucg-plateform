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
            throw new RuntimeException('Match corrections require SQLite or PostgreSQL.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout='5s'");
        }
        Schema::create('match_score_corrections', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('match_id');
            $t->uuid('base_match_version');
            $t->uuid('match_version');
            $t->uuid('base_correction_id')->nullable();
            $t->uuid('idempotency_key');
            $t->string('fingerprint', 64);
            $t->unsignedSmallInteger('number');
            foreach (['before', 'after', 'changed_ids', 'reason'] as $column) {
                $t->text($column);
            }
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'match_id', 'id'], 'match_correction_identity');
            $t->unique(['organization_id', 'match_id', 'number'], 'match_correction_number');
            $t->unique(['organization_id', 'match_id', 'idempotency_key'], 'match_correction_key');
            $t->foreign(['organization_id', 'match_id'])->references(['organization_id', 'id'])->on('matches')->restrictOnDelete();
            $t->foreign(['organization_id', 'match_id', 'base_correction_id'], 'match_correction_base')->references(['organization_id', 'match_id', 'id'])->on('match_score_corrections')->restrictOnDelete();
        });
        Schema::create('match_score_recalculations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('match_id');
            $t->uuid('correction_id');
            $t->text('series');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'match_id', 'correction_id'], 'match_recalculation_once');
            $t->foreign(['organization_id', 'match_id', 'correction_id'], 'match_recalculation_parent')->references(['organization_id', 'match_id', 'id'])->on('match_score_corrections')->restrictOnDelete();
        });
        $pending = 'EXISTS (SELECT 1 FROM match_score_corrections c WHERE c.organization_id=NEW.organization_id AND c.match_id=NEW.match_id AND NOT EXISTS (SELECT 1 FROM match_score_recalculations r WHERE r.organization_id=c.organization_id AND r.match_id=c.match_id AND r.correction_id=c.id))';
        $invalid = "NEW.number<1 OR NEW.number>100 OR NEW.number<>(SELECT COALESCE(MAX(number),0)+1 FROM match_score_corrections WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id)
            OR NOT EXISTS (SELECT 1 FROM matches WHERE organization_id=NEW.organization_id AND id=NEW.match_id AND version=NEW.match_version AND status IN ('in_progress','awaiting_validation','disputed'))
            OR (NEW.number=1 AND NEW.base_correction_id IS NOT NULL) OR (NEW.number>1 AND NOT EXISTS (SELECT 1 FROM match_score_corrections c JOIN match_score_recalculations r ON r.organization_id=c.organization_id AND r.match_id=c.match_id AND r.correction_id=c.id WHERE c.organization_id=NEW.organization_id AND c.match_id=NEW.match_id AND c.number=NEW.number-1 AND c.id=NEW.base_correction_id)) OR $pending";
        $completion = 'NOT EXISTS (SELECT 1 FROM match_score_corrections c JOIN matches m ON m.organization_id=c.organization_id AND m.id=c.match_id WHERE c.organization_id=NEW.organization_id AND c.match_id=NEW.match_id AND c.id=NEW.correction_id AND c.match_version=m.version AND c.number=(SELECT MAX(number) FROM match_score_corrections WHERE organization_id=NEW.organization_id AND match_id=NEW.match_id))';
        foreach (['match_score_corrections', 'match_score_recalculations'] as $table) {
            $condition = $table === 'match_score_corrections' ? $invalid : $completion;
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_insert BEFORE INSERT ON {$table} WHEN $condition BEGIN SELECT RAISE(ABORT,'Invalid match correction'); END");
                foreach (['update', 'delete'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_{$operation} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT,'Immutable match correction'); END");
                }
            } else {
                DB::unprepared("CREATE FUNCTION {$table}_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Immutable match correction'; END IF;
                    PERFORM 1 FROM matches WHERE organization_id=NEW.organization_id AND id=NEW.match_id FOR UPDATE;
                    IF $condition THEN RAISE EXCEPTION 'Invalid match correction'; END IF; RETURN NEW; END \$\$");
                DB::unprepared("CREATE TRIGGER {$table}_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_guard()");
            }
        }
        foreach (['match_result_events', 'match_game_instances', 'match_score_rules', 'matches'] as $table) {
            $condition = $table === 'matches' ? 'EXISTS (SELECT 1 FROM match_score_corrections c WHERE c.organization_id=NEW.organization_id AND c.match_id=NEW.id AND NOT EXISTS (SELECT 1 FROM match_score_recalculations r WHERE r.organization_id=c.organization_id AND r.match_id=c.match_id AND r.correction_id=c.id))' : $pending;
            $operation = $table === 'matches' ? 'UPDATE' : 'INSERT';
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_recalculation_freeze BEFORE {$operation} ON {$table} WHEN $condition BEGIN SELECT RAISE(ABORT,'Match recalculation pending'); END");
            } else {
                $matchColumn = $table === 'matches' ? 'id' : 'match_id';
                DB::unprepared("CREATE FUNCTION {$table}_recalculation_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
                    PERFORM 1 FROM matches WHERE organization_id=NEW.organization_id AND id=NEW.{$matchColumn} FOR UPDATE;
                    IF $condition THEN RAISE EXCEPTION 'Match recalculation pending'; END IF; RETURN NEW; END \$\$");
                DB::unprepared("CREATE TRIGGER {$table}_recalculation_freeze BEFORE {$operation} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_recalculation_guard()");
            }
        }
    }

    public function down(): void
    {
        foreach (['match_score_recalculations', 'match_score_corrections'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Match correction history must be retained.');
            }
        }
        foreach (['match_result_events', 'match_game_instances', 'match_score_rules', 'matches'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_recalculation_freeze".(DB::getDriverName() === 'pgsql' ? " ON {$table}" : ''));
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION IF EXISTS {$table}_recalculation_guard()");
            }
        }
        foreach (['match_score_recalculations', 'match_score_corrections'] as $table) {
            Schema::dropIfExists($table);
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP FUNCTION IF EXISTS {$table}_guard()");
            }
        }
    }
};
