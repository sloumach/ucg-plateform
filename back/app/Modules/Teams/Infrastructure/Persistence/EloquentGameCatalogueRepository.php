<?php

namespace App\Modules\Teams\Infrastructure\Persistence;

use App\Exceptions\DomainConflictException;
use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class EloquentGameCatalogueRepository implements GameCatalogueRepository
{
    public function __construct(private ConnectionInterface $database) {}

    public function revision(bool $lock = false): string
    {
        $query = $this->database->table('game_catalogue_state')->where('id', 'catalogue');
        $revision = ($lock ? $query->lockForUpdate() : $query)->value('version');
        if (! is_string($revision) || ! Str::isUuid($revision)) {
            throw new DomainConflictException(__('game_catalogue.invalid'), 'GAME_CATALOGUE_INVALID');
        }

        return $revision;
    }

    public function advanceRevision(): string
    {
        $version = (string) Str::uuid();
        $this->database->table('game_catalogue_state')->where('id', 'catalogue')->update(['version' => $version]);

        return $version;
    }

    public function paginate(string $kind, ?string $gameId, int $perPage, int $page): LengthAwarePaginator
    {
        return GameCatalogueEntry::query()->where('kind', $kind)->where('scope_key', $gameId ?? 'global')->orderBy('name')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(string $id): GameCatalogueEntry
    {
        return GameCatalogueEntry::query()->findOrFail(strtolower($id));
    }

    public function snapshot(): array
    {
        $limit = GameCatalogueInput::MAX_GAMES + (count(GameCatalogueInput::GLOBAL) - 1) * GameCatalogueInput::MAX_GLOBAL_REFERENCES;
        $entries = GameCatalogueEntry::query()->whereIn('kind', GameCatalogueInput::GLOBAL)->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get();
        if ($entries->count() > $limit) {
            throw new DomainConflictException(__('game_catalogue.invalid'), 'GAME_CATALOGUE_INVALID');
        }

        return array_values($entries->all());
    }

    public function save(?GameCatalogueEntry $entry, array $attributes): GameCatalogueEntry
    {
        $entry ??= new GameCatalogueEntry;
        $entry->forceFill($attributes)->save();

        return $entry;
    }

    public function references(string $gameId): array
    {
        $limit = GameCatalogueInput::MAX_GAME_REFERENCES * count(GameCatalogueInput::SCOPED);
        $entries = GameCatalogueEntry::query()->where('game_id', strtolower($gameId))->orderBy('kind')->orderBy('name')->limit($limit + 1)->get();
        if ($entries->count() > $limit) {
            throw new DomainConflictException(__('game_catalogue.invalid'), 'GAME_CATALOGUE_INVALID');
        }

        return array_values($entries->all());
    }

    public function audit(array $attributes): void
    {
        $this->database->table('game_catalogue_events')->insert($attributes);
    }

    public function settings(string $organizationId): array
    {
        $settings = OrganizationGame::query()->where('organization_id', strtolower($organizationId))->limit(GameCatalogueInput::MAX_GAMES + 1)->get();
        if ($settings->count() > GameCatalogueInput::MAX_GAMES) {
            throw new DomainConflictException(__('game_catalogue.invalid'), 'GAME_CATALOGUE_INVALID');
        }

        return array_values($settings->all());
    }

    public function setting(string $organizationId, string $gameId, bool $lock = false): ?OrganizationGame
    {
        $query = OrganizationGame::query()->where('organization_id', strtolower($organizationId))->where('game_id', strtolower($gameId));

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function saveSetting(?OrganizationGame $setting, array $attributes): OrganizationGame
    {
        $setting ??= new OrganizationGame;
        $setting->forceFill($attributes)->save();

        return $setting;
    }

    public function count(string $kind, string $scope): int
    {
        return GameCatalogueEntry::query()->where('kind', $kind)->where('scope_key', $scope)->count();
    }
}
