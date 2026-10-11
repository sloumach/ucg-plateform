<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\PeopleDirectory;
use App\Modules\People\Application\Contracts\PersonRecord;
use App\Modules\People\Application\Data\PersonData;
use App\Modules\People\Application\Validation\PersonInput;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PersonService implements PeopleDirectory
{
    public function __construct(private PersonRepository $people, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private PersonProjectionService $projection,
        private ConnectionInterface $database, private Factory $validator) {}

    public function find(TenantContext $context, string $personId): PersonRecord
    {
        $this->authorizeRead($context);

        return $this->projection->project($context, $this->people->find($context->organizationId, $personId));
    }

    public function create(TenantContext $context, PersonData $data, ?string $ipAddress = null): PersonRecord
    {
        return $this->save($context, $data, null, $ipAddress);
    }

    public function update(TenantContext $context, string $personId, PersonData $data, ?string $ipAddress = null): PersonRecord
    {
        return $this->save($context, $data, $personId, $ipAddress);
    }

    private function save(TenantContext $context, PersonData $data, ?string $personId, ?string $ipAddress): PersonRecord
    {
        $attributes = $this->validated($data, $personId === null);
        $this->authorizeWrite($context, $attributes);
        try {
            return $this->database->transaction(function () use ($context, $data, $attributes, $personId, $ipAddress): PersonRecord {
                $this->guard->lock($context, isset($attributes['user_id']) ? (int) $attributes['user_id'] : null);
                $this->authorizeWrite($context, $attributes);
                $person = $personId === null ? null : $this->people->find($context->organizationId, $personId, true);
                if ($person !== null && $person->version !== $data->version) {
                    throw new DomainConflictException(__('people.stale'), 'PERSON_VERSION_STALE');
                }
                $before = $person === null ? [] : $this->auditState($person);
                $values = [...$attributes, 'version' => (string) Str::uuid()];
                $saved = $person === null ? $this->people->create($context->organizationId, $values) : $this->people->update($person, $values);
                // Closed states identify the person without copying administrative identity or biography.
                $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId,
                    AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId, AuditReason::Domain,
                    $context->requestId, $ipAddress, $before, [...$this->auditState($saved), 'changed_fields' => array_keys($attributes)]));

                return $this->projection->project($context, $saved);
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('people.account_duplicate'), 'PERSON_ACCOUNT_ALREADY_LINKED');
        }
    }

    private function authorizeRead(TenantContext $context): void
    {
        if (! $this->authorization->allows($context, 'sports.read') && ! $this->authorization->allows($context, 'administrative.read')) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function authorizeWrite(TenantContext $context, array $attributes): void
    {
        if (array_intersect(array_keys($attributes), PersonInput::SPORTS_FIELDS) !== []) {
            $this->authorization->authorize($context, 'sports.write');
        }
        if (array_intersect(array_keys($attributes), PersonInput::ADMINISTRATIVE_FIELDS) !== []) {
            $this->authorization->authorize($context, 'administrative.write');
        }
    }

    /** @return array<string, mixed> */
    private function validated(PersonData $data, bool $creating): array
    {
        $unknown = array_diff(array_keys($data->attributes), [...PersonInput::SPORTS_FIELDS, ...PersonInput::ADMINISTRATIVE_FIELDS]);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('people.validation.immutable')));
        }
        if ($data->attributes === []) {
            throw ValidationException::withMessages(['person' => __('people.validation.empty')]);
        }
        $values = $this->validator->make([...$data->attributes, 'version' => $data->version],
            [...PersonInput::rules($creating), 'version' => $creating ? ['prohibited'] : ['required', 'uuid']], PersonInput::messages())->validate();
        unset($values['version']);
        if (isset($values['user_id'])) {
            $values['user_id'] = (int) $values['user_id'];
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function auditState(Person $person): array
    {
        return ['person_id' => $person->id, 'person_status' => $person->status->value,
            'account_linked' => $person->user_id !== null, 'version' => $person->version];
    }
}
