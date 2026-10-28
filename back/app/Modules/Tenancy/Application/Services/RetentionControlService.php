<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\RetentionControlReport;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use App\Modules\Tenancy\Domain\Contracts\RetentionReviewRepository;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use App\Modules\Tenancy\Domain\RetentionReviewPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\Rule;
use Psr\Log\LoggerInterface;

/** Explicit server operations, organization-wide. No person erasure or provider attestation is inferred. */
final readonly class RetentionControlService
{
    public const array ACTIONS = ['inspect', 'hold', 'release-hold', 'restrict-archive', 'release-archive', 'quarantine', 'release-quarantine'];

    public function __construct(private RetentionControlRepository $controls, private RetentionGuard $guard,
        private OrganizationPortabilityService $portability, private RetentionReviewRepository $inventory,
        private ConnectionInterface $database, private Repository $config, private Factory $validator, private LoggerInterface $logger) {}

    public function run(string $organizationId, OrganizationAuditData $audit, string $action = 'inspect',
        ?int $expectedVersion = null, ?string $reference = null, ?string $confirmation = null): RetentionControlReport
    {
        $this->validator->make(['action' => $action, 'version' => $expectedVersion, 'reference' => $reference], [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'version' => [$action === 'inspect' ? 'nullable' : 'required', 'integer', 'min:0', 'max:2147483646'],
            'reference' => [$action === 'inspect' ? 'nullable' : 'required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
        ])->validate();

        return $this->database->transaction(function () use ($organizationId, $audit, $action, $expectedVersion, $reference, $confirmation): RetentionControlReport {
            $this->guard->authorizeOperator($organizationId, $audit->actorUserId);
            $organization = $this->portability->authorize($organizationId, $audit);
            $this->inventory->assertCatalog();
            $state = $this->controls->state($organizationId);
            if ($action !== 'inspect') {
                if ($this->config->get('retention.control_enabled') !== true || $confirmation !== $organizationId) {
                    throw new DomainConflictException(__('retention.control_disabled'), 'RETENTION_CONTROL_DISABLED');
                }
                $previous = $this->controls->request($organizationId, $audit->requestId);
                if ($previous !== null) {
                    if ($previous['action'] !== $action || $previous['reference'] !== $reference
                        || (int) $previous['actor_user_id'] !== $audit->actorUserId || $previous['reason'] !== $audit->reason
                        || (int) $previous['version'] !== $expectedVersion + 1) {
                        throw new DomainConflictException(__('retention.request_conflict'), 'RETENTION_REQUEST_CONFLICT');
                    }

                    // A replay reports current state and never reapplies an old release to a newer hold.
                    return $this->report($organizationId, $audit, $state, true);
                }
                if ($state['version'] !== $expectedVersion) {
                    throw new DomainConflictException(__('retention.version_conflict'), 'RETENTION_VERSION_CONFLICT');
                }
                $reference = (string) $reference;
                $evidence = null;
                switch ($action) {
                    case 'hold':
                        if (in_array($reference, $state['holds'], true) || count($state['holds']) >= 1000) {
                            throw new DomainConflictException(__('retention.invalid_control'), 'RETENTION_HOLD_CONFLICT');
                        }
                        $state['holds'][] = $reference;
                        sort($state['holds']);
                        break;
                    case 'release-hold':
                        if (! in_array($reference, $state['holds'], true)) {
                            throw new DomainConflictException(__('retention.invalid_control'), 'RETENTION_HOLD_NOT_FOUND');
                        }
                        $state['holds'] = array_values(array_diff($state['holds'], [$reference]));
                        break;
                    case 'restrict-archive':
                        if ($organization->status !== OrganizationStatus::Archived || $state['restricted_archive']) {
                            throw new DomainConflictException(__('retention.archive_required'), 'RETENTION_ARCHIVED_REQUIRED');
                        }
                        $state['restricted_archive'] = true;
                        break;
                    case 'release-archive':
                        if (! $state['restricted_archive']) {
                            throw new DomainConflictException(__('retention.invalid_control'), 'RETENTION_ARCHIVE_NOT_RESTRICTED');
                        }
                        $state['restricted_archive'] = false;
                        break;
                    case 'quarantine':
                        if ($state['quarantine'] !== null || $organization->status === OrganizationStatus::Active) {
                            throw new DomainConflictException(__('retention.quarantine_requires_inactive'), 'RETENTION_QUARANTINE_REQUIRES_INACTIVE');
                        }
                        $state['quarantine'] = $reference;
                        break;
                    case 'release-quarantine':
                        if ($state['quarantine'] !== null && (str_starts_with($state['quarantine'], 'erasure:') || str_starts_with($state['quarantine'], 'restore:'))) {
                            throw new DomainConflictException(__('retention.erasure_blocked'), 'ERASURE_BLOCKED');
                        }
                        $evidence = $this->restorationApproval($organizationId, $state, $reference);
                        if ($state['quarantine'] === null || $evidence === null) {
                            throw new DomainConflictException(__('retention.restoration_unverified'), 'RETENTION_RESTORATION_UNVERIFIED');
                        }
                        $state['quarantine'] = null;
                        break;
                }
                // Recheck configuration/account after reading state and before recording the decision.
                $this->guard->authorizeOperator($organizationId, $audit->actorUserId);
                $state['version']++;
                $this->controls->append($organizationId, $audit->actorUserId, $audit->requestId, $audit->reason, $action, $reference, $state, $evidence);
            }

            return $this->report($organizationId, $audit, $state, false);
        });
    }

    /** @param array{version: int, holds: list<string>, restricted_archive: bool, quarantine: ?string} $state */
    private function report(string $organizationId, OrganizationAuditData $audit, array $state, bool $replayed): RetentionControlReport
    {
        $this->guard->authorizeOperator($organizationId, $audit->actorUserId);
        $policy = RetentionReviewPolicy::fromConfiguration($this->config->get('retention.review_policies.'.$organizationId), CarbonImmutable::now('UTC')->startOfDay());
        $missing = array_values(array_filter(array_keys(RetentionCatalog::FAMILIES), fn (string $family): bool => $policy->rule($family) === null));
        $this->logger->info('tenant.retention.control', ['tenant_id' => $organizationId, 'actor_user_id' => $audit->actorUserId,
            'request_id' => $audit->requestId, 'control_version' => $state['version'], 'replayed' => $replayed]);

        return new RetentionControlReport(['organization_id' => $organizationId, 'request_id' => $audit->requestId,
            'scope' => 'whole_organization', 'state' => $state, 'replayed' => $replayed,
            'person_disposition' => RetentionCatalog::PERSON_DISPOSITION,
            'policy_status' => $policy->status, 'policy_fingerprint' => $policy->fingerprint, 'missing_family_rules' => $missing,
            'blocks' => [
                'rules' => $missing === [] ? 'review_rules_configured_execution_unavailable' : 'family_rules_missing',
                'holds' => 'business_and_technical_writes_frozen_by_sql_and_service_guards',
                'archive' => 'organization_archive_only_record_archive_unavailable',
                'business_erasure' => 'manual_scoped_erasure_shared_person_history_blocked',
                'copies_restoration' => 'independent_signed_journal_replay_operator_copy_attestations',
                'qualification' => 'hosting_runtime_unqualified',
            ], 'unverified_copies' => RetentionCatalog::UNVERIFIED_COPIES,
            'manual_erasure_available' => true,
            'business_deletion_supported' => $this->config->get('retention.erasure.enabled') === true
                && in_array($organizationId, $this->config->get('retention.erasure.operators.'.$audit->actorUserId, []), true),
            'production_qualified' => false]);
    }

    /** A manually reviewed receipt is an operator declaration, never proof of provider deletion.
     * @param  array{version: int, holds: list<string>, restricted_archive: bool, quarantine: ?string}  $state
     * @return array<string, mixed>|null
     */
    private function restorationApproval(string $organizationId, array $state, string $reference): ?array
    {
        $approval = $this->config->get('retention.restoration_approvals.'.$organizationId);
        $policy = RetentionReviewPolicy::fromConfiguration($this->config->get('retention.review_policies.'.$organizationId), CarbonImmutable::now('UTC')->startOfDay());
        if (! is_array($approval) || count($approval) !== 7 || ($approval['reviewed'] ?? null) !== true
            || ($approval['reference'] ?? null) !== $reference || ($approval['quarantine'] ?? null) !== $state['quarantine']
            || ($approval['control_version'] ?? null) !== $state['version'] || $policy->fingerprint === null
            || ($approval['policy_fingerprint'] ?? null) !== $policy->fingerprint
            || ! $this->validReference($approval['hosting_reference'] ?? null) || ! is_array($approval['copies'] ?? null)
            || count($approval['copies']) !== count(RetentionCatalog::UNVERIFIED_COPIES)) {
            return null;
        }
        foreach (RetentionCatalog::FAMILIES as $family => $_tables) {
            if ($policy->rule($family) === null) {
                return null;
            }
        }
        foreach (RetentionCatalog::UNVERIFIED_COPIES as $copy) {
            if (! $this->validReference($approval['copies'][$copy] ?? null)) {
                return null;
            }
        }

        return $approval;
    }

    private function validReference(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $value) === 1;
    }
}
