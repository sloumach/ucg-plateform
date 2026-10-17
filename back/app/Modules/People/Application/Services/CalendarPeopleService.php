<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\People\Domain\Contracts\CalendarPeopleRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class CalendarPeopleService implements CalendarPeopleDirectory
{
    public function __construct(private CalendarPeopleRepository $records, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    public function viewer(TenantContext $context): array
    {
        $this->authorization->authorize($context, 'calendar.read');
        $viewer = $this->records->viewer($context->organizationId, $context->actorUserId);

        return ['person_id' => $viewer['person_id'], 'timezone' => $viewer['timezone'] ?? $context->timezone];
    }

    public function accounts(TenantContext $context, array $ids): array
    {
        $this->authorizeWrite($context);
        if ($this->database->transactionLevel() < 1 || count($ids) > 50) {
            throw new LogicException('Calendar recipient resolution requires a bounded tenant transaction.');
        }

        return $this->records->accounts($context->organizationId, $ids);
    }

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorizeWrite($context);

        return $this->records->choices($context->organizationId, max(1, min(100000, $page)), max(1, min(100, $perPage)));
    }

    public function assertAssignable(TenantContext $context, array $ids): void
    {
        $this->authorizeWrite($context);
        if ($this->database->transactionLevel() < 1 || count($ids) > 50) {
            throw new LogicException('Calendar participants require a bounded tenant transaction.');
        }
        $this->records->assertAssignable($context->organizationId, $ids);
    }

    public function labels(TenantContext $context, array $ids): array
    {
        $this->authorization->authorize($context, 'calendar.read');
        if (count($ids) > 5000) {
            throw new LogicException('Calendar labels must be bounded.');
        }

        return $this->records->labels($context->organizationId, $ids);
    }

    private function authorizeWrite(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'calendar.read');
        $this->authorization->authorize($context, 'calendar.write');
    }
}
