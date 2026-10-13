<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use Illuminate\Database\ConnectionInterface;

final readonly class GameProfileCatalogueBridge implements GameProfileCatalogue
{
    public function __construct(private EffectiveGameCatalogue $catalogue, private GameCatalogueRepository $repository, private ConnectionInterface $database) {}

    public function entries(string $organizationId): array
    {
        return $this->catalogue->profiles($organizationId);
    }

    public function assertAvailable(string $organizationId, string $gameKey, string $platformKey, string $regionKey, ?string $providerKey = null): void
    {
        if ($this->database->transactionLevel() > 0) {
            $this->repository->revision(true);
        }
        foreach ($this->entries($organizationId) as $game) {
            if ($game['key'] !== $gameKey || ! in_array($regionKey, array_column($game['regions'], 'key'), true)) {
                continue;
            }
            foreach ($game['platforms'] as $platform) {
                if ($platform['key'] === $platformKey && ($providerKey === null || in_array($providerKey, array_column($platform['providers'], 'key'), true))) {
                    return;
                }
            }
        }
        throw new DomainConflictException(__('game_profiles.context_unavailable'), 'GAME_CONTEXT_UNAVAILABLE');
    }
}
