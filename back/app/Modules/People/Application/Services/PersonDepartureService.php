<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\PersonDepartureParticipant;
use App\Modules\People\Application\Data\PersonLifecycleData;
use App\Modules\People\Application\Validation\PersonLifecycleInput as Input;
use App\Modules\People\Domain\Contracts\PersonLifecycleRepository;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\PersonStatus;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDepartureAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\Str;

final readonly class PersonDepartureService
{
    /** @param iterable<PersonDepartureParticipant> $participants */
    public function __construct(private PersonLifecycleRepository $records, private PersonRepository $people,
        private TenantAuthorization $authorization, private TenantDepartureAccess $access, private Factory $validator,
        private PersonLifecycleProjectionService $projection, private iterable $participants) {}

    /** @return array<string,mixed> */
    public function show(TenantContext $context, string $personId): array
    {
        $this->projection->authorizeRead($context);
        $person = $this->people->find($context->organizationId, $personId);

        return $this->projection->departure($context, $this->records->departure($context->organizationId, $personId), $person->version);
    }

    /** @return array<string,mixed> */
    public function depart(TenantContext $context, string $personId, PersonLifecycleData $data, ?string $ip = null): array
    {
        $attributes = Input::validate($this->validator, $data, Input::departureRules(), false);
        $this->authorize($context);
        $initial = $this->people->find($context->organizationId, $personId);

        return $this->access->run($context, $initial->user_id, function () use ($context, $personId, $data, $attributes, $initial, $ip): array {
            $this->authorize($context);
            $person = $this->people->find($context->organizationId, $personId, true);
            if ($person->user_id !== $initial->user_id) {
                throw new DomainConflictException(__('people_lifecycle.stale'), 'PERSON_VERSION_STALE');
            }
            $existing = $this->records->departure($context->organizationId, $personId);
            if ($existing !== null) {
                if ($existing->previous_version !== $data->version || $existing->reason_key !== $attributes['reason_key']) {
                    throw new DomainConflictException(__('people_lifecycle.departed'), 'PERSON_ALREADY_DEPARTED');
                }

                return ['id' => $existing->id, 'person_id' => $personId, 'person_version' => $person->version, 'departed' => true];
            }
            if ($person->version !== $data->version) {
                throw new DomainConflictException(__('people_lifecycle.stale'), 'PERSON_VERSION_STALE');
            }
            $pending = $this->records->itemCount($context->organizationId, $personId, true);
            if ($pending > 0 && ! ($attributes['acknowledge_pending'] ?? false)) {
                throw new DomainConflictException(__('people_lifecycle.pending'), 'PERSON_DEPARTURE_PENDING_ITEMS');
            }
            $at = CarbonImmutable::now()->startOfSecond();
            foreach ($this->records->activePeriods($context->organizationId, $personId) as $period) {
                $before = $this->projection->auditPeriod($period);
                $values = ['status' => $period->starts_at->greaterThanOrEqualTo($at) ? 'cancelled' : 'closed', 'version' => (string) Str::uuid()];
                if ($values['status'] === 'closed' && ($period->ends_at === null || $period->ends_at->greaterThan($at))) {
                    $values['ends_at'] = $at;
                }
                $saved = $this->records->savePeriod($context->organizationId, $personId, $period, $values);
                $this->projection->audit($context, $before, $this->projection->auditPeriod($saved), $ip);
            }
            foreach ($this->participants as $participant) {
                $participant->closeForDeparture($context, $personId, $at);
            }
            $version = (string) Str::uuid();
            $savedPerson = $this->people->update($person, ['status' => PersonStatus::Former, 'version' => $version]);
            $departure = $this->records->createDeparture($context->organizationId, $personId, [
                'user_id' => $person->user_id, 'actor_user_id' => $context->actorUserId, 'reason_key' => $attributes['reason_key'],
                'departed_at' => $at, 'previous_version' => $data->version, 'person_version' => $version, 'pending_items_count' => $pending]);
            $this->projection->audit($context, ['person_id' => $personId, 'version' => $data->version],
                ['person_id' => $personId, 'lifecycle_id' => $departure->id, 'person_status' => 'former', 'version' => $savedPerson->version, 'departed_at' => $at->toIso8601String(), 'pending_items_count' => $pending], $ip);

            // Only action metadata is returned; self-departure must not re-authorize after access removal.
            return ['id' => $departure->id, 'person_id' => $personId, 'person_version' => $version, 'departed' => true];
        });
    }

    private function authorize(TenantContext $context): void
    {
        foreach (['sports.write', 'administrative.write', 'memberships.manage', 'invitations.manage'] as $permission) {
            $this->authorization->authorize($context, $permission);
        }
    }
}
