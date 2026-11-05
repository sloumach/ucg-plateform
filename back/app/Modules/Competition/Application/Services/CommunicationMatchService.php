<?php

namespace App\Modules\Competition\Application\Services;

use App\Modules\Competition\Application\Contracts\CommunicationMatchDirectory;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

final readonly class CommunicationMatchService implements CommunicationMatchDirectory
{
    public function __construct(private MatchRepository $records, private MatchService $matches,
        private MatchReferenceDirectory $references, private TenantAuthorization $authorization) {}

    public function visibleIds(TenantContext $context): ?Builder
    {
        return $this->authorization->allows($context, 'sports.read')
            ? $this->records->visibleIdsQuery($context->organizationId, $context->actorUserId, $this->references->viewer($context))->where('status', '!=', 'cancelled') : null;
    }

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $rows = $this->records->communicationChoices($context->organizationId, $context->actorUserId, $this->references->viewer($context), max(1, min(100000, $page)), max(1, min(100, $perPage)));

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn (MatchRecord $row): array => ['id' => $row->id, 'label' => $row->title], $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage());
    }

    public function resolve(TenantContext $context, string $id): array
    {
        $row = $this->matches->find($context, $id, []);
        if ($row->status === 'cancelled') {
            throw new ModelNotFoundException;
        }

        return ['id' => $row->id, 'label' => $row->title, 'version' => $row->version];
    }
}
