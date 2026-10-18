<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Teams\Application\Contracts\ExternalCompetitionGames;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Contracts\TeamAudienceRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ExternalCompetitionGameService implements ExternalCompetitionGames
{
    public function __construct(private TeamAudienceRepository $audience, private GameCatalogueRepository $catalogue, private EffectiveGameCatalogue $games, private TenantAuthorization $authorization) {}

    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorize($context);

        return $this->audience->choices($context->organizationId, 'game', max(1, min(100000, $page)), max(1, min(25, $perPage)));
    }

    public function available(TenantContext $context, string $id): array
    {
        $this->authorize($context);
        $this->games->assertGameAvailable($context->organizationId, $id);
        $game = $this->catalogue->find($id);

        return ['id' => $game->id, 'key' => $game->key, 'name' => $game->name];
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'sports.competitions.read');
        $this->authorization->authorize($context, 'sports.competitions.write');
    }
}
