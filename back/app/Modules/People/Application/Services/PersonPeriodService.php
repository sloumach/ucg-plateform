<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Data\PersonLifecycleData;
use App\Modules\People\Application\Validation\PersonLifecycleInput as Input;
use App\Modules\People\Domain\Contracts\PersonLifecycleRepository;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\PersonPeriod;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PersonPeriodService
{
    public function __construct(private PersonLifecycleRepository $records, private PersonRepository $people,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private PersonLifecycleProjectionService $projection, private ConnectionInterface $database, private Factory $validator) {}

    /** @return LengthAwarePaginator<int,PersonPeriod> */
    public function list(TenantContext $context, string $personId, int $perPage, int $page): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->validator->make(['per_page' => $perPage, 'page' => $page], Input::pageRules(), Input::messages())->validate();
        $this->people->find($context->organizationId, $personId);

        return $this->records->periods($context->organizationId, $personId, $perPage, $page);
    }

    public function unavailable(TenantContext $context, string $personId): bool
    {
        $this->authorization->authorize($context, 'sports.read');

        return $this->records->unavailableNow($context->organizationId, $personId, CarbonImmutable::now());
    }

    public function save(TenantContext $context, string $personId, PersonLifecycleData $data, ?string $id = null, ?string $ip = null): PersonPeriod
    {
        $attributes = Input::validate($this->validator, $data, Input::periodRules($id === null), $id === null);
        $this->authorization->authorize($context, 'sports.write');

        return $this->database->transaction(function () use ($context, $personId, $data, $attributes, $id, $ip): PersonPeriod {
            $this->guard->lock($context);
            $this->authorization->authorize($context, 'sports.write');
            $this->people->find($context->organizationId, $personId, true);
            if ($this->records->departure($context->organizationId, $personId) !== null) {
                throw new DomainConflictException(__('people_lifecycle.departed'), 'PERSON_ALREADY_DEPARTED');
            }
            $period = $id === null ? null : $this->records->findPeriod($context->organizationId, $personId, $id);
            if ($period !== null && ($period->version !== $data->version || $period->status !== 'active')) {
                throw new DomainConflictException(__('people_lifecycle.stale'), 'PERSON_PERIOD_VERSION_STALE');
            }
            if ($period === null && $this->records->periodCount($context->organizationId, $personId) >= Input::MAX_PERIODS) {
                throw new DomainConflictException(__('people_lifecycle.limit'), 'PERSON_PERIOD_LIMIT_REACHED');
            }
            $kind = $period->kind ?? $attributes['kind'];
            $role = $period->role_key ?? ($attributes['role_key'] ?? null);
            if ($kind === 'unavailability' && $role !== null) {
                throw ValidationException::withMessages(['role_key' => __('people.validation.immutable')]);
            }
            $start = Input::date($attributes['starts_at'] ?? $period?->starts_at->toIso8601String());
            $end = array_key_exists('ends_at', $attributes) ? Input::date($attributes['ends_at']) : $period?->ends_at;
            if ($start === null || ($end !== null && ! $end->greaterThan($start))) {
                throw ValidationException::withMessages(['ends_at' => __('people_lifecycle.period')]);
            }
            $status = $attributes['status'] ?? $period->status ?? 'active';
            if ($status !== 'cancelled' && $this->records->overlaps($context->organizationId, $personId, $kind, $role, $start, $end, $id)) {
                throw new DomainConflictException(__('people_lifecycle.overlap'), 'PERSON_PERIOD_OVERLAP');
            }
            $before = $period === null ? [] : $this->projection->auditPeriod($period);
            $saved = $this->records->savePeriod($context->organizationId, $personId, $period,
                [...$attributes, 'starts_at' => $start, 'ends_at' => $end, 'status' => $status, 'version' => (string) Str::uuid()]);
            $this->projection->audit($context, $before, [...$this->projection->auditPeriod($saved), 'period_changed_fields' => array_keys($attributes)], $ip);

            return $saved;
        });
    }
}
