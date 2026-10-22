<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\AcademyPeopleDirectory;
use App\Modules\People\Domain\Contracts\CalendarPeopleRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

final readonly class AcademyPeopleService implements AcademyPeopleDirectory
{
    public function __construct(private CalendarPeopleRepository $people, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'academy.read');
        $this->authorization->authorize($context, 'academy.manage');

        return $this->people->choices($context->organizationId, max(1, min(100000, $page)), max(1, min(100, $perPage)));
    }

    public function account(TenantContext $context, string $personId, bool $lock = false): ?int
    {
        $this->authorization->authorize($context, 'academy.read');
        $this->authorization->authorize($context, 'academy.manage');
        if ($lock) {
            if ($this->database->transactionLevel() < 1) {
                throw new LogicException('Academy person locks require a business transaction.');
            }
            $this->people->assertAssignable($context->organizationId, [$personId]);
        } elseif (! isset($this->people->labels($context->organizationId, [$personId])[$personId])) {
            throw new ModelNotFoundException;
        }

        return $this->people->accounts($context->organizationId, [$personId])[$personId] ?? null;
    }

    public function labels(TenantContext $context, array $personIds): array
    {
        $this->authorization->authorize($context, 'academy.read');
        if (count($personIds) > 100) {
            throw new LogicException('Academy labels require a bounded page.');
        }

        return $this->people->labels($context->organizationId, $personIds);
    }
}
