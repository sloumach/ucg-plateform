<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('TEAM-005 requires PostgreSQL or SQLite.');
        }
        if ($pg) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        Schema::table('people', fn (Blueprint $table) => $table->text('birth_date')->nullable());
        Schema::create('team_eligibility_rulesets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->foreignUuid('game_id')->constrained('game_catalogue_entries')->restrictOnDelete();
            $t->string('name', 120);
            $t->uuid('version');
            $t->enum('status', ['active', 'archived']);
            $t->unsignedSmallInteger('min_starters');
            $t->unsignedSmallInteger('max_starters');
            $t->unsignedSmallInteger('max_substitutes');
            $t->string('platform_key', 32)->nullable();
            $t->jsonb('allowed_regions');
            $t->jsonb('allowed_ranks');
            $t->unsignedSmallInteger('min_age')->nullable();
            $t->unsignedSmallInteger('max_age')->nullable();
            $t->unsignedSmallInteger('observation_max_days');
            $t->timestamp('lock_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'id']);
            $t->index(['organization_id', 'game_id', 'created_at', 'id'], 'team_eligibility_rulesets_list_index');
        });
        Schema::create('team_lineup_rule_bindings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('lineup_id');
            $t->uuid('ruleset_id');
            $t->uuid('ruleset_version');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['organization_id', 'id']);
            $t->unique(['organization_id', 'lineup_id']);
            foreach (['lineup_id' => 'team_lineups', 'ruleset_id' => 'team_eligibility_rulesets'] as $key => $parent) {
                $t->foreign(['organization_id', $key])->references(['organization_id', 'id'])->on($parent)->restrictOnDelete();
            }
            $t->index(['organization_id', 'ruleset_id']);
        });
        Schema::create('team_lineup_eligibility_reports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $t->uuid('lineup_id');
            $t->uuid('binding_id');
            $t->uuid('ruleset_id');
            $t->uuid('ruleset_version');
            $t->uuid('lineup_version');
            $t->enum('stage', ['preview', 'final']);
            $t->enum('status', ['eligible', 'ineligible', 'incomplete']);
            $t->json('checks');
            $t->timestamp('evaluated_at');
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            foreach (['lineup_id' => 'team_lineups', 'binding_id' => 'team_lineup_rule_bindings', 'ruleset_id' => 'team_eligibility_rulesets'] as $key => $parent) {
                $t->foreign(['organization_id', $key])->references(['organization_id', 'id'])->on($parent)->restrictOnDelete();
            }
            $t->index(['organization_id', 'lineup_id', 'created_at', 'id'], 'team_lineup_eligibility_reports_list_index');
        });
        DB::statement("CREATE UNIQUE INDEX team_lineup_final_report_unique ON team_lineup_eligibility_reports(organization_id,lineup_id) WHERE stage='final'");
        $diff = $pg ? 'IS DISTINCT FROM' : 'IS NOT';
        $clock = $pg ? "(clock_timestamp() AT TIME ZONE 'UTC')" : "STRFTIME('%Y-%m-%d %H:%M:%f','now')";
        $immutable = implode(' OR ', array_map(fn (string $field): string => "NEW.{$field} {$diff} OLD.{$field}",
            ['id', 'organization_id', 'game_id', 'name', 'version', 'min_starters', 'max_starters', 'max_substitutes', 'platform_key', 'allowed_regions', 'allowed_ranks', 'min_age', 'max_age', 'observation_max_days', 'lock_at', 'created_by', 'created_at']));
        $jsonArrays = $pg ? "jsonb_typeof(NEW.allowed_regions::jsonb)!='array' OR jsonb_array_length(NEW.allowed_regions::jsonb)>32 OR jsonb_typeof(NEW.allowed_ranks::jsonb)!='array' OR jsonb_array_length(NEW.allowed_ranks::jsonb)>32"
            : "json_type(NEW.allowed_regions)!='array' OR json_array_length(NEW.allowed_regions)>32 OR json_type(NEW.allowed_ranks)!='array' OR json_array_length(NEW.allowed_ranks)>32";
        $invalidRules = "NEW.min_starters<1 OR NEW.max_starters<NEW.min_starters OR NEW.max_starters+NEW.max_substitutes>50 OR NEW.max_substitutes<0
            OR NEW.observation_max_days<1 OR NEW.observation_max_days>3650 OR NEW.min_age<0 OR NEW.min_age>120 OR NEW.max_age<0 OR NEW.max_age>120
            OR (NEW.min_age IS NOT NULL AND NEW.max_age IS NOT NULL AND NEW.max_age<NEW.min_age) OR ({$jsonArrays})";
        $bindingValid = "EXISTS(SELECT 1 FROM team_lineups l JOIN team_eligibility_rulesets r ON r.id=NEW.ruleset_id AND r.organization_id=l.organization_id
            WHERE l.id=NEW.lineup_id AND l.organization_id=NEW.organization_id AND l.status='draft' AND l.game_id=r.game_id AND r.version=NEW.ruleset_version
                AND r.status='active' AND (r.lock_at IS NULL OR r.lock_at>{$clock}))";
        $reportValid = "EXISTS(SELECT 1 FROM team_lineup_rule_bindings b JOIN team_lineups l ON l.id=b.lineup_id AND l.organization_id=b.organization_id
            WHERE b.id=NEW.binding_id AND b.organization_id=NEW.organization_id AND b.lineup_id=NEW.lineup_id AND b.ruleset_id=NEW.ruleset_id
                AND b.ruleset_version=NEW.ruleset_version AND l.status='draft')";
        $checksValid = $pg ? "jsonb_typeof(NEW.checks::jsonb)='array' AND jsonb_array_length(NEW.checks::jsonb)<=256" : "json_type(NEW.checks)='array' AND json_array_length(NEW.checks)<=256";
        $invalidReport = "NOT ({$reportValid}) OR NOT ({$checksValid}) OR (NEW.stage='final' AND NEW.status!='eligible')
            OR (SELECT COUNT(*) FROM team_lineup_eligibility_reports e WHERE e.organization_id=NEW.organization_id AND e.lineup_id=NEW.lineup_id)>=100
            OR (NEW.stage='preview' AND (SELECT COUNT(*) FROM team_lineup_eligibility_reports e WHERE e.organization_id=NEW.organization_id AND e.lineup_id=NEW.lineup_id AND e.stage='preview')>=99)";
        $expired = "EXISTS(SELECT 1 FROM team_lineup_rule_bindings b JOIN team_eligibility_rulesets r ON r.id=b.ruleset_id AND r.organization_id=b.organization_id
            WHERE b.lineup_id=NEW.id AND b.organization_id=NEW.organization_id AND r.lock_at IS NOT NULL AND r.lock_at<={$clock})";
        $bound = 'EXISTS(SELECT 1 FROM team_lineup_rule_bindings WHERE lineup_id=NEW.id AND organization_id=NEW.organization_id)';
        $proof = "EXISTS(SELECT 1 FROM team_lineup_eligibility_reports e JOIN team_lineup_rule_bindings b ON b.id=e.binding_id AND b.organization_id=e.organization_id
            WHERE e.lineup_id=NEW.id AND e.organization_id=NEW.organization_id AND e.lineup_version=NEW.version AND e.stage='final' AND e.status='eligible')";
        $entryOldExpired = "EXISTS(SELECT 1 FROM team_lineup_rule_bindings b JOIN team_eligibility_rulesets r ON r.id=b.ruleset_id AND r.organization_id=b.organization_id
                WHERE b.lineup_id=OLD.lineup_id AND b.organization_id=OLD.organization_id AND r.lock_at IS NOT NULL AND r.lock_at<={$clock})";
        $entryNewExpired = "EXISTS(SELECT 1 FROM team_lineup_rule_bindings b JOIN team_eligibility_rulesets r ON r.id=b.ruleset_id AND r.organization_id=b.organization_id
                WHERE b.lineup_id=NEW.lineup_id AND b.organization_id=NEW.organization_id AND r.lock_at IS NOT NULL AND r.lock_at<={$clock})";
        if ($pg) {
            DB::unprepared("CREATE FUNCTION ucg_eligibility_rules_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Rules must be retained' USING ERRCODE='23514'; END IF;
                PERFORM id FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                IF ({$invalidRules}) THEN RAISE EXCEPTION 'Invalid eligibility rules' USING ERRCODE='23514'; END IF;
                IF TG_OP='INSERT' AND (NEW.status!='active' OR (SELECT COUNT(*) FROM team_eligibility_rulesets WHERE organization_id=NEW.organization_id)>=1000) THEN RAISE EXCEPTION 'Invalid ruleset capacity or state' USING ERRCODE='23514'; END IF;
                IF TG_OP='UPDATE' AND (OLD.status!='active' OR NEW.status!='archived' OR ({$immutable})) THEN RAISE EXCEPTION 'Rules are immutable' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER eligibility_rules_guard BEFORE INSERT OR UPDATE OR DELETE ON team_eligibility_rulesets FOR EACH ROW EXECUTE FUNCTION ucg_eligibility_rules_guard();
                CREATE FUNCTION ucg_eligibility_binding_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP!='INSERT' THEN RAISE EXCEPTION 'Bindings are immutable' USING ERRCODE='23514'; END IF;
                PERFORM id FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                IF NOT ({$bindingValid}) THEN RAISE EXCEPTION 'Invalid eligibility binding' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER eligibility_binding_guard BEFORE INSERT OR UPDATE OR DELETE ON team_lineup_rule_bindings FOR EACH ROW EXECUTE FUNCTION ucg_eligibility_binding_guard();
                CREATE FUNCTION ucg_eligibility_report_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP!='INSERT' THEN RAISE EXCEPTION 'Reports are append-only' USING ERRCODE='23514'; END IF;
                PERFORM id FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                IF ({$invalidReport}) THEN RAISE EXCEPTION 'Invalid eligibility report' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER eligibility_report_guard BEFORE INSERT OR UPDATE OR DELETE ON team_lineup_eligibility_reports FOR EACH ROW EXECUTE FUNCTION ucg_eligibility_report_guard();
                CREATE FUNCTION ucg_lineup_eligibility_guard() RETURNS trigger AS $$ BEGIN
                PERFORM id FROM organizations WHERE id=NEW.organization_id FOR UPDATE;
                IF NEW.status!='cancelled' AND ({$expired}) THEN RAISE EXCEPTION 'Lineup deadline reached' USING ERRCODE='23514'; END IF;
                IF NEW.status='finalized' AND ({$bound}) AND NOT ({$proof}) THEN RAISE EXCEPTION 'Eligible proof required' USING ERRCODE='23514'; END IF;
                RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER lineup_eligibility_guard BEFORE UPDATE ON team_lineups FOR EACH ROW EXECUTE FUNCTION ucg_lineup_eligibility_guard();
                CREATE FUNCTION ucg_entry_eligibility_guard() RETURNS trigger AS $$ BEGIN
                IF TG_OP='DELETE' THEN IF ({$entryOldExpired}) THEN RAISE EXCEPTION 'Lineup deadline reached' USING ERRCODE='23514'; END IF; RETURN OLD; END IF;
                IF ({$entryNewExpired}) THEN RAISE EXCEPTION 'Lineup deadline reached' USING ERRCODE='23514'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER entry_eligibility_guard BEFORE INSERT OR DELETE ON team_lineup_entries FOR EACH ROW EXECUTE FUNCTION ucg_entry_eligibility_guard()");
        } else {
            DB::unprepared("CREATE TRIGGER eligibility_rules_insert BEFORE INSERT ON team_eligibility_rulesets WHEN NEW.status!='active' OR ({$invalidRules}) OR (SELECT COUNT(*) FROM team_eligibility_rulesets WHERE organization_id=NEW.organization_id)>=1000 BEGIN SELECT RAISE(ABORT,'Invalid eligibility rules'); END");
            DB::unprepared("CREATE TRIGGER eligibility_rules_update BEFORE UPDATE ON team_eligibility_rulesets WHEN OLD.status!='active' OR NEW.status!='archived' OR ({$immutable}) BEGIN SELECT RAISE(ABORT,'Rules are immutable'); END");
            DB::unprepared("CREATE TRIGGER eligibility_rules_delete BEFORE DELETE ON team_eligibility_rulesets BEGIN SELECT RAISE(ABORT,'Rules must be retained'); END");
            foreach (['team_lineup_rule_bindings' => 'eligibility_binding', 'team_lineup_eligibility_reports' => 'eligibility_report'] as $table => $trigger) {
                foreach (['UPDATE', 'DELETE'] as $op) {
                    DB::unprepared("CREATE TRIGGER {$trigger}_".strtolower($op)." BEFORE {$op} ON {$table} BEGIN SELECT RAISE(ABORT,'Eligibility history is immutable'); END");
                }
            }
            DB::unprepared("CREATE TRIGGER eligibility_binding_insert BEFORE INSERT ON team_lineup_rule_bindings WHEN NOT ({$bindingValid}) BEGIN SELECT RAISE(ABORT,'Invalid eligibility binding'); END");
            DB::unprepared("CREATE TRIGGER eligibility_report_insert BEFORE INSERT ON team_lineup_eligibility_reports WHEN ({$invalidReport}) BEGIN SELECT RAISE(ABORT,'Invalid eligibility report'); END");
            DB::unprepared("CREATE TRIGGER lineup_eligibility_guard BEFORE UPDATE ON team_lineups WHEN (NEW.status!='cancelled' AND ({$expired})) OR (NEW.status='finalized' AND ({$bound}) AND NOT ({$proof})) BEGIN SELECT RAISE(ABORT,'Lineup deadline or eligibility proof invalid'); END");
            foreach (['INSERT' => 'NEW', 'DELETE' => 'OLD'] as $op => $row) {
                DB::unprepared('CREATE TRIGGER entry_eligibility_'.strtolower($op)." BEFORE {$op} ON team_lineup_entries WHEN (".($row === 'OLD' ? $entryOldExpired : $entryNewExpired).") BEGIN SELECT RAISE(ABORT,'Lineup deadline reached'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['team_eligibility_rulesets', 'team_lineup_rule_bindings', 'team_lineup_eligibility_reports'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('TEAM-005 rollback cannot discard rules or evaluations. Use a forward fix.');
            }
        }
        if (DB::table('people')->whereNotNull('birth_date')->exists()) {
            throw new RuntimeException('TEAM-005 rollback cannot discard private birth dates.');
        }
        $pg = DB::getDriverName() === 'pgsql';
        if ($pg) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::unprepared('DROP TRIGGER lineup_eligibility_guard ON team_lineups; DROP TRIGGER entry_eligibility_guard ON team_lineup_entries');
        } else {
            foreach (['lineup_eligibility_guard', 'entry_eligibility_insert', 'entry_eligibility_delete'] as $trigger) {
                DB::unprepared("DROP TRIGGER {$trigger}");
            }
        }
        Schema::drop('team_lineup_eligibility_reports');
        Schema::drop('team_lineup_rule_bindings');
        Schema::drop('team_eligibility_rulesets');
        Schema::table('people', fn (Blueprint $t) => $t->dropColumn('birth_date'));
        if ($pg) {
            DB::unprepared('DROP FUNCTION ucg_entry_eligibility_guard(); DROP FUNCTION ucg_lineup_eligibility_guard(); DROP FUNCTION ucg_eligibility_report_guard(); DROP FUNCTION ucg_eligibility_binding_guard(); DROP FUNCTION ucg_eligibility_rules_guard()');
        }
    }
};
