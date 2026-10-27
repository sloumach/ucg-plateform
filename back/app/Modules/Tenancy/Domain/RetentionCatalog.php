<?php

namespace App\Modules\Tenancy\Domain;

final class RetentionCatalog
{
    /** Closed inventory; a new table must receive an explicit family before review. */
    public const array FAMILIES = [
        'organization' => ['organizations', 'organization_settings', 'organization_memberships', 'organization_invitations', 'organization_roles', 'organization_role_assignments', 'organization_support_accesses'],
        'history' => ['organization_sensitive_access_events', 'organization_lifecycle_events', 'organization_access_events', 'organization_support_events', 'organization_audit_events'],
        'people' => ['people', 'person_game_profiles', 'person_external_accounts', 'person_periods', 'person_departure_items', 'person_departures'],
        'people_imports' => ['person_imports', 'person_import_rows'],
        'teams' => ['team_eligibility_rulesets', 'team_lineup_rule_bindings', 'team_lineup_eligibility_reports', 'team_lineups', 'team_lineup_entries', 'team_memberships', 'team_movements', 'team_scope_grants', 'teams'],
        'games' => ['organization_games'],
        'opponents' => ['external_opponents', 'external_opponent_snapshots'],
        'matches' => ['match_score_recalculations', 'match_score_corrections', 'match_notebook_revisions', 'match_result_events', 'match_game_instances', 'match_score_rules', 'match_lineup_revisions', 'match_status_events', 'match_participants', 'matches'],
        'competitions' => ['competition_outcome_matches', 'competition_outcome_revisions', 'competition_registration_reminders', 'competition_registration_revisions', 'external_competition_documents', 'external_competition_revisions', 'external_competitions'],
        'contracts' => ['contract_reminder_plans', 'contract_reminder_deliveries', 'contract_financial_terms', 'contract_amendment_approvals', 'contract_approved_copies', 'contracts', 'contract_parties', 'contract_obligations', 'contract_transitions', 'contract_documents', 'contract_document_versions', 'contract_document_signatures', 'contract_document_scans'],
        'statistics' => ['statistical_calculations', 'statistical_calculation_sources', 'statistical_calculation_revisions', 'statistical_import_executions', 'statistical_import_receipts', 'statistical_imports', 'observation_revisions', 'statistical_observations'],
        'academy' => ['academy_session_revisions', 'academy_attendances', 'academy_objectives', 'academy_feedback', 'academy_coaching_revisions', 'academy_sessions', 'academy_revisions', 'academy_enrollments', 'academy_cycles', 'academy_cohorts'],
        'calendar' => ['calendar_change_recipients', 'calendar_changes', 'calendar_feeds', 'calendar_invitations', 'calendar_invitation_deliveries', 'calendar_resources', 'calendar_reservations', 'calendar_conflict_overrides', 'availabilities', 'availability_revisions', 'calendar_activities', 'calendar_participants'],
        'communication' => ['announcements', 'announcement_comments', 'communication_events', 'communication_preferences', 'communication_receipts'],
        'sponsors' => ['sponsorship_campaigns', 'sponsorship_deliverables', 'sponsorship_campaign_events', 'sponsorship_agreements', 'sponsorship_agreement_terms', 'sponsorship_exposures', 'sponsorship_agreement_events', 'sponsors', 'sponsor_contacts', 'sponsorship_opportunities', 'sponsorship_interactions'],
        'media' => ['media_items'],
        'reporting' => ['report_exports', 'dashboard_indicator_revisions', 'dashboard_indicators', 'dashboard_source_versions'],
        'artifacts' => ['tenant_storage_usage', 'tenant_artifacts', 'tenant_portability_operations'],
    ];

    public const array UNVERIFIED_COPIES = ['exports', 'artifact_versions', 'historical_snapshots', 'imports', 'caches', 'jobs', 'logs', 'replicas', 'backups_pitr', 'providers', 'recipient_downloads'];

    /** @return list<string> */
    public static function tables(): array
    {
        return array_merge(...array_values(self::FAMILIES));
    }
}
