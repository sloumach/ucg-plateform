<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Data\ManualErasureReport;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Domain\Contracts\ErasureJournal;
use App\Modules\Tenancy\Domain\Contracts\ManualErasureRepository;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use App\Modules\Tenancy\Domain\RetentionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @phpstan-import-type ErasureOperation from ManualErasureRepository
 * @phpstan-import-type ErasureIntent from ErasureJournal
 */
final readonly class ManualErasureService
{
    public function __construct(private ManualErasureRepository $erasures, private ErasureJournal $journal,
        private RetentionControlRepository $controls, private RetentionGuard $guard,
        private OrganizationPortabilityService $portability, private ConnectionInterface $database,
        private Repository $config, private Factory $validator, private ErasureAccessGate $accessGate) {}

    public function plan(string $org, OrganizationAuditData $audit, string $scope, string $target): ManualErasureReport
    {
        $this->validateTarget($org, $scope, $target);

        return $this->database->transaction(function () use ($org, $audit, $scope, $target): ManualErasureReport {
            $this->authorize($org, $audit);
            $plan = $this->erasures->plan($org, $scope, $target);

            return new ManualErasureReport(['plan' => $plan, 'production_qualified' => false,
                'required_copy_reviews' => RetentionCatalog::UNVERIFIED_COPIES,
                'shared_history_policy' => 'block_individual_erasure', 'execution_enabled' => $this->config->get('retention.erasure.enabled') === true]);
        });
    }

    public function initializeJournal(string $org, OrganizationAuditData $audit): ManualErasureReport
    {
        $this->database->transaction(fn () => $this->authorize($org, $audit));
        $this->enabled();
        $this->journal->initialize();
        $events = $this->database->table('organization_retention_events')->where('organization_id', $org)->where('action', 'hold')->orderBy('version')->limit(1001)->get(['request_id', 'reference']);
        if ($events->count() > 1000) {
            throw new DomainConflictException(__('retention.erasure_limit'), 'ERASURE_LIMIT');
        }
        foreach ($events as $event) {
            $this->journal->preserve($org, (string) $event->request_id, (string) $event->reference);
        }

        return new ManualErasureReport(['journal_ready' => true, 'production_qualified' => false]);
    }

    public function begin(string $org, OrganizationAuditData $audit, string $scope, string $target,
        string $digest, string $reference, string $confirmation): ManualErasureReport
    {
        $this->validateTarget($org, $scope, $target);
        $this->validateApproval($target, $digest, $reference, $confirmation);
        $this->database->transaction(fn () => $this->authorize($org, $audit));
        $this->enabled();
        $this->journal->assertReady();
        $operation = $this->database->transaction(function () use ($org, $audit, $scope, $target, $digest, $reference): array {
            $status = $this->authorize($org, $audit);
            $state = $this->controls->state($org);
            if (($scope === 'organization' && $status !== 'archived') || ($scope === 'person' && $status === 'active')
                || $state['holds'] !== [] || $state['quarantine'] !== null || $state['restricted_archive']) {
                throw new DomainConflictException(__('retention.erasure_blocked'), 'ERASURE_BLOCKED');
            }
            $plan = $this->erasures->plan($org, $scope, $target);
            if ($plan['blockers'] !== [] || ! hash_equals($plan['source_digest'], $digest)) {
                throw new DomainConflictException(__('retention.erasure_plan_changed'), 'ERASURE_PLAN_CHANGED');
            }
            $this->healthy();
            $operation = $this->erasures->create($plan, $audit->actorUserId, $audit->requestId, $reference);
            $this->quarantine($org, $audit, 'erasure:'.$operation['id']);

            return $operation;
        });
        // A committed source decision remains quarantined if the independent journal is unavailable.
        $this->journal->append($operation);

        return $this->report($operation);
    }

    public function inspect(string $org, OrganizationAuditData $audit, string $operationId): ManualErasureReport
    {
        return $this->database->transaction(function () use ($org, $audit, $operationId): ManualErasureReport {
            $this->authorize($org, $audit);

            return $this->report($this->erasures->find($org, $operationId));
        });
    }

    public function resume(string $org, OrganizationAuditData $audit, string $operationId): ManualErasureReport
    {
        $this->enabled();
        $operation = $this->database->transaction(function () use ($org, $audit, $operationId): array {
            $this->authorize($org, $audit);
            $operation = $this->erasures->find($org, $operationId);
            $this->assertQuarantine($org, $operationId);
            $this->healthy();
            $this->erasures->assertExecutable($operation);

            return $operation;
        });
        if ($operation['source_intent_id'] === null) {
            $this->journal->append($operation);
        } else {
            $this->assertReplayIntent($operation);
        }

        return $this->database->transaction(function () use ($org, $audit, $operationId): ManualErasureReport {
            $this->authorize($org, $audit);
            $this->assertQuarantine($org, $operationId);
            $operation = $this->erasures->find($org, $operationId);
            $this->erasures->assertExecutable($operation);
            $this->healthy();

            return $this->report($this->erasures->batch($operation, $audit->actorUserId, $audit->requestId));
        });
    }

    public function acknowledge(string $org, OrganizationAuditData $audit, string $operationId, string $category, string $reference): ManualErasureReport
    {
        $this->validator->make(compact('category', 'reference'), [
            'category' => ['required', Rule::in(RetentionCatalog::UNVERIFIED_COPIES)],
            'reference' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
        ])->validate();
        $this->enabled();

        return $this->database->transaction(function () use ($org, $audit, $operationId, $category, $reference): ManualErasureReport {
            $this->authorize($org, $audit);
            $this->assertQuarantine($org, $operationId);
            $this->healthy();
            $operation = $this->erasures->find($org, $operationId);
            $this->erasures->assertEmpty($operation);
            $operation = $this->erasures->acknowledge($operation, $category, $reference, $audit->actorUserId, $audit->requestId);
            if ($operation['status'] === 'completed' && $operation['scope'] === 'person') {
                $next = $operation['restore_epoch'] === null ? null : 'restore:'.$operation['restore_epoch'];
                $this->quarantine($org, $audit, $next, $next === null ? ['journal_head' => $this->verifiedHead($org)] : null);
            }

            return $this->report($operation);
        });
    }

    /** @param ErasureIntent $intent */
    public function replayIntent(string $org, OrganizationAuditData $audit, array $intent, string $epoch, string $expectedHead): ManualErasureReport
    {
        $this->enabled();
        $this->database->transaction(fn () => $this->authorize($org, $audit));
        $trusted = array_filter($this->journal->intents($org, 0, 1000), static fn (array $record): bool => $record === $intent);
        if ($trusted === []) {
            throw new DomainConflictException(__('retention.journal_conflict'), 'ERASURE_JOURNAL_CONFLICT');
        }
        if ($this->config->get('retention.erasure.restore_mode') !== true || $this->config->get('retention.erasure.restore_epoch') !== $epoch
            || ! Str::isUuid($epoch) || ! hash_equals($this->journal->head($org), $expectedHead)) {
            throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
        }

        return $this->database->transaction(function () use ($org, $audit, $intent, $epoch): ManualErasureReport {
            $this->authorize($org, $audit);
            $existing = $this->erasures->replay($org, $intent['id'], $epoch);
            if ($existing !== null) {
                if ($existing['status'] === 'completed') {
                    $this->erasures->assertEmpty($existing);
                }

                return $this->report($existing);
            }
            $state = $this->controls->state($org);
            if ($state['holds'] !== [] || ($state['quarantine'] !== null && $state['quarantine'] !== 'erasure:'.$intent['id'] && ! str_starts_with($state['quarantine'], 'restore:'))) {
                throw new DomainConflictException(__('retention.erasure_blocked'), 'ERASURE_BLOCKED');
            }
            $this->healthy();
            $plan = $this->erasures->plan($org, $intent['scope'], $intent['target_id']);
            $blockers = array_diff($plan['blockers'], ['person_not_soft_deleted', 'departure_or_obligations_unresolved']);
            if ($blockers !== [] || ! hash_equals($intent['schema_digest'], $plan['schema_digest'])) {
                throw new DomainConflictException(__('retention.erasure_plan_changed'), 'ERASURE_PLAN_CHANGED');
            }
            $files = [];
            foreach ([...$intent['manifest']['files'], ...$plan['manifest']['files']] as $file) {
                $files[$file['kind'].':'.$file['disk'].':'.$file['id']] = $file;
            }
            $plan['manifest']['files'] = array_values($files);
            $plan['manifest']['imports'] = array_values(array_unique([...$intent['manifest']['imports'], ...$plan['manifest']['imports']]));
            $operation = $this->erasures->create($plan, $audit->actorUserId, $audit->requestId, $intent['reference'], $intent['id'], $epoch);
            $this->quarantine($org, $audit, 'erasure:'.$operation['id']);

            return $this->report($operation);
        });
    }

    public function importPreservations(string $org, OrganizationAuditData $audit): int
    {
        $this->enabled();
        if ($this->config->get('retention.erasure.restore_mode') !== true || ! Str::isUuid($this->config->get('retention.erasure.restore_epoch', ''))) {
            throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
        }

        return $this->database->transaction(function () use ($org, $audit): int {
            $this->authorize($org, $audit);
            $missing = $this->accessGate->missingPreservations($org);
            foreach ($missing as $record) {
                $state = $this->controls->state($org);
                $state['holds'] = array_values(array_unique([...$state['holds'], $record['reference']]));
                if (count($state['holds']) > 1000) {
                    throw new DomainConflictException(__('retention.erasure_limit'), 'ERASURE_LIMIT');
                }
                $state['version']++;
                $this->controls->append($org, $audit->actorUserId, (string) Str::uuid7(), $audit->reason, 'hold', $record['reference'], $state,
                    ['source_hold_request_id' => $record['request_id']]);
            }

            return count($missing);
        });
    }

    public function finishReconciliation(string $org, OrganizationAuditData $audit, string $epoch, string $expectedHead): ManualErasureReport
    {
        $this->enabled();

        return $this->database->transaction(function () use ($org, $audit, $epoch, $expectedHead): ManualErasureReport {
            $this->authorize($org, $audit);
            if ($this->config->get('retention.erasure.restore_mode') !== true || $this->config->get('retention.erasure.restore_epoch') !== $epoch
                || ! hash_equals($this->verifiedHead($org, $epoch), $expectedHead)) {
                throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
            }
            $state = $this->controls->state($org);
            if ($state['holds'] !== [] || ($state['quarantine'] !== null && ! str_starts_with($state['quarantine'], 'restore:'))) {
                throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
            }
            $this->quarantine($org, $audit, null, ['journal_head' => $expectedHead, 'restore_epoch' => $epoch]);

            return new ManualErasureReport(['reconciled' => true, 'journal_head' => $expectedHead, 'production_qualified' => false]);
        });
    }

    private function authorize(string $org, OrganizationAuditData $audit): string
    {
        $this->guard->authorizeOperator($org, $audit->actorUserId);
        $targets = $this->config->get('retention.erasure.operators.'.$audit->actorUserId);
        if (! is_array($targets) || ! in_array($org, $targets, true)) {
            throw new AuthorizationException(__('retention.control_forbidden'));
        }

        return $this->portability->authorize($org, $audit)->status->value;
    }

    private function enabled(): void
    {
        if ($this->config->get('retention.erasure.enabled') !== true) {
            throw new DomainConflictException(__('retention.erasure_disabled'), 'ERASURE_DISABLED');
        }
    }

    private function validateTarget(string $org, string $scope, string $target): void
    {
        $this->validator->make(compact('org', 'scope', 'target'), [
            'org' => ['required', 'uuid'], 'scope' => ['required', Rule::in(['person', 'organization'])],
            'target' => ['required', 'uuid', ...($scope === 'organization' ? ['same:org'] : [])],
        ])->validate();
    }

    private function validateApproval(string $target, string $digest, string $reference, string $confirmation): void
    {
        $this->validator->make(compact('target', 'digest', 'reference', 'confirmation'), [
            'digest' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'reference' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
            'confirmation' => ['required', 'uuid', 'same:target'],
        ])->validate();
    }

    private function assertQuarantine(string $org, string $id): void
    {
        $state = $this->controls->state($org);
        if ($state['holds'] !== [] || $state['quarantine'] !== 'erasure:'.$id) {
            throw new DomainConflictException(__('retention.erasure_blocked'), 'ERASURE_BLOCKED');
        }
    }

    private function healthy(): void
    {
        if (in_array(false, $this->erasures->guardHealth(), true)) {
            throw new DomainConflictException(__('retention.erasure_guards_changed'), 'ERASURE_GUARDS_CHANGED');
        }
    }

    /** @param array<string, mixed>|null $evidence */
    private function quarantine(string $org, OrganizationAuditData $audit, ?string $next, ?array $evidence = null): void
    {
        $state = $this->controls->state($org);
        $previous = $state['quarantine'];
        $state['version']++;
        $state['quarantine'] = $next;
        $this->controls->append($org, $audit->actorUserId, (string) Str::uuid7(), $audit->reason,
            $next === null ? 'release-quarantine' : 'quarantine', $next ?? $previous ?? 'erasure-review:'.$audit->requestId, $state, $evidence);
    }

    private function verifiedHead(string $org, ?string $epoch = null): string
    {
        $head = $this->journal->head($org);
        foreach ($this->journal->intents($org, 0, 1000) as $intent) {
            $operation = $epoch === null ? $this->erasures->completedIntent($org, $intent['id']) : $this->erasures->replay($org, $intent['id'], $epoch);
            if ($operation === null || $operation['status'] !== 'completed') {
                throw new DomainConflictException(__('retention.copies_pending'), 'ERASURE_COPIES_PENDING');
            }
            $this->erasures->assertEmpty($operation);
        }
        if (! hash_equals($head, $this->journal->head($org))) {
            throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
        }

        return $head;
    }

    /** @param ErasureOperation $operation */
    private function assertReplayIntent(array $operation): void
    {
        if ($operation['restore_epoch'] !== $this->config->get('retention.erasure.restore_epoch')
            || $this->config->get('retention.erasure.restore_mode') !== true) {
            throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
        }
        foreach ($this->journal->intents($operation['organization_id'], 0, 1000) as $intent) {
            if ($intent['id'] === $operation['source_intent_id'] && $intent['scope'] === $operation['scope'] && $intent['target_id'] === $operation['target_id']) {
                return;
            }
        }
        throw new DomainConflictException(__('retention.journal_conflict'), 'ERASURE_JOURNAL_CONFLICT');
    }

    /** @param ErasureOperation $operation */
    private function report(array $operation): ManualErasureReport
    {
        return new ManualErasureReport(['operation' => $operation,
            'remaining_copy_reviews' => array_values(array_diff(RetentionCatalog::UNVERIFIED_COPIES, array_keys($operation['copy_receipts']))),
            'copy_evidence' => 'operator_attestations', 'production_qualified' => false]);
    }
}
