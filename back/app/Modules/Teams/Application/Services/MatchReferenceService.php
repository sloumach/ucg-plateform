<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Contracts\MatchReferenceRepository;
use App\Modules\Teams\Domain\Contracts\TeamAudienceRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class MatchReferenceService implements MatchReferenceDirectory
{
    public function __construct(private SportingPeopleDirectory $people, private TeamAudienceRepository $audience,
        private MatchReferenceRepository $records, private GameCatalogueRepository $catalogue,
        private EffectiveGameCatalogue $games, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    public function viewer(TenantContext $context): array
    {
        $this->authorization->authorize($context, 'sports.read');
        $person = $this->people->personForAccount($context->organizationId, $context->actorUserId);

        return ['person_id' => $person, 'team_ids' => $this->audience->current($context->organizationId, $person)['teams']];
    }

    public function choices(TenantContext $context, string $type, ?string $gameId, int $page, int $perPage): LengthAwarePaginator
    {
        $this->write($context);
        $page = max(1, min(100000, $page));
        $perPage = max(1, min(100, $perPage));
        if ($type === 'game') {
            return $this->audience->choices($context->organizationId, 'game', $page, $perPage);
        }
        if ($type === 'person') {
            return $this->people->choices($context, $page, $perPage);
        }
        if (! in_array($type, ['team', 'opponent_snapshot'], true)) {
            throw new LogicException('Unknown match reference.');
        }

        return $this->records->choices($context->organizationId, $type, $gameId, $page, $perPage);
    }

    public function game(TenantContext $context, string $id): array
    {
        $this->write($context);
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('Match selection requires the tenant transaction.');
        }
        $this->games->assertGameAvailable($context->organizationId, $id);
        $game = $this->catalogue->find($id);

        return ['id' => $game->id, 'key' => $game->key, 'name' => $game->name];
    }

    public function participant(TenantContext $context, string $type, string $id, string $gameId): array
    {
        $result = $this->participants($context, [['kind' => $type, 'source_id' => $id]], $gameId)[0];

        return ['label' => $result['label'], 'version' => $result['version']];
    }

    public function participants(TenantContext $context, array $participants, string $gameId): array
    {
        if (count($participants) > 100) {
            throw new LogicException('Match participants must be bounded.');
        }
        $game = $this->game($context, $gameId);
        $snapshots = [];
        foreach (['person', 'team', 'opponent_snapshot'] as $type) {
            $ids = array_values(array_unique(array_column(array_filter($participants, fn (array $p): bool => $p['kind'] === $type), 'source_id')));
            $snapshots[$type] = $type === 'person' ? $this->people->snapshots($context, $ids)
                : $this->records->snapshots($context->organizationId, $type, $ids, $gameId, $game['key']);
        }

        return array_map(fn (array $p): array => [...$p, ...$snapshots[$p['kind']][$p['source_id']]], $participants);
    }

    private function write(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'sports.write');
    }
}
