<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\CompetitionRegistrationPeople;
use App\Modules\People\Domain\Contracts\CalendarPeopleRepository;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;

final readonly class CompetitionRegistrationPeopleService implements CompetitionRegistrationPeople
{
    public function __construct(private PersonRepository $people, private CalendarPeopleRepository $accounts, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    private function authorize(TenantContext $context): void
    {
        foreach (['sports.read', 'sports.competitions.read', 'sports.registrations.read', 'sports.registrations.write'] as $permission) {
            $this->authorization->authorize($context, $permission);
        }
    }

    public function choices(TenantContext $context, int $page, int $size): LengthAwarePaginator
    {
        $this->authorize($context);

        return $this->people->partnershipPage($context->organizationId, max(1, min(100000, $page)), max(1, min(10, $size)));
    }

    public function snapshots(TenantContext $context, array $ids): array
    {
        $this->authorize($context);
        if ($this->database->transactionLevel() < 1 || count($ids) > 70) {
            throw new \LogicException('Registration people require a bounded tenant transaction.');
        }

        return $this->people->sportingSnapshots($context->organizationId, $ids);
    }

    public function accounts(string $org, array $ids): array
    {
        if (count($ids) > 50) {
            throw new \LogicException('Registration recipients must be bounded.');
        }

        return $this->accounts->accounts($org, $ids);
    }
}
