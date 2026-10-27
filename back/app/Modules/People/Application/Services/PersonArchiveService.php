<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\PersonRecord;
use App\Modules\People\Application\Data\PersonArchiveData;
use App\Modules\People\Application\Validation\PersonInput;
use App\Modules\People\Domain\Contracts\PersonLifecycleRepository;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\PersonStatus;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;

final readonly class PersonArchiveService
{
    public function __construct(private PersonRepository $people, private PersonLifecycleRepository $lifecycle,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit,
        private ConnectionInterface $database, private Factory $validator) {}

    public function change(TenantContext $context, string $personId, PersonArchiveData $data, bool $archive, ?string $ipAddress = null): PersonRecord
    {
        $this->validator->make(['version' => $data->version, 'confirm' => $data->confirm], PersonInput::archiveRules(), PersonInput::messages())->validate();
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $personId, $data, $archive, $ipAddress): PersonRecord {
            $this->guard->lock($context);
            $this->authorize($context);
            $person = $this->people->findIncludingArchived($context->organizationId, $personId);
            if ($person->version !== $data->version) {
                throw new DomainConflictException(__('people.stale'), 'PERSON_VERSION_STALE');
            }
            if ($person->trashed() === $archive) {
                throw new DomainConflictException(__('people.archive_state_conflict'), 'PERSON_ARCHIVE_STATE_CONFLICT');
            }
            if ($archive && ($person->status !== PersonStatus::Former || ! $this->people->hasDeparted($context->organizationId, $personId)
                || $this->lifecycle->itemCount($context->organizationId, $personId, true) !== 0)) {
                throw new DomainConflictException(__('people.archive_departure_required'), 'PERSON_ARCHIVE_DEPARTURE_REQUIRED');
            }
            $before = ['person_id' => $person->id, 'person_status' => $person->status->value, 'version' => $person->version,
                'person_archived' => $person->trashed()];
            $person = $this->people->setArchived($person, $archive);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress,
                $before, ['person_id' => $person->id, 'person_status' => $person->status->value, 'version' => $person->version,
                    'person_archived' => $person->trashed()]));
            $this->authorize($context);

            return new PersonRecord(['id' => $person->id, 'version' => $person->version, 'deleted_at' => $person->deleted_at?->toIso8601String(),
                'retention' => 'indefinite', 'physical_erasure' => false]);
        });
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.write');
        $this->authorization->authorize($context, 'administrative.write');
    }
}
