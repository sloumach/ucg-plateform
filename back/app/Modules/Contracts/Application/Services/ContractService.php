<?php

namespace App\Modules\Contracts\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\ContractLifecycle;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\Models\ContractTransition;
use App\Modules\People\Application\Contracts\AdministrativePeopleDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ContractService
{
    public function __construct(private ContractRepository $contracts, private AdministrativePeopleDirectory $people,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit,
        private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractRecord>
     */
    public function paginate(TenantContext $context, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'administrative.read');
        $values = ContractInput::validate($this->validator, $filters, ContractInput::pageRules());
        if (isset($values['responsible_person_id'])) {
            $values['responsible_person_id'] = strtolower($values['responsible_person_id']);
        }

        return $this->contracts->paginate($context->organizationId, $values);
    }

    public function find(TenantContext $context, string $id): ContractRecord
    {
        $this->authorization->authorize($context, 'administrative.read');

        return $this->contracts->find($context->organizationId, $id);
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractTransition>
     */
    public function transitions(TenantContext $context, string $id, array $filters): LengthAwarePaginator
    {
        $this->find($context, $id);
        $values = ContractInput::validate($this->validator, $filters, ContractInput::pageRules(true));

        return $this->contracts->transitions($context->organizationId, $id, $values);
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ipAddress = null): ContractRecord
    {
        $values = ContractInput::validate($this->validator, $input, ContractInput::saveRules($id === null));
        $this->authorizeWrite($context);
        if ($id !== null && array_diff(array_keys($values), ['version']) === []) {
            throw ValidationException::withMessages(['contract' => __('managed_contracts.empty')]);
        }
        try {
            return $this->database->transaction(function () use ($context, $values, $id, $ipAddress): ContractRecord {
                $this->guard->lock($context);
                $this->authorizeWrite($context);
                $contract = $id === null ? null : $this->locked($context, $id, $values['version']);
                if ($contract === null && $this->contracts->count($context->organizationId) >= ContractInput::MAX_CONTRACTS) {
                    throw new DomainConflictException(__('managed_contracts.limit'), 'CONTRACT_LIMIT_REACHED');
                }
                $terms = array_intersect(array_keys($values), ['title', 'starts_on', 'ends_on', 'parties', 'obligations']);
                if ($contract !== null && ! ContractLifecycle::editable($contract->status) && $terms !== []) {
                    throw new DomainConflictException(__('managed_contracts.frozen'), 'CONTRACT_TERMS_FROZEN');
                }
                $start = array_key_exists('starts_on', $values) ? $values['starts_on'] : $contract?->starts_on?->format('Y-m-d');
                $end = array_key_exists('ends_on', $values) ? $values['ends_on'] : $contract?->ends_on?->format('Y-m-d');
                if ($end !== null && ($start === null || $end < $start)) {
                    throw ValidationException::withMessages(['ends_on' => __('managed_contracts.dates_order')]);
                }
                $ids = isset($values['responsible_person_id']) ? [strtolower($values['responsible_person_id'])] : [];
                foreach ($values['parties'] ?? [] as $party) {
                    if ($party['kind'] === 'external' && ($party['person_id'] ?? null) !== null) {
                        throw ValidationException::withMessages(['parties' => __('managed_contracts.party')]);
                    }
                    if ($party['kind'] === 'person') {
                        $ids[] = strtolower($party['person_id']);
                    }
                }
                $labels = $this->people->labels($context, array_values(array_unique($ids)));
                $attributes = $values;
                unset($attributes['version'], $attributes['parties'], $attributes['obligations']);
                if (isset($attributes['title'])) {
                    $attributes['title'] = trim($attributes['title']);
                }
                if (isset($attributes['responsible_person_id'])) {
                    $attributes['responsible_person_id'] = strtolower($attributes['responsible_person_id']);
                    $attributes['responsible_name'] = $labels[$attributes['responsible_person_id']];
                }
                if ($terms !== []) {
                    $attributes['prepared_by'] = $context->actorUserId;
                }
                if (isset($values['obligations']) && $contract !== null && $contract->obligations->contains(fn ($item): bool => $item->status !== 'pending')) {
                    throw new DomainConflictException(__('managed_contracts.obligations_retained'), 'CONTRACT_OBLIGATIONS_RETAINED');
                }
                $before = $contract === null ? [] : $this->state($contract);
                $saved = $this->contracts->save($contract, [
                    ...($contract === null ? ['organization_id' => $context->organizationId, 'status' => 'draft', 'starts_on' => null, 'ends_on' => null,
                        'created_by' => $context->actorUserId, 'approved_by' => null, 'approved_at' => null, 'signed_on' => null,
                        'signature_reference' => null, 'signature_recorded_at' => null, 'terminated_on' => null] : []),
                    ...$attributes, 'version' => (string) Str::uuid(),
                ]);
                if (isset($values['parties'])) {
                    $parties = array_map(fn (array $party): array => ['kind' => $party['kind'], 'name' => trim($party['name']),
                        'person_id' => isset($party['person_id']) ? strtolower($party['person_id']) : null], $values['parties']);
                    $this->contracts->replaceParties($saved, array_values($parties));
                }
                if (isset($values['obligations'])) {
                    $this->contracts->replaceObligations($saved, array_values(array_map(fn (array $item): array => ['title' => trim($item['title']), 'due_on' => $item['due_on'] ?? null], $values['obligations'])));
                }
                if ($contract === null) {
                    $this->recordTransition($context, $saved, null);
                }
                $this->journal($context, $before, $saved, $ipAddress);

                return $this->contracts->find($context->organizationId, $saved->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('managed_contracts.duplicate'), 'CONTRACT_REFERENCE_ALREADY_EXISTS');
        }
    }

    /** @param array<string, mixed> $input */
    public function transition(TenantContext $context, string $id, array $input, ?string $ipAddress = null): ContractRecord
    {
        $values = ContractInput::validate($this->validator, $input, ContractInput::transitionRules());
        $this->authorizeWrite($context, $values['status']);

        return $this->database->transaction(function () use ($context, $id, $values, $ipAddress): ContractRecord {
            $this->guard->lock($context);
            $this->authorizeWrite($context, $values['status']);
            $contract = $this->locked($context, $id, $values['version']);
            $target = $values['status'];
            ContractLifecycle::assertTransition($contract->status, $target, $contract->starts_on?->format('Y-m-d'),
                $contract->ends_on?->format('Y-m-d'), CarbonImmutable::now(), $contract->pending_obligations_count);
            if ($contract->transitions_count >= ContractInput::MAX_TRANSITIONS) {
                throw new DomainConflictException(__('managed_contracts.transition_limit'), 'CONTRACT_TRANSITION_LIMIT');
            }
            if ($target === 'approved' && $contract->prepared_by === $context->actorUserId) {
                throw new DomainConflictException(__('managed_contracts.self_approval'), 'CONTRACT_SELF_APPROVAL_FORBIDDEN');
            }
            $extras = array_diff(array_keys($values), ['version', 'status'], $target === 'signed' ? ['signed_on', 'signature_reference', 'confirm_signature_received'] : ($target === 'terminated' ? ['terminated_on'] : []));
            if ($extras !== []) {
                throw ValidationException::withMessages(array_fill_keys($extras, __('managed_contracts.unknown')));
            }
            $attributes = ['status' => $target, 'version' => (string) Str::uuid()];
            if ($target === 'approved') {
                $attributes = [...$attributes, 'approved_by' => $context->actorUserId, 'approved_at' => CarbonImmutable::now()];
            }
            if (in_array($target, ['draft', 'in_review'], true)) {
                $attributes = [...$attributes, 'approved_by' => null, 'approved_at' => null];
            }
            if ($target === 'signed') {
                if (! isset($values['signed_on'], $values['signature_reference']) || ! ($values['confirm_signature_received'] ?? false)) {
                    throw ValidationException::withMessages(['signed_on' => __('managed_contracts.signature')]);
                }
                $attributes = [...$attributes, 'signed_on' => $values['signed_on'], 'signature_reference' => trim($values['signature_reference']),
                    'signature_recorded_at' => CarbonImmutable::now()];
            }
            if ($target === 'terminated') {
                if (! isset($values['terminated_on']) || $contract->signed_on === null || $values['terminated_on'] < $contract->signed_on->format('Y-m-d')) {
                    throw ValidationException::withMessages(['terminated_on' => __('managed_contracts.termination')]);
                }
                $attributes['terminated_on'] = $values['terminated_on'];
            }
            $from = $contract->status;
            $before = $this->state($contract);
            $saved = $this->contracts->save($contract, $attributes);
            $this->recordTransition($context, $saved, $from);
            $this->journal($context, $before, $saved, $ipAddress);

            return $this->contracts->find($context->organizationId, $saved->id);
        });
    }

    /** @param array<string, mixed> $input */
    public function updateObligation(TenantContext $context, string $id, string $obligationId, array $input, ?string $ipAddress = null): ContractRecord
    {
        $values = ContractInput::validate($this->validator, $input, ContractInput::obligationRules());
        $this->authorizeWrite($context);

        return $this->database->transaction(function () use ($context, $id, $obligationId, $values, $ipAddress): ContractRecord {
            $this->guard->lock($context);
            $this->authorizeWrite($context);
            $contract = $this->locked($context, $id, $values['contract_version']);
            $obligation = $this->contracts->obligation($context->organizationId, $contract->id, $obligationId);
            if ($obligation->version !== $values['version']) {
                throw new DomainConflictException(__('managed_contracts.stale'), 'CONTRACT_OBLIGATION_VERSION_STALE');
            }
            if ($obligation->status === $values['status']) {
                throw ValidationException::withMessages(['status' => __('managed_contracts.empty')]);
            }
            $before = $this->state($contract);
            $this->contracts->saveObligation($obligation, $values['status'], (string) Str::uuid());
            $saved = $this->contracts->save($contract, ['version' => (string) Str::uuid()]);
            $this->journal($context, $before, $saved, $ipAddress);

            return $this->contracts->find($context->organizationId, $saved->id);
        });
    }

    private function locked(TenantContext $context, string $id, string $version): ContractRecord
    {
        $contract = $this->contracts->find($context->organizationId, $id, true);
        if ($contract->version !== $version) {
            throw new DomainConflictException(__('managed_contracts.stale'), 'CONTRACT_VERSION_STALE');
        }
        if ($contract->status === 'archived') {
            throw new DomainConflictException(__('managed_contracts.archived'), 'CONTRACT_ARCHIVED');
        }

        return $contract;
    }

    private function authorizeWrite(TenantContext $context, ?string $target = null): void
    {
        $this->authorization->authorize($context, 'administrative.read');
        $this->authorization->authorize($context, 'administrative.write');
        if (in_array($target, ContractLifecycle::VALIDATION_TARGETS, true)) {
            $this->authorization->authorize($context, 'administrative.validate');
        }
    }

    private function recordTransition(TenantContext $context, ContractRecord $contract, ?string $from): void
    {
        $this->contracts->recordTransition(['organization_id' => $context->organizationId, 'contract_id' => $contract->id,
            'from_status' => $from, 'to_status' => $contract->status, 'contract_version' => $contract->version,
            'actor_user_id' => $context->actorUserId, 'occurred_at' => CarbonImmutable::now()]);
    }

    /** @return array<string, mixed> */
    private function state(ContractRecord $contract): array
    {
        return ['contract_id' => $contract->id, 'contract_status' => $contract->status, 'version' => $contract->version];
    }

    /** @param array<string, mixed> $before */
    private function journal(TenantContext $context, array $before, ContractRecord $contract, ?string $ipAddress): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
            AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress, $before, $this->state($contract)));
    }
}
