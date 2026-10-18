<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Architecture\Database\TableOwnership;
use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

final readonly class DatabasePortabilityRepository implements PortabilityRepository
{
    /** Closed inventory; register new tenant tables explicitly before claiming a complete export. */
    public const array TABLES = [
        'match_score_recalculations' => 'id', 'match_score_corrections' => 'id', 'match_notebook_revisions' => 'id', 'match_result_events' => 'id', 'match_game_instances' => 'id', 'match_score_rules' => 'id', 'match_lineup_revisions' => 'id', 'match_status_events' => 'id', 'match_participants' => 'id', 'matches' => 'id',
        'calendar_change_recipients' => 'id', 'calendar_changes' => 'id',
        'calendar_feeds' => 'id',
        'calendar_invitations' => 'id', 'calendar_invitation_deliveries' => 'id',
        'calendar_resources' => 'id', 'calendar_reservations' => 'id', 'calendar_conflict_overrides' => 'id',
        'availabilities' => 'id', 'availability_revisions' => 'id',
        'calendar_activities' => 'id',
        'calendar_participants' => 'id',
        'announcements' => 'id',
        'announcement_comments' => 'id',
        'communication_events' => 'id',
        'communication_preferences' => 'id',
        'communication_receipts' => 'id',
        'media_items' => 'id',
        'sponsorship_campaigns' => 'id', 'sponsorship_deliverables' => 'id', 'sponsorship_campaign_events' => 'id',
        'sponsorship_agreements' => 'id', 'sponsorship_agreement_terms' => 'id', 'sponsorship_exposures' => 'id', 'sponsorship_agreement_events' => 'id',
        'sponsors' => 'id', 'sponsor_contacts' => 'id', 'sponsorship_opportunities' => 'id', 'sponsorship_interactions' => 'id',
        'organization_sensitive_access_events' => 'id',
        'contract_reminder_plans' => 'id', 'contract_reminder_deliveries' => 'id',
        'contract_financial_terms' => 'id', 'contract_amendment_approvals' => 'id', 'contract_approved_copies' => 'id',
        'contracts' => 'id', 'contract_parties' => 'id', 'contract_obligations' => 'id', 'contract_transitions' => 'id',
        'contract_documents' => 'id', 'contract_document_versions' => 'id', 'contract_document_signatures' => 'id',
        'contract_document_scans' => 'id',
        'external_opponents' => 'id', 'external_opponent_snapshots' => 'id',
        'team_eligibility_rulesets' => 'id', 'team_lineup_rule_bindings' => 'id', 'team_lineup_eligibility_reports' => 'id',
        'team_lineups' => 'id', 'team_lineup_entries' => 'id',
        'team_memberships' => 'id', 'team_movements' => 'id', 'team_scope_grants' => 'id',
        'teams' => 'id',
        'organization_games' => 'id',
        'person_imports' => 'id', 'person_import_rows' => 'id',
        'organizations' => 'id', 'organization_settings' => 'organization_id',
        'organization_lifecycle_events' => 'id', 'organization_memberships' => 'id',
        'organization_invitations' => 'id', 'organization_access_events' => 'id',
        'organization_roles' => 'id', 'organization_role_assignments' => 'id',
        'organization_support_accesses' => 'id', 'organization_support_events' => 'id',
        'organization_audit_events' => 'id',
        'people' => 'id',
        'person_game_profiles' => 'id', 'person_external_accounts' => 'id',
        'person_periods' => 'id', 'person_departure_items' => 'id', 'person_departures' => 'id',
        'tenant_storage_usage' => 'organization_id', 'tenant_artifacts' => 'id',
        'tenant_portability_operations' => 'id',
    ];

    /** Explicit physical columns: adding a field must not silently leak it into an archive. */
    private const array COLUMNS = [
        'match_score_corrections' => ['id', 'organization_id', 'match_id', 'base_match_version', 'match_version', 'base_correction_id', 'idempotency_key', 'fingerprint', 'number', 'before', 'after', 'changed_ids', 'reason', 'actor_user_id', 'created_at'],
        'match_score_recalculations' => ['id', 'organization_id', 'match_id', 'correction_id', 'series', 'actor_user_id', 'created_at'],
        'match_notebook_revisions' => ['id', 'organization_id', 'match_id', 'entry_id', 'number', 'kind', 'audience', 'title', 'body', 'vod_url', 'timestamp_seconds', 'state', 'due_at', 'actor_user_id', 'created_at'],
        'match_result_events' => ['id', 'organization_id', 'match_id', 'base_version', 'match_version', 'number', 'action', 'outcome', 'from_status', 'to_status', 'submission_id', 'proof_reference', 'snapshot', 'contributors', 'reason', 'actor_user_id', 'created_at'],
        'match_score_rules' => ['id', 'organization_id', 'match_id', 'match_version', 'reason', 'actor_user_id', 'created_at', 'format', 'game_limit', 'allow_draw'],
        'match_game_instances' => ['id', 'organization_id', 'match_id', 'match_version', 'reason', 'actor_user_id', 'created_at', 'number', 'outcome', 'map', 'mode', 'game_patch', 'results'],
        'match_lineup_revisions' => ['id', 'organization_id', 'match_id', 'participant_id', 'match_version', 'number', 'action', 'phase', 'snapshot', 'assessment', 'reason', 'actor_user_id', 'created_at'],
        'matches' => ['id', 'organization_id', 'game_id', 'game_key', 'game_name', 'title', 'kind', 'format', 'status', 'starts_at', 'ends_at', 'origin_timezone', 'responsible_person_id', 'responsible_label', 'visibility', 'sharing_authorized_by', 'sharing_authorized_at', 'created_by', 'version', 'created_at', 'updated_at'],
        'match_participants' => ['id', 'organization_id', 'match_id', 'slot', 'kind', 'team_id', 'person_id', 'opponent_snapshot_id', 'reference_version', 'label'],
        'match_status_events' => ['id', 'organization_id', 'match_id', 'from_status', 'to_status', 'match_version', 'actor_user_id', 'reason', 'created_at'],
        'calendar_changes' => ['id', 'organization_id', 'activity_id', 'previous_version', 'activity_version', 'actor_user_id', 'kind', 'title', 'reason', 'previous_starts_at', 'previous_ends_at', 'previous_timezone', 'starts_at', 'ends_at', 'origin_timezone', 'created_at'],
        'calendar_change_recipients' => ['id', 'organization_id', 'change_id', 'person_id', 'recipient_id', 'created_at', 'checked_at', 'delivered_at', 'run_id', 'blocked_reason'],
        'calendar_feeds' => ['id', 'organization_id', 'user_id', 'token_hash', 'before_days', 'after_days', 'expires_at', 'revoked_at', 'version', 'created_at', 'updated_at'],
        'calendar_invitations' => ['id', 'organization_id', 'activity_id', 'activity_version', 'person_id', 'recipient_id', 'issued_by', 'response_deadline', 'reminder_offsets', 'response', 'responded_at', 'sent_at', 'checked_at', 'blocked_reason', 'version', 'created_at', 'updated_at'],
        'calendar_invitation_deliveries' => ['id', 'organization_id', 'invitation_id', 'kind', 'offset_minutes', 'run_id', 'created_at'],
        'calendar_resources' => ['id', 'organization_id', 'key', 'label', 'kind', 'status', 'version', 'created_by', 'created_at', 'updated_at'],
        'calendar_reservations' => ['id', 'organization_id', 'activity_id', 'resource_id', 'created_at'],
        'calendar_conflict_overrides' => ['id', 'organization_id', 'activity_id', 'activity_version', 'actor_user_id', 'reason', 'snapshot', 'conflict_count', 'created_at'],
        'availabilities' => ['id', 'organization_id', 'person_id', 'status', 'comment', 'starts_at', 'ends_at', 'origin_timezone', 'cancelled_at', 'created_by', 'version', 'number', 'created_at', 'updated_at'],
        'availability_revisions' => ['id', 'organization_id', 'availability_id', 'person_id', 'action', 'status', 'comment', 'starts_at', 'ends_at', 'origin_timezone', 'cancelled_at', 'version', 'number', 'actor_user_id', 'created_at'],
        'calendar_activities' => ['id', 'organization_id', 'title', 'description', 'kind', 'starts_at', 'ends_at', 'origin_timezone', 'team_id', 'location', 'url', 'visibility', 'sharing_authorized_by', 'sharing_authorized_at', 'created_by', 'archived_at', 'cancelled_at', 'version', 'created_at', 'updated_at'],
        'calendar_participants' => ['id', 'organization_id', 'activity_id', 'person_id', 'created_at'],
        'announcements' => ['id', 'organization_id', 'title', 'body', 'target_type', 'team_id', 'game_id', 'role_id', 'status', 'comments_enabled', 'created_by', 'published_by', 'published_at', 'expires_at', 'withdrawn_at', 'version', 'created_at', 'updated_at'],
        'announcement_comments' => ['id', 'organization_id', 'announcement_id', 'body', 'created_by', 'created_at'],
        'communication_events' => ['id', 'organization_id', 'announcement_id', 'comment_id', 'kind', 'actor_user_id', 'created_at'],
        'communication_preferences' => ['id', 'organization_id', 'user_id', 'announcements_enabled', 'comments_enabled', 'version', 'created_at', 'updated_at'],
        'communication_receipts' => ['id', 'organization_id', 'event_id', 'user_id', 'read_at'],
        'media_items' => ['id', 'organization_id', 'team_id', 'title', 'kind', 'provider', 'url', 'status', 'status_observed_at', 'is_scrim', 'visibility', 'publish_at', 'sharing_authorized_by', 'sharing_authorized_at', 'archived_at', 'created_by', 'version', 'created_at', 'updated_at'],
        'sponsorship_campaigns' => ['id', 'organization_id', 'agreement_id', 'title', 'starts_on', 'ends_on', 'responsible_user_id', 'status', 'version', 'created_by', 'created_at', 'updated_at'],
        'sponsorship_deliverables' => ['id', 'organization_id', 'agreement_id', 'campaign_id', 'exposure_id', 'kind', 'title', 'responsible_user_id', 'due_at', 'status', 'proof_reference', 'delivered_at', 'delivered_by', 'validated_at', 'validated_by', 'validated_agreement_version', 'validated_exposure_version', 'prepared_by', 'version', 'created_at', 'updated_at'],
        'sponsorship_campaign_events' => ['id', 'organization_id', 'campaign_id', 'deliverable_id', 'action', 'from_status', 'to_status', 'record_version', 'actor_user_id', 'occurred_at', 'delivery_snapshot'],
        'sponsorship_agreements' => ['id', 'organization_id', 'sponsor_id', 'opportunity_id', 'reference', 'title', 'starts_on', 'ends_on', 'responsible_user_id', 'contract_id', 'contract_version', 'status', 'version', 'prepared_by', 'approved_by', 'approved_at', 'approved_financial_version', 'created_at', 'updated_at'],
        'sponsorship_agreement_terms' => ['id', 'organization_id', 'agreement_id', 'terms', 'version', 'prepared_by', 'created_at', 'updated_at'],
        'sponsorship_exposures' => ['id', 'organization_id', 'agreement_id', 'target_type', 'team_id', 'person_id', 'target_label', 'channel', 'description', 'authorization_reference', 'exclusivity_key', 'status', 'version', 'created_at', 'updated_at'],
        'sponsorship_agreement_events' => ['id', 'organization_id', 'agreement_id', 'action', 'from_status', 'to_status', 'agreement_version', 'actor_user_id', 'occurred_at'],
        'sponsors' => ['id', 'organization_id', 'name', 'industry', 'status', 'version', 'created_at', 'updated_at'],
        'sponsor_contacts' => ['id', 'organization_id', 'sponsor_id', 'name', 'position', 'email', 'phone', 'status', 'version', 'created_at', 'updated_at'],
        'sponsorship_opportunities' => ['id', 'organization_id', 'sponsor_id', 'title', 'description', 'status', 'version', 'created_at', 'updated_at'],
        'sponsorship_interactions' => ['id', 'organization_id', 'sponsor_id', 'contact_id', 'opportunity_id', 'channel', 'summary', 'occurred_at', 'created_by', 'created_at'],
        'organization_sensitive_access_events' => ['id', 'organization_id', 'actor_user_id', 'action', 'target_type', 'target_id', 'reason_code', 'request_id', 'ip_address', 'occurred_at'],
        'contract_reminder_plans' => ['id', 'organization_id', 'contract_id', 'enabled', 'notice_on', 'renewal_on', 'offsets', 'channels', 'version', 'updated_by', 'checked_at', 'created_at', 'updated_at'],
        'contract_reminder_deliveries' => ['id', 'organization_id', 'contract_id', 'plan_id', 'person_id', 'recipient_id', 'occurrence', 'kind', 'due_on', 'offset_days', 'in_app_status', 'email_status', 'reason', 'attempts', 'version', 'run_id', 'email_started_at', 'email_sent_at', 'read_at', 'created_at', 'updated_at'],
        'contract_financial_terms' => ['id', 'organization_id', 'contract_id', 'document_id', 'scope_id', 'terms', 'version', 'prepared_by', 'created_at', 'updated_at'],
        'contract_amendment_approvals' => ['id', 'organization_id', 'contract_id', 'document_id', 'document_version_id', 'financial_version', 'number', 'approved_by', 'created_at'],
        'contract_approved_copies' => ['id', 'organization_id', 'contract_id', 'document_id', 'document_version_id', 'approval_id'],
        'contracts' => ['id', 'organization_id', 'reference', 'type', 'title', 'responsible_person_id', 'responsible_name', 'starts_on', 'ends_on', 'status', 'version', 'created_by', 'prepared_by', 'approved_by', 'approved_at', 'signed_on', 'signature_reference', 'signature_recorded_at', 'signature_recorded_by', 'signature_separation_required', 'terminated_on', 'created_at', 'updated_at'],
        'contract_documents' => ['id', 'organization_id', 'contract_id', 'reference', 'kind', 'title', 'original_document_id', 'effective_on', 'created_by', 'created_at'],
        'contract_document_versions' => ['id', 'organization_id', 'contract_id', 'document_id', 'artifact_id', 'number', 'purpose', 'filename', 'sha256', 'bytes', 'created_by', 'created_at'],
        'contract_document_signatures' => ['id', 'organization_id', 'contract_id', 'document_id', 'document_version_id', 'version_number', 'sha256', 'signed_on', 'recorded_by', 'created_at'],
        'contract_document_scans' => ['id', 'organization_id', 'contract_id', 'document_id', 'document_version_id', 'attempt', 'sha256', 'policy', 'status', 'reason', 'engine', 'requested_by', 'requested_at', 'started_at', 'finished_at', 'invalidated_at'],
        'contract_parties' => ['id', 'organization_id', 'contract_id', 'kind', 'person_id', 'name', 'position', 'created_at', 'updated_at'],
        'contract_obligations' => ['id', 'organization_id', 'contract_id', 'title', 'due_on', 'position', 'status', 'version', 'created_at', 'updated_at'],
        'contract_transitions' => ['id', 'organization_id', 'contract_id', 'from_status', 'to_status', 'contract_version', 'actor_user_id', 'occurred_at'],
        'external_opponents' => ['id', 'organization_id', 'key', 'kind', 'name', 'short_name', 'organization_name', 'game_id', 'source_label', 'source_url', 'observed_at', 'status', 'version', 'created_at', 'updated_at'],
        'external_opponent_snapshots' => ['id', 'organization_id', 'opponent_id', 'opponent_version', 'context_label', 'kind', 'name', 'short_name', 'organization_name', 'game_key', 'game_name', 'source_label', 'source_url', 'observed_at', 'captured_at', 'created_by'],
        'team_eligibility_rulesets' => ['id', 'organization_id', 'game_id', 'name', 'version', 'status', 'min_starters', 'max_starters', 'max_substitutes', 'platform_key', 'allowed_regions', 'allowed_ranks', 'min_age', 'max_age', 'observation_max_days', 'lock_at', 'created_by', 'created_at', 'updated_at'],
        'team_lineup_rule_bindings' => ['id', 'organization_id', 'lineup_id', 'ruleset_id', 'ruleset_version', 'actor_user_id', 'created_at'],
        'team_lineup_eligibility_reports' => ['id', 'organization_id', 'lineup_id', 'binding_id', 'ruleset_id', 'ruleset_version', 'lineup_version', 'stage', 'status', 'checks', 'evaluated_at', 'actor_user_id', 'created_at'],
        'team_lineups' => ['id', 'organization_id', 'team_id', 'game_id', 'purpose', 'label', 'effective_at', 'status', 'version', 'team_name', 'game_key', 'game_name', 'season', 'created_by', 'finalized_by', 'finalized_at', 'created_at', 'updated_at'],
        'team_lineup_entries' => ['id', 'organization_id', 'lineup_id', 'membership_id', 'membership_version', 'person_id', 'pseudonym', 'membership_role_key', 'slot_key', 'position', 'created_at', 'updated_at'],
        'team_memberships' => ['id', 'organization_id', 'team_id', 'person_id', 'role_key', 'starts_at', 'ends_at', 'status', 'version', 'created_at', 'updated_at'],
        'team_scope_grants' => ['id', 'organization_id', 'role_assignment_id', 'team_membership_id', 'created_at'],
        'team_movements' => ['id', 'organization_id', 'team_id', 'membership_id', 'destination_team_id', 'destination_membership_id', 'action', 'reason_key', 'actor_user_id', 'effective_at', 'request_id', 'created_at'],
        'teams' => ['id', 'organization_id', 'game_id', 'slug', 'season', 'name', 'kind', 'custom_category', 'short_name', 'public_description', 'color', 'status', 'version', 'created_at', 'updated_at'],
        'organization_games' => ['id', 'organization_id', 'game_id', 'enabled', 'restrictions', 'version', 'created_at', 'updated_at'],
        'person_imports' => ['id', 'organization_id', 'actor_user_id', 'execution_actor_id', 'artifact_id', 'fingerprint', 'delimiter', 'headers', 'mapping', 'defaults', 'row_count', 'status', 'version', 'confirmed_version', 'failure_code', 'created_at', 'updated_at'],
        'person_import_rows' => ['id', 'organization_id', 'import_id', 'line', 'status', 'values', 'errors', 'person_id', 'created_at', 'updated_at'],
        'person_periods' => ['id', 'organization_id', 'person_id', 'kind', 'role_key', 'starts_at', 'ends_at', 'status', 'version', 'created_at', 'updated_at'],
        'person_departure_items' => ['id', 'organization_id', 'person_id', 'kind', 'title', 'notes', 'status', 'due_at', 'completed_at', 'version', 'created_at', 'updated_at'],
        'person_departures' => ['id', 'organization_id', 'person_id', 'user_id', 'actor_user_id', 'reason_key', 'departed_at', 'previous_version', 'person_version', 'pending_items_count', 'created_at', 'updated_at'],
        'organizations' => ['id', 'name', 'slug', 'owner_user_id', 'status', 'timezone', 'language', 'country', 'created_at', 'updated_at'],
        'organization_settings' => ['organization_id', 'week_starts_on', 'date_format', 'created_at', 'updated_at'],
        'organization_lifecycle_events' => ['id', 'organization_id', 'actor_user_id', 'action', 'from_status', 'to_status', 'reason', 'request_id', 'occurred_at'],
        'organization_memberships' => ['id', 'organization_id', 'user_id', 'status', 'roles', 'starts_at', 'ends_at', 'created_at', 'updated_at'],
        'organization_invitations' => ['id', 'organization_id', 'email', 'roles', 'status', 'invited_by', 'responded_by', 'starts_at', 'ends_at', 'expires_at', 'responded_at', 'created_at', 'updated_at'],
        'organization_access_events' => ['id', 'organization_id', 'actor_user_id', 'action', 'subject_id', 'before', 'after', 'request_id', 'occurred_at'],
        'organization_roles' => ['id', 'organization_id', 'key', 'name', 'permissions', 'active', 'privileged', 'version', 'created_at', 'updated_at'],
        'organization_role_assignments' => ['id', 'organization_id', 'role_id', 'user_id', 'membership_id', 'scope_type', 'scope_id', 'status', 'starts_at', 'ends_at', 'version', 'created_at', 'updated_at'],
        'organization_support_accesses' => ['id', 'organization_id', 'actor_user_id', 'platform_role_version', 'reason', 'actions', 'status', 'starts_at', 'expires_at', 'created_at', 'updated_at'],
        'organization_support_events' => ['id', 'organization_id', 'support_access_id', 'actor_user_id', 'action', 'request_id', 'ip_address', 'occurred_at'],
        'organization_audit_events' => ['id', 'organization_id', 'actor_user_id', 'action', 'target_type', 'target_id', 'reason_code', 'before', 'after', 'request_id', 'ip_address', 'occurred_at'],
        'people' => ['id', 'organization_id', 'user_id', 'pseudonym', 'language', 'timezone', 'status', 'biography_public', 'biography_internal', 'first_name', 'last_name', 'contact_email', 'contact_phone', 'birth_date', 'version', 'created_at', 'updated_at'],
        'person_game_profiles' => ['id', 'organization_id', 'person_id', 'game_key', 'platform_key', 'region_key', 'rank', 'game_role', 'observed_at', 'status', 'version', 'created_at', 'updated_at'],
        'person_external_accounts' => ['id', 'organization_id', 'game_profile_id', 'game_key', 'platform_key', 'region_key', 'provider_key', 'external_id', 'display_name', 'observed_at', 'status', 'version', 'created_at', 'updated_at'],
        'tenant_storage_usage' => ['organization_id', 'bytes'],
        'tenant_artifacts' => ['id', 'organization_id', 'name', 'disk', 'bytes', 'ready', 'created_at', 'updated_at'],
        'tenant_portability_operations' => ['id', 'organization_id', 'actor_user_id', 'request_id', 'kind', 'status', 'reason', 'disk', 'bytes', 'sha256', 'expires_at', 'details', 'created_at', 'updated_at'],
    ];

    public function __construct(private DatabaseManager $database) {}

    public function assertCatalog(): void
    {
        $schema = $this->database->connection()->getSchemaBuilder();
        foreach ($schema->getTableListing($schema->getCurrentSchemaName(), schemaQualified: false) as $table) {
            if (! TableOwnership::isGlobal($table) && ! array_key_exists($table, self::TABLES)) {
                throw new DomainConflictException(__('tenancy.portability.catalog'), 'PORTABILITY_CATALOG_INCOMPLETE');
            }
        }
        foreach (array_keys(self::TABLES) as $table) {
            if (! $schema->hasTable($table)) {
                throw new DomainConflictException(__('tenancy.portability.catalog'), 'PORTABILITY_CATALOG_INCOMPLETE');
            }
            $actual = $schema->getColumnListing($table);
            $expected = self::COLUMNS[$table];
            // Generated FK projections are derived from subject_id and are not restore inputs.
            $generated = $table === 'organization_access_events' ? ['membership_id', 'invitation_id', 'role_id', 'role_assignment_id'] : [];
            if (array_diff($actual, [...$expected, ...$generated]) !== [] || array_diff($expected, $actual) !== []) {
                throw new DomainConflictException(__('tenancy.portability.catalog'), 'PORTABILITY_CATALOG_INCOMPLETE');
            }
        }
    }

    public function rows(string $organizationId, int $limit): iterable
    {
        $this->assertCatalog();
        if ($limit < 1) {
            throw new InvalidArgumentException('A complete export requires a positive row limit.');
        }
        $count = 0;
        foreach (self::TABLES as $table => $key) {
            foreach ($this->tenantQuery($table, $organizationId)->select(self::COLUMNS[$table])->lazyById(200, $key) as $row) {
                if (++$count > $limit) {
                    throw new TenantLimitExceededException;
                }
                yield ['table' => $table, 'data' => (array) $row];
            }
        }
    }

    public function counts(string $organizationId): array
    {
        $this->assertCatalog();
        $counts = [];
        foreach (array_keys(self::TABLES) as $table) {
            $counts[$table] = $this->tenantQuery($table, $organizationId)->count();
        }

        return $counts;
    }

    public function create(string $organizationId, array $attributes): PortabilityOperation
    {
        $id = (string) Str::uuid7();
        $this->database->connection()->table('tenant_portability_operations')->insert([
            ...$attributes, 'id' => $id, 'organization_id' => $organizationId,
            'details' => json_encode($attributes['details'] ?? [], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->find($organizationId, $id);
    }

    public function find(string $organizationId, string $operationId): PortabilityOperation
    {
        if (! Str::isUuid($operationId)) {
            throw new ModelNotFoundException;
        }
        $row = $this->tenantQuery('tenant_portability_operations', $organizationId)->where('id', strtolower($operationId))->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        return $this->operation($row);
    }

    public function update(string $organizationId, string $operationId, array $attributes): void
    {
        if (isset($attributes['details'])) {
            $attributes['details'] = json_encode($attributes['details'], JSON_THROW_ON_ERROR);
        }
        $this->tenantQuery('tenant_portability_operations', $organizationId)->where('id', $operationId)
            ->update([...$attributes, 'updated_at' => now()]);
    }

    public function reservedExportBytes(string $organizationId): int
    {
        return (int) $this->tenantQuery('tenant_portability_operations', $organizationId)->where('kind', 'export')
            ->whereIn('status', ['pending', 'ready', 'failed'])->sum('bytes');
    }

    public function exports(string $organizationId, int $limit): array
    {
        $this->assertLimit($limit);

        return array_values($this->tenantQuery('tenant_portability_operations', $organizationId)->where('kind', 'export')
            ->whereIn('status', ['pending', 'ready', 'failed'])->orderBy('id')->limit($limit)->get()
            ->map(fn (stdClass $row): PortabilityOperation => $this->operation($row))->all());
    }

    public function expiredExports(int $limit): array
    {
        $this->assertLimit($limit);

        return array_values($this->database->connection()->table('tenant_portability_operations')->where('kind', 'export')
            ->whereIn('status', ['pending', 'ready', 'failed'])->where('expires_at', '<=', now())->orderBy('updated_at')->orderBy('id')
            ->limit($limit)->get()->map(fn (stdClass $row): PortabilityOperation => $this->operation($row))->all());
    }

    public function artifacts(string $organizationId, int $limit): array
    {
        $this->assertLimit($limit);

        return array_values($this->tenantQuery('tenant_artifacts', $organizationId)->orderBy('id')->limit($limit)->get(['id', 'disk', 'bytes'])
            ->map(fn (stdClass $row): array => ['id' => (string) $row->id, 'disk' => (string) $row->disk, 'bytes' => (int) $row->bytes])->all());
    }

    public function removeSettings(string $organizationId): void
    {
        $this->tenantQuery('organization_settings', $organizationId)->delete();
    }

    public function hasPurge(string $organizationId): bool
    {
        return $this->tenantQuery('tenant_portability_operations', $organizationId)->where('kind', 'purge')->exists();
    }

    private function tenantQuery(string $table, string $organizationId): Builder
    {
        if (! Str::isUuid($organizationId) || ! array_key_exists($table, self::TABLES)) {
            throw new InvalidArgumentException('Portability requires an explicit tenant and a registered table.');
        }

        return $this->database->connection()->table($table)->where($table === 'organizations' ? 'id' : 'organization_id', strtolower($organizationId));
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Maintenance pages must contain between one and 200 records.');
        }
    }

    private function operation(stdClass $row): PortabilityOperation
    {
        $details = json_decode((string) $row->details, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($details)) {
            throw new InvalidArgumentException('Invalid operation details.');
        }

        return new PortabilityOperation((string) $row->id, (string) $row->organization_id, (int) $row->actor_user_id,
            (string) $row->request_id, (string) $row->kind, (string) $row->status, (string) $row->reason,
            $row->disk === null ? null : (string) $row->disk, $row->expires_at === null ? null : (string) $row->expires_at,
            (int) $row->bytes, $row->sha256 === null ? null : (string) $row->sha256, $details);
    }
}
