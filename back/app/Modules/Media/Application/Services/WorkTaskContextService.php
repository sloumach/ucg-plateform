<?php

namespace App\Modules\Media\Application\Services;

use App\Modules\Media\Domain\Contracts\WorkTaskRepository;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Communication\ContextSource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

final readonly class WorkTaskContextService implements ContextSource
{
    public function __construct(private WorkTaskRepository $records, private TenantAuthorization $authorization) {}

    public function visibleIds(TenantContext $context): ?Builder
    {
        return $this->authorization->allows($context, 'tasks.read') ? $this->records->visibleIds($context->organizationId) : null;
    }

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'tasks.read');

        $rows = $this->records->paginate($context->organizationId, ['page' => max(1, min(100000, $page)), 'per_page' => max(1, min(100, $perPage))]);

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn (WorkTask $task): array => ['id' => $task->id, 'label' => $task->title], $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage());
    }

    public function resolve(TenantContext $context, string $id): array
    {
        $this->authorization->authorize($context, 'tasks.read');
        $task = $this->records->find($context->organizationId, $id);

        return ['id' => $task->id, 'label' => $task->title, 'version' => $task->version];
    }
}
