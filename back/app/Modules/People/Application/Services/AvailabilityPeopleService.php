<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\AvailabilityPeopleDirectory;
use App\Modules\People\Domain\Contracts\CalendarPeopleRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class AvailabilityPeopleService implements AvailabilityPeopleDirectory
{
    public function __construct(private CalendarPeopleRepository $records, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    public function viewer(TenantContext $context): array
    {
        if (! $this->authorization->allows($context, 'availability.self') && ! $this->authorization->allows($context, 'availability.read')) {
            throw new AuthorizationException;
        }
        $viewer = $this->records->viewer($context->organizationId, $context->actorUserId);

        return ['person_id' => $viewer['person_id'], 'timezone' => $viewer['timezone'] ?? $context->timezone];
    }

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'availability.read');

        return $this->records->choices($context->organizationId, max(1, min(100000, $page)), max(1, min(100, $perPage)));
    }

    public function assertAssignable(TenantContext $context, string $personId): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('Availability writes require a tenant transaction.');
        }
        $this->authorizePerson($context, [$personId], true);
        $this->records->assertAssignable($context->organizationId, [$personId]);
    }

    public function labels(TenantContext $context, array $ids): array
    {
        if (count($ids) > 100) {
            throw new LogicException('Availability labels must be bounded.');
        }
        $this->authorizePerson($context, $ids, false);

        return $this->records->labels($context->organizationId, $ids);
    }

    /** @param list<string> $ids */
    private function authorizePerson(TenantContext $context, array $ids, bool $writing): void
    {
        $viewer = $this->viewer($context);
        if ($this->authorization->allows($context, 'availability.self') && array_diff($ids, [$viewer['person_id']]) === []) {
            return;
        }
        $this->authorization->authorize($context, 'availability.read');
        if ($writing) {
            $this->authorization->authorize($context, 'availability.manage');
        }
    }
}
