<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\RetentionReviewQuery;
use App\Modules\Tenancy\Application\Data\RetentionReviewReport;
use App\Modules\Tenancy\Domain\Contracts\RetentionReviewRepository;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use App\Modules\Tenancy\Domain\RetentionReviewPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/** Server-operator preview; no deletion, archive mutation, queue or storage adapter. */
final readonly class RetentionReviewService
{
    public function __construct(private RetentionReviewRepository $records, private OrganizationPortabilityService $portability,
        private AccountDirectory $accounts, private PlatformAccountDirectory $platform, private Repository $config,
        private ConnectionInterface $database, private LoggerInterface $logger) {}

    public function review(string $organizationId, OrganizationAuditData $audit, RetentionReviewQuery $query): RetentionReviewReport
    {
        return $this->database->transaction(function () use ($organizationId, $audit, $query): RetentionReviewReport {
            $this->authorize($organizationId, $audit->actorUserId);
            $organization = $this->portability->authorize($organizationId, $audit);
            $this->records->assertCatalog();
            $policy = RetentionReviewPolicy::fromConfiguration($this->config->get('retention.review_policies.'.$organization->id), $query->asOf);
            if ($query->section === 'inventory') {
                $families = array_slice(RetentionCatalog::FAMILIES, ($query->page - 1) * $query->perPage, $query->perPage, true);
                $items = [];
                foreach ($families as $family => $tables) {
                    $items[] = ['family' => $family, 'counts' => $this->records->counts($organization->id, $tables),
                        'rule_configured' => $policy->rule($family) !== null, 'outcome' => 'review_required',
                        'reasons' => $this->policyReasons($policy, $family), 'deletion_authorized' => false];
                }
                $total = count(RetentionCatalog::FAMILIES);
            } else {
                $page = $this->records->people($organization->id, $query->perPage, $query->page);
                $dependencies = $this->records->dependencies($organization->id, array_column($page->items(), 'id'));
                $items = [];
                foreach ($page->items() as $person) {
                    $items[] = $this->person($person, $dependencies[(string) $person['id']] ?? [], $policy, $query);
                }
                $total = $page->total();
            }
            // A captured actor/configuration is never proof after the inventory has been read.
            $this->authorize($organization->id, $audit->actorUserId);
            $currentPolicy = RetentionReviewPolicy::fromConfiguration($this->config->get('retention.review_policies.'.$organization->id), $query->asOf);
            if ($currentPolicy->fingerprint !== $policy->fingerprint || $currentPolicy->status !== $policy->status) {
                throw new DomainConflictException(__('retention.policy_changed'), 'RETENTION_POLICY_CHANGED');
            }
            $report = new RetentionReviewReport($organization->id, $query, $policy->status, $policy->version, $items, $total,
                $audit->requestId, $policy->fingerprint);
            $this->logger->info('tenant.retention.review', ['tenant_id' => $organization->id, 'actor_user_id' => $audit->actorUserId,
                'request_id' => $audit->requestId, 'section' => $query->section, 'page' => $query->page,
                'items' => count($items), 'policy_version' => $policy->version, 'policy_fingerprint' => $policy->fingerprint]);

            return $report;
        });
    }

    private function authorize(string $organizationId, int $actorId): void
    {
        $targets = $this->config->get('retention.review_operators.'.$actorId);
        if (! is_array($targets) || ! in_array($organizationId, $targets, true)
            || ! $this->accounts->verifiedAccountExists($actorId) || ! $this->accounts->hasConfirmedMfa($actorId)
            || $this->platform->isTechnical($actorId)) {
            throw new AuthorizationException(__('retention.forbidden'));
        }
    }

    /** @return list<string> */
    private function policyReasons(RetentionReviewPolicy $policy, string $family): array
    {
        $reasons = ['business_execution_not_available', 'copies_unverified'];
        if ($policy->status !== 'configured_review_only') {
            $reasons[] = 'policy_'.$policy->status;
        } elseif ($policy->rule($family) === null) {
            $reasons[] = 'family_rule_missing';
        } else {
            $reasons[] = 'configured_policy_requires_human_review';
        }

        return $reasons;
    }

    /** @param array<string, mixed> $person
     * @param  array<string, int>  $dependencies
     * @return array<string, mixed>
     */
    private function person(array $person, array $dependencies, RetentionReviewPolicy $policy, RetentionReviewQuery $query): array
    {
        $reasons = [...$this->policyReasons($policy, 'people'), 'age_procedure_unverified', 'snapshot_references_unverified'];
        $departed = $person['departed_at'] === null ? null : CarbonImmutable::parse((string) $person['departed_at'], 'UTC')->utc()->startOfDay();
        if ($departed === null) {
            $reasons[] = in_array($person['status'], ['candidate', 'trial', 'former'], true) ? 'closure_event_missing' : 'current_use_unverified';
        }
        if ($departed !== null && $departed->greaterThan($query->asOf)) {
            $reasons[] = 'closure_after_review_date';
        }
        if ($person['account_linked']) {
            $reasons[] = 'global_account_protected';
        }
        if (($dependencies['open_departure_items'] ?? 0) > 0) {
            $reasons[] = 'open_obligations_require_scoped_review';
        }
        if (array_sum($dependencies) > 0) {
            $reasons[] = 'references_present';
        }
        $rule = $policy->rule('people');
        $active = $archive = null;
        if ($rule !== null && $rule['event'] === 'recorded_departure' && $departed !== null && $departed->lessThanOrEqualTo($query->asOf)) {
            $active = $departed->addDays($rule['active_days']);
            $archive = $rule['archive_days'] === null ? null : $active->addDays($rule['archive_days']);
        }

        return ['person_id' => $person['id'], 'status' => $person['status'], 'outcome' => 'review_required', 'reasons' => $reasons,
            'closure_on' => $departed?->toDateString(), 'active_review_on' => $active?->toDateString(),
            'archive_review_on' => $archive?->toDateString(),
            'review_date_reached' => $active !== null && $active->lessThanOrEqualTo($query->asOf),
            'dependencies' => $dependencies, 'dependency_inventory_complete' => false, 'deletion_authorized' => false];
    }
}
