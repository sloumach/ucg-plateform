<?php

namespace App\Modules\Academy\Application\Services;

use App\Modules\Academy\Application\Contracts\CommunicationSessionDirectory;
use App\Modules\Academy\Domain\Contracts\AcademySessionRepository;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

final readonly class CommunicationSessionService implements CommunicationSessionDirectory
{
    public function __construct(private AcademySessionRepository $records, private TenantAuthorization $authorization) {}

    public function visibleIds(TenantContext $context): ?Builder
    {
        // academy.read is the existing staff audience; academy.self.read alone grants no staff discussion.
        return $this->authorization->allows($context, 'academy.read') ? $this->records->communicationIds($context->organizationId) : null;
    }

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'academy.read');
        $rows = $this->records->communicationChoices($context->organizationId, max(1, min(100000, $page)), max(1, min(100, $perPage)));

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn (AcademySession $row): array => ['id' => $row->id, 'label' => $row->title], $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage());
    }

    public function resolve(TenantContext $context, string $id): array
    {
        $this->authorization->authorize($context, 'academy.read');
        $row = $this->records->communicationSession($context->organizationId, $id);

        return ['id' => $row->id, 'label' => $row->title, 'version' => $row->version];
    }
}
