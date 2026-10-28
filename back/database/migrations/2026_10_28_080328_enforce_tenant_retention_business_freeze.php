<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = [
        0 => 'academy_session_revisions',
        1 => 'academy_attendances',
        2 => 'academy_objectives',
        3 => 'academy_feedback',
        4 => 'academy_coaching_revisions',
        5 => 'academy_sessions',
        6 => 'academy_revisions',
        7 => 'academy_enrollments',
        8 => 'academy_cycles',
        9 => 'academy_cohorts',
        10 => 'report_exports',
        11 => 'dashboard_indicator_revisions',
        12 => 'dashboard_indicators',
        13 => 'dashboard_source_versions',
        14 => 'statistical_calculations',
        15 => 'statistical_calculation_sources',
        16 => 'statistical_calculation_revisions',
        17 => 'statistical_import_executions',
        18 => 'statistical_import_receipts',
        19 => 'statistical_imports',
        20 => 'observation_revisions',
        21 => 'statistical_observations',
        22 => 'competition_outcome_matches',
        23 => 'competition_outcome_revisions',
        24 => 'competition_registration_reminders',
        25 => 'competition_registration_revisions',
        26 => 'external_competition_documents',
        27 => 'external_competition_revisions',
        28 => 'external_competitions',
        29 => 'match_score_recalculations',
        30 => 'match_score_corrections',
        31 => 'match_notebook_revisions',
        32 => 'match_result_events',
        33 => 'match_game_instances',
        34 => 'match_score_rules',
        35 => 'match_lineup_revisions',
        36 => 'match_status_events',
        37 => 'match_participants',
        38 => 'matches',
        39 => 'calendar_change_recipients',
        40 => 'calendar_changes',
        41 => 'calendar_feeds',
        42 => 'calendar_invitations',
        43 => 'calendar_invitation_deliveries',
        44 => 'calendar_resources',
        45 => 'calendar_reservations',
        46 => 'calendar_conflict_overrides',
        47 => 'availabilities',
        48 => 'availability_revisions',
        49 => 'calendar_activities',
        50 => 'calendar_participants',
        51 => 'announcements',
        52 => 'announcement_comments',
        53 => 'communication_events',
        54 => 'communication_preferences',
        55 => 'communication_receipts',
        56 => 'media_items',
        57 => 'sponsorship_campaigns',
        58 => 'sponsorship_deliverables',
        59 => 'sponsorship_campaign_events',
        60 => 'sponsorship_agreements',
        61 => 'sponsorship_agreement_terms',
        62 => 'sponsorship_exposures',
        63 => 'sponsorship_agreement_events',
        64 => 'sponsors',
        65 => 'sponsor_contacts',
        66 => 'sponsorship_opportunities',
        67 => 'sponsorship_interactions',
        68 => 'organization_sensitive_access_events',
        69 => 'contract_reminder_plans',
        70 => 'contract_reminder_deliveries',
        71 => 'contract_financial_terms',
        72 => 'contract_amendment_approvals',
        73 => 'contract_approved_copies',
        74 => 'contracts',
        75 => 'contract_parties',
        76 => 'contract_obligations',
        77 => 'contract_transitions',
        78 => 'contract_documents',
        79 => 'contract_document_versions',
        80 => 'contract_document_signatures',
        81 => 'contract_document_scans',
        82 => 'external_opponents',
        83 => 'external_opponent_snapshots',
        84 => 'team_eligibility_rulesets',
        85 => 'team_lineup_rule_bindings',
        86 => 'team_lineup_eligibility_reports',
        87 => 'team_lineups',
        88 => 'team_lineup_entries',
        89 => 'team_memberships',
        90 => 'team_movements',
        91 => 'team_scope_grants',
        92 => 'teams',
        93 => 'organization_games',
        94 => 'person_imports',
        95 => 'person_import_rows',
        96 => 'organizations',
        97 => 'organization_settings',
        98 => 'organization_lifecycle_events',
        99 => 'organization_memberships',
        100 => 'organization_invitations',
        101 => 'organization_access_events',
        102 => 'organization_roles',
        103 => 'organization_role_assignments',
        104 => 'organization_support_accesses',
        105 => 'organization_support_events',
        106 => 'organization_audit_events',
        107 => 'organization_retention_events',
        108 => 'people',
        109 => 'person_game_profiles',
        110 => 'person_external_accounts',
        111 => 'person_periods',
        112 => 'person_departure_items',
        113 => 'person_departures',
        114 => 'tenant_storage_usage',
        115 => 'tenant_artifacts',
        116 => 'tenant_portability_operations',
    ]; // Frozen inventory, populated from the reviewed schema at authoring time.

    private const array AUDIT_TABLES = ['organization_retention_events', 'organization_sensitive_access_events', 'organization_audit_events', 'organization_access_events', 'organization_support_events', 'organization_lifecycle_events'];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new LogicException('Retention freeze requires PostgreSQL or SQLite.');
        }
        Schema::table('organizations', fn (Blueprint $table) => $table->unsignedInteger('retention_version')->default(0));
        DB::table('organization_retention_events')->selectRaw('organization_id, MAX(version) AS version')
            ->groupBy('organization_id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('organizations')->where('id', $row->organization_id)->update(['retention_version' => $row->version]);
                }
            }, 'organization_id');
        if (DB::getDriverName() === 'pgsql') {
            $this->postgres();
        } else {
            $this->sqlite();
        }
    }

    public function down(): void
    {
        if (DB::table('organization_retention_events')->exists()) {
            throw new LogicException('Retention decisions require a forward fix; their business protection cannot be rolled back.');
        }
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (DB::getDriverName() === 'pgsql') {
                DB::connection()->getPdo()->exec('DROP TRIGGER IF EXISTS ucg_retention_business_guard ON "'.$table.'"');
            } else {
                foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                    DB::connection()->getPdo()->exec('DROP TRIGGER IF EXISTS "ucg_retention_'.$table.'_'.$operation.'"');
                }
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::connection()->getPdo()->exec('DROP FUNCTION IF EXISTS ucg_retention_business_guard()');
        }
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('retention_version'));
    }

    private function postgres(): void
    {
        DB::connection()->getPdo()->exec(<<<'SQL'
            CREATE FUNCTION ucg_retention_business_guard() RETURNS trigger AS $$
            DECLARE
                old_data jsonb; new_data jsonb; source_id uuid; target_id uuid; root_record record; decision record;
            BEGIN
                IF TG_OP <> 'INSERT' THEN old_data := to_jsonb(OLD); END IF;
                IF TG_OP <> 'DELETE' THEN new_data := to_jsonb(NEW); END IF;
                IF TG_TABLE_NAME = 'tenant_portability_operations' THEN
                    IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
                END IF;
                IF TG_TABLE_NAME IN ('organization_retention_events', 'organization_sensitive_access_events', 'organization_audit_events',
                    'organization_access_events', 'organization_support_events', 'organization_lifecycle_events') AND TG_OP = 'INSERT' THEN
                    RETURN NEW;
                END IF;
                IF TG_TABLE_NAME = 'tenant_artifacts' AND TG_OP = 'UPDATE' AND new_data - 'updated_at' = old_data - 'updated_at' THEN
                    RETURN NEW;
                END IF;
                IF TG_TABLE_NAME = 'organizations' THEN
                    IF TG_OP = 'INSERT' THEN
                        IF NEW.retention_version <> 0 THEN RAISE EXCEPTION 'Invalid retention revision' USING ERRCODE = '23514'; END IF;
                        RETURN NEW;
                    END IF;
                    IF TG_OP = 'UPDATE' AND NEW.retention_version IS DISTINCT FROM OLD.retention_version THEN
                        IF NEW.retention_version = OLD.retention_version + 1 AND new_data - 'retention_version' = old_data - 'retention_version'
                            AND EXISTS (SELECT 1 FROM organization_retention_events WHERE organization_id = OLD.id AND version = NEW.retention_version) THEN
                            RETURN NEW;
                        END IF;
                        RAISE EXCEPTION 'Invalid retention revision' USING ERRCODE = '23514';
                    END IF;
                    source_id := (old_data ->> 'id')::uuid; target_id := (new_data ->> 'id')::uuid;
                ELSE
                    source_id := (old_data ->> 'organization_id')::uuid; target_id := (new_data ->> 'organization_id')::uuid;
                END IF;
                -- The revision changes the root tuple on every decision, including release. A stale
                -- REPEATABLE READ writer therefore serializes or aborts instead of ignoring a new hold.
                FOR root_record IN SELECT id, retention_version FROM organizations WHERE id IN (source_id, target_id) ORDER BY id FOR UPDATE LOOP
                    SELECT version, state::jsonb AS state INTO decision FROM organization_retention_events
                        WHERE organization_id = root_record.id ORDER BY version DESC LIMIT 1;
                    IF root_record.retention_version <> COALESCE(decision.version, 0)
                        OR (decision.version IS NOT NULL AND (jsonb_typeof(decision.state -> 'holds') IS DISTINCT FROM 'array'
                        OR decision.state ->> 'version' IS DISTINCT FROM decision.version::text
                        OR jsonb_array_length(decision.state -> 'holds') > 0
                        OR decision.state -> 'quarantine' IS DISTINCT FROM 'null'::jsonb)) THEN
                        RAISE EXCEPTION 'Retention preservation blocks business writes' USING ERRCODE = '23514';
                    END IF;
                END LOOP;
                IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
        foreach (self::TABLES as $table) {
            DB::connection()->getPdo()->exec('CREATE TRIGGER ucg_retention_business_guard BEFORE INSERT OR UPDATE OR DELETE ON "'.$table.'" FOR EACH ROW EXECUTE FUNCTION ucg_retention_business_guard()');
        }
    }

    private function sqlite(): void
    {
        foreach (self::TABLES as $table) {
            $columns = Schema::getColumnListing($table);
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                if ($table === 'tenant_portability_operations' || ($operation === 'INSERT' && in_array($table, self::AUDIT_TABLES, true))) {
                    continue;
                }
                $checks = [];
                foreach ($operation === 'UPDATE' ? ['OLD', 'NEW'] : [$operation === 'DELETE' ? 'OLD' : 'NEW'] as $record) {
                    $id = $record.'."'.($table === 'organizations' ? 'id' : 'organization_id').'"';
                    $checks[] = '(EXISTS (SELECT 1 FROM organization_retention_events e WHERE e.organization_id = '.$id
                        .' AND e.version = (SELECT MAX(version) FROM organization_retention_events WHERE organization_id = '.$id.')'
                        ." AND (json_type(e.state, '$.holds') IS NOT 'array' OR json_array_length(e.state, '$.holds') > 0"
                        ." OR json_type(e.state, '$.quarantine') IS NOT 'null' OR json_extract(e.state, '$.version') IS NOT e.version))"
                        .' OR (SELECT retention_version FROM organizations WHERE id = '.$id.') IS NOT COALESCE((SELECT MAX(version) FROM organization_retention_events WHERE organization_id = '.$id.'), 0))';
                }
                $when = '('.implode(' OR ', $checks).')';
                if ($table === 'tenant_artifacts' && $operation === 'UPDATE') {
                    $when .= ' AND NOT ('.$this->sameColumns($columns, ['updated_at']).')';
                }
                if ($table === 'organizations' && $operation === 'INSERT') {
                    $when = 'NEW.retention_version <> 0';
                } elseif ($table === 'organizations' && $operation === 'UPDATE') {
                    $revision = '(NEW.retention_version = OLD.retention_version + 1 AND '.$this->sameColumns($columns, ['retention_version'])
                        .' AND EXISTS (SELECT 1 FROM organization_retention_events WHERE organization_id = OLD.id AND version = NEW.retention_version))';
                    $when = '((NEW.retention_version IS NOT OLD.retention_version OR '.$when.') AND NOT '.$revision.')';
                }
                DB::connection()->getPdo()->exec('CREATE TRIGGER "ucg_retention_'.$table.'_'.$operation.'" BEFORE '.$operation.' ON "'.$table.'" WHEN '.$when
                    ." BEGIN SELECT RAISE(ABORT, 'Retention preservation blocks business writes'); END;");
            }
        }
    }

    /** @param list<string> $columns
     * @param  list<string>  $except
     */
    private function sameColumns(array $columns, array $except): string
    {
        return implode(' AND ', array_map(fn (string $column): string => 'NEW."'.$column.'" IS OLD."'.$column.'"', array_values(array_diff($columns, $except))));
    }
};
