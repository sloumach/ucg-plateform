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
    ];

    public function up(): void
    {
        Schema::create('retention_guard_definitions', function (Blueprint $table): void {
            $table->string('id', 200)->primary();
            $table->string('kind', 20);
            $table->string('table_name', 100)->nullable();
            $table->text('original_sql');
            $table->char('wrapped_hash', 64);
        });
        if (DB::getDriverName() === 'pgsql') {
            $this->postgres();
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->sqlite();
        } else {
            throw new LogicException('Scoped erasure guards require PostgreSQL or SQLite.');
        }
    }

    public function down(): void
    {
        if (DB::table('tenant_erasure_operations')->exists()) {
            throw new LogicException('Erasure audit requires a forward fix.');
        }
        foreach (DB::table('retention_guard_definitions')->orderBy('id')->get() as $record) {
            if ($record->kind === 'pg_trigger') {
                continue;
            }
            if ($record->kind === 'function') {
                $actual = DB::selectOne('SELECT pg_get_functiondef(to_regprocedure(?)) AS definition', [$record->id])->definition;
                if (! is_string($actual) || ! hash_equals($record->wrapped_hash, hash('sha256', $actual))) {
                    throw new LogicException('A newer SQL guard cannot be overwritten by rollback.');
                }
                DB::connection()->getPdo()->exec($record->original_sql);
            } elseif (Schema::hasTable($record->table_name)) {
                $actual = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $record->id)->value('sql');
                if (! is_string($actual) || ! hash_equals($record->wrapped_hash, hash('sha256', $actual))) {
                    throw new LogicException('A newer SQL guard cannot be overwritten by rollback.');
                }
                DB::connection()->getPdo()->exec('DROP TRIGGER "'.$record->id.'"');
                DB::connection()->getPdo()->exec($record->original_sql);
            }
        }
        Schema::dropIfExists('retention_guard_definitions');
        if (DB::getDriverName() === 'pgsql') {
            DB::connection()->getPdo()->exec('DROP FUNCTION IF EXISTS ucg_manual_erasure_allowed(text, jsonb, jsonb, text)');
        }
    }

    private function postgres(): void
    {
        DB::connection()->getPdo()->exec(<<<'SQL'
            CREATE FUNCTION ucg_manual_erasure_allowed(resource_table text, old_data jsonb, new_data jsonb, resource_action text) RETURNS boolean AS $$
            DECLARE
                permit record; item record; organization uuid;
            BEGIN
                IF resource_action NOT IN ('DELETE', 'UPDATE') OR old_data IS NULL THEN RETURN false; END IF;
                organization := (CASE WHEN resource_table = 'organizations' THEN old_data ->> 'id' ELSE old_data ->> 'organization_id' END)::uuid;
                SELECT p.* INTO permit FROM tenant_erasure_permits p
                    JOIN tenant_erasure_operations o ON o.id = p.operation_id AND o.organization_id = p.organization_id
                    JOIN organizations r ON r.id = p.organization_id AND r.retention_version = p.control_version
                    JOIN organization_retention_events e ON e.organization_id = r.id AND e.version = r.retention_version
                    WHERE p.organization_id = organization AND p.table_name = resource_table
                        AND p.backend_id = pg_backend_pid() AND p.transaction_id = txid_current()::text
                        AND p.row_key = old_data ->> p.key_column AND o.status = 'erasing'
                        AND p.key_column = CASE WHEN resource_table IN ('organization_settings', 'tenant_storage_usage') THEN 'organization_id' WHEN resource_table = 'dashboard_source_versions' THEN 'card' ELSE 'id' END
                        AND (o.manifest::jsonb -> 'order' ? resource_table OR (resource_action = 'UPDATE' AND resource_table IN ('organizations', 'tenant_storage_usage', 'dashboard_source_versions')))
                        AND (o.scope = 'organization' OR resource_table IN ('tenant_storage_usage', 'dashboard_source_versions')
                            OR (resource_table = 'people' AND old_data ->> 'id' = o.target_id::text)
                            OR (resource_table IN ('person_game_profiles', 'person_periods', 'person_departures', 'person_departure_items') AND old_data ->> 'person_id' = o.target_id::text)
                            OR (resource_table = 'person_external_accounts' AND EXISTS (SELECT 1 FROM person_game_profiles g WHERE g.organization_id = o.organization_id AND g.person_id = o.target_id AND g.id::text = old_data ->> 'game_profile_id'))
                            OR (resource_table = 'person_imports' AND o.manifest::jsonb -> 'imports' ? (old_data ->> 'id'))
                            OR (resource_table = 'person_import_rows' AND old_data ->> 'person_id' = o.target_id::text AND o.manifest::jsonb -> 'imports' ? (old_data ->> 'import_id'))
                            OR (resource_table = 'tenant_artifacts' AND EXISTS (SELECT 1 FROM jsonb_array_elements(o.manifest::jsonb -> 'files') f WHERE f ->> 'kind' = 'artifact' AND f ->> 'id' = old_data ->> 'id')))
                        AND jsonb_array_length(e.state::jsonb -> 'holds') = 0
                        AND e.state::jsonb ->> 'quarantine' = 'erasure:' || o.id::text
                    LIMIT 1;
                IF NOT FOUND THEN RETURN false; END IF;
                IF resource_action = 'DELETE' THEN RETURN permit.expected IS NULL AND resource_table <> 'organizations'; END IF;
                IF permit.expected IS NULL OR new_data IS NULL OR new_data ->> permit.key_column IS DISTINCT FROM old_data ->> permit.key_column
                    OR new_data ->> 'organization_id' IS DISTINCT FROM old_data ->> 'organization_id'
                    OR (resource_table = 'organizations' AND new_data ->> 'owner_user_id' IS DISTINCT FROM old_data ->> 'owner_user_id') THEN RETURN false; END IF;
                FOR item IN SELECT key, value FROM jsonb_each(new_data) LOOP
                    IF item.value IS DISTINCT FROM old_data -> item.key
                        AND (NOT (permit.expected::jsonb ? item.key) OR item.value IS DISTINCT FROM permit.expected::jsonb -> item.key) THEN
                        RETURN false;
                    END IF;
                END LOOP;
                FOR item IN SELECT key, value FROM jsonb_each(permit.expected::jsonb) LOOP
                    IF new_data -> item.key IS DISTINCT FROM item.value THEN RETURN false; END IF;
                END LOOP;
                RETURN true;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
        $placeholders = implode(',', array_fill(0, count(self::TABLES), '?'));
        $records = DB::select('SELECT DISTINCT p.oid, p.prosrc, pg_get_functiondef(p.oid) AS definition, n.nspname AS schema, p.proname, l.lanname
            FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace cn ON cn.oid = c.relnamespace
            JOIN pg_proc p ON p.oid = t.tgfoid JOIN pg_namespace n ON n.oid = p.pronamespace JOIN pg_language l ON l.oid = p.prolang
            WHERE NOT t.tgisinternal AND cn.nspname = current_schema() AND c.relname IN ('.$placeholders.')
                AND ((t.tgtype::int & 8) <> 0 OR (t.tgtype::int & 16) <> 0)', self::TABLES);
        foreach ($records as $record) {
            if ($record->lanname !== 'plpgsql' || ! preg_match('/^[a-z_][a-z0-9_]*$/D', $record->schema)
                || ! preg_match('/^[a-z_][a-z0-9_]*$/D', $record->proname)) {
                throw new LogicException('Unreviewed trigger implementation.');
            }
            $prefix = 'BEGIN
                IF TG_OP = \'DELETE\' AND ucg_manual_erasure_allowed(TG_TABLE_NAME, to_jsonb(OLD), NULL, TG_OP) THEN RETURN OLD; END IF;
                IF TG_OP = \'UPDATE\' AND ucg_manual_erasure_allowed(TG_TABLE_NAME, to_jsonb(OLD), to_jsonb(NEW), TG_OP) THEN RETURN NEW; END IF;';
            $body = preg_replace('/\bBEGIN\b/i', $prefix, $record->prosrc, 1, $replaced);
            if ($replaced !== 1 || $body === null || str_contains($body, '$ucg_erasure_wrapper$')) {
                throw new LogicException('Unreviewed trigger body.');
            }
            $id = $record->schema.'.'.$record->proname.'()';
            $definition = str_replace($record->prosrc, $body, $record->definition, $occurrences);
            if ($occurrences !== 1) {
                throw new LogicException('Ambiguous trigger definition.');
            }
            DB::connection()->getPdo()->exec($definition);
            $wrapped = DB::selectOne('SELECT pg_get_functiondef(?::oid) AS definition', [$record->oid])->definition;
            DB::table('retention_guard_definitions')->insert(['id' => $id, 'kind' => 'function', 'table_name' => null,
                'original_sql' => $record->definition, 'wrapped_hash' => hash('sha256', $wrapped)]);
        }
        $helper = DB::selectOne("SELECT n.nspname || '.ucg_manual_erasure_allowed(text, jsonb, jsonb, text)' AS identity, pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname = 'ucg_manual_erasure_allowed'");
        DB::table('retention_guard_definitions')->insert(['id' => $helper->identity, 'kind' => 'function', 'table_name' => null,
            'original_sql' => $helper->definition, 'wrapped_hash' => hash('sha256', $helper->definition)]);
        foreach (DB::select('SELECT c.relname, t.tgname, pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT t.tgisinternal AND n.nspname = current_schema() AND c.relname IN ('.$placeholders.')', self::TABLES) as $trigger) {
            DB::table('retention_guard_definitions')->insert(['id' => $trigger->relname.'.'.$trigger->tgname,
                'kind' => 'pg_trigger', 'table_name' => $trigger->relname, 'original_sql' => $trigger->definition,
                'wrapped_hash' => hash('sha256', $trigger->definition)]);
        }
    }

    private function sqlite(): void
    {
        $records = DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', self::TABLES)->orderBy('name')->get();
        foreach ($records as $record) {
            if (! preg_match('/\b(?:BEFORE|AFTER)\s+(UPDATE|DELETE)\b/i', $record->sql, $operation)) {
                continue;
            }
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $record->name)) {
                throw new LogicException('Unreviewed trigger identifier.');
            }
            $columns = Schema::getColumnListing($record->tbl_name);
            $old = $this->jsonRow($columns, 'OLD');
            $new = strtoupper($operation[1]) === 'DELETE' ? 'NULL' : $this->jsonRow($columns, 'NEW');
            $allowed = "ucg_manual_erasure_allowed('".$record->tbl_name."', ".$old.', '.$new.", '".strtoupper($operation[1])."') = 1";
            $sql = preg_replace_callback('/\bON\s+"?'.preg_quote($record->tbl_name, '/').'"?(?:\s+WHEN\s+(.*?))?\s+BEGIN\b/is',
                static fn (array $match): string => 'ON "'.$record->tbl_name.'" WHEN '.(isset($match[1]) && trim($match[1]) !== '' ? '('.$match[1].') AND ' : '').'NOT ('.$allowed.') BEGIN',
                $record->sql, 1, $replaced);
            if ($replaced !== 1 || $sql === null) {
                throw new LogicException('Unreviewed SQLite trigger body.');
            }
            DB::connection()->getPdo()->exec('DROP TRIGGER "'.$record->name.'"');
            DB::connection()->getPdo()->exec($sql);
            $wrapped = DB::table('sqlite_master')->where('type', 'trigger')->where('name', $record->name)->value('sql');
            DB::table('retention_guard_definitions')->insert(['id' => $record->name, 'kind' => 'trigger', 'table_name' => $record->tbl_name,
                'original_sql' => $record->sql, 'wrapped_hash' => hash('sha256', $wrapped)]);
        }
    }

    /** @param list<string> $columns */
    private function jsonRow(array $columns, string $record): string
    {
        return 'json_object('.implode(', ', array_map(static fn (string $column): string => "'".$column."', ".$record.'."'.$column.'"', $columns)).')';
    }
};
