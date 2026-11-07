<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\WorkTaskPeopleDirectory;
use App\Modules\People\Domain\Contracts\CalendarPeopleRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class WorkTaskPeopleService implements WorkTaskPeopleDirectory
{
    public function __construct(private CalendarPeopleRepository $people, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'tasks.read');
        $this->authorization->authorize($context, 'tasks.manage');

        return $this->people->choices($context->organizationId, max(1, min(100000, $page)), max(1, min(100, $perPage)));
    }

    public function assertAssignable(TenantContext $context, string $id): void
    {
        $this->authorization->authorize($context, 'tasks.read');
        $this->authorization->authorize($context, 'tasks.manage');
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('Task assignment requires the tenant transaction.');
        }
        $this->people->assertAssignable($context->organizationId, [$id]);
    }

    public function labels(TenantContext $context, array $ids): array
    {
        $this->authorization->authorize($context, 'tasks.read');
        if (count($ids) > 100) {
            throw new LogicException('Task labels require a bounded page.');
        }

        return $this->people->labels($context->organizationId, $ids);
    }
}
