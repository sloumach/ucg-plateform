<?php

namespace App\Modules\Teams\Domain\Contracts;

use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface GameCatalogueRepository
{
    public function revision(bool $lock = false): string;

    public function advanceRevision(): string;

    /** @return LengthAwarePaginator<int, GameCatalogueEntry> */
    public function paginate(string $kind, ?string $gameId, int $perPage, int $page): LengthAwarePaginator;

    public function find(string $id): GameCatalogueEntry;

    /** @return list<GameCatalogueEntry> */
    public function snapshot(): array;

    /** @return list<GameCatalogueEntry> */
    public function references(string $gameId): array;

    /** @param array<string, mixed> $attributes */
    public function save(?GameCatalogueEntry $entry, array $attributes): GameCatalogueEntry;

    /** @param array<string, mixed> $attributes */
    public function audit(array $attributes): void;

    /** @return list<OrganizationGame> */
    public function settings(string $organizationId): array;

    public function setting(string $organizationId, string $gameId, bool $lock = false): ?OrganizationGame;

    /** @param array<string, mixed> $attributes */
    public function saveSetting(?OrganizationGame $setting, array $attributes): OrganizationGame;

    public function count(string $kind, string $scope): int;
}
