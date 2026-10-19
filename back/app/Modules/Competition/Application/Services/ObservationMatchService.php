<?php

namespace App\Modules\Competition\Application\Services;

use App\Modules\Competition\Application\Contracts\ObservationMatchDirectory;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Contracts\MatchScoreRepository;
use App\Modules\Competition\Domain\Models\MatchGameInstance;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

final readonly class ObservationMatchService implements ObservationMatchDirectory
{
    public function __construct(private MatchRepository $records, private MatchService $matches, private MatchReferenceDirectory $references, private MatchScoreRepository $scores) {}

    public function visibleIds(TenantContext $context): Builder
    {
        return $this->records->visibleIdsQuery($context->organizationId, $context->actorUserId, $this->references->viewer($context));
    }

    public function assertReference(TenantContext $context, string $match, string $game, ?string $part): array
    {
        $row = $this->matches->find($context, $match, []);
        if ($row->game_id !== $game) {
            throw (new ModelNotFoundException)->setModel(MatchRecord::class);
        }
        if ($part !== null) {
            $this->scores->findGame($context->organizationId, $row->id, $part);
        }

        return ['match_id' => $row->id, 'kind' => $row->kind];
    }

    public function choices(TenantContext $context, string $game, int $page): LengthAwarePaginator
    {
        $rows = $this->matches->paginate($context, ['game_id' => $game, 'page' => $page, 'per_page' => 10]);

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn (MatchRecord $r): array => ['id' => $r->id, 'label' => $r->title], $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage());
    }

    public function parts(TenantContext $context, string $match, int $page): LengthAwarePaginator
    {
        $this->matches->find($context, $match, []);

        $rows = $this->scores->paginate($context->organizationId, $match, $page, 10);

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn (MatchGameInstance $r): array => ['id' => $r->id, 'label' => 'Partie '.$r->number], $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage());
    }
}
