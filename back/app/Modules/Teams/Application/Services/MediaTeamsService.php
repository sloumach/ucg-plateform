<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Teams\Application\Contracts\MediaTeamsDirectory;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final readonly class MediaTeamsService implements MediaTeamsDirectory
{
    public function __construct(private TeamRepository $records, private TenantAuthorization $authorization) {}

    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'media.read');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('References must be paginated.');
        }

        return $this->records->referencePage($context->organizationId, $page, $perPage);
    }

    public function labels(TenantContext $context, array $ids): array
    {
        $this->authorization->authorize($context, 'media.read');
        if (count($ids) > 100) {
            throw new InvalidArgumentException('References must be bounded.');
        }

        return $this->records->referenceLabels($context->organizationId, array_map('strtolower', $ids));
    }

    public function assertActive(TenantContext $context, string $id): void
    {
        $this->authorization->authorize($context, 'media.read');
        if ($this->records->find($context->organizationId, $id, true)->status !== 'active') {
            throw new ModelNotFoundException;
        }
    }
}
