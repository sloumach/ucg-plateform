<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\PersonStatus;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Database\ConnectionInterface;

final readonly class SportingPeopleService implements SportingPeopleDirectory
{
    public function __construct(private PersonRepository $people, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    public function assertAssignable(TenantContext $context, string $personId): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new \LogicException('Sporting assignments require the tenant transaction.');
        }
        $this->authorization->authorize($context, 'sports.read');
        $person = $this->people->find($context->organizationId, $personId, true);
        if ($person->status === PersonStatus::Former || $this->people->hasDeparted($context->organizationId, $personId)) {
            throw new DomainConflictException(__('team_memberships.person_unavailable'), 'TEAM_PERSON_UNAVAILABLE');
        }
    }

    public function pseudonyms(TenantContext $context, array $ids): array
    {
        $this->authorization->authorize($context, 'sports.read');
        if (count($ids) > 100) {
            throw new \LogicException('Person projections must be paginated.');
        }

        return $this->people->pseudonyms($context->organizationId, $ids);
    }

    public function personForAccount(string $organizationId, int $userId): ?string
    {
        return $this->people->personForAccount($organizationId, $userId);
    }
}
