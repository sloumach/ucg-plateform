<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Teams\Application\Contracts\OrganizationGameCatalogue;
use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

final readonly class EffectiveGameCatalogue implements OrganizationGameCatalogue
{
    public function __construct(private GameCatalogueRepository $repository, private ConnectionInterface $database, private Cache $cache) {}

    /** @return list<GameCatalogueEntry> */
    public function snapshot(): array
    {
        if ($this->database->transactionLevel() > 0) {
            return $this->repository->snapshot();
        }
        $revision = $this->repository->revision();
        /** @var list<GameCatalogueEntry> $entries */
        $entries = $this->cache->remember('game-catalogue:v1:'.$revision, 300, fn (): array => $this->repository->snapshot());

        return $entries;
    }

    /** @param list<GameCatalogueEntry>|null $snapshot
     * @return array<string, list<array{key: string, name: string}>>
     */
    public function options(GameCatalogueEntry $game, bool $scoped = true, ?array $snapshot = null): array
    {
        $definition = $this->definition($game);
        $allowed = ['platform' => array_keys($definition['platforms']), 'region' => $definition['regions'], 'provider' => []];
        foreach ($definition['platforms'] as $providers) {
            $allowed['provider'] = array_values(array_unique([...$allowed['provider'], ...$providers]));
        }
        $dictionary = [];
        $options = array_fill_keys(GameCatalogueInput::RESTRICTIONS, []);
        foreach ($snapshot ?? $this->snapshot() as $entry) {
            $dictionary[$entry->kind][$entry->key] = $entry;
            if ($game->active && isset($allowed[$entry->kind]) && $entry->active && in_array($entry->key, $allowed[$entry->kind], true)) {
                $options[$entry->kind][] = ['key' => $entry->key, 'name' => $entry->name];
            }
        }
        foreach ($allowed as $kind => $keys) {
            foreach ($keys as $key) {
                if (! isset($dictionary[$kind][$key])) {
                    throw new DomainConflictException(__('game_catalogue.invalid'), 'GAME_CATALOGUE_INVALID');
                }
            }
        }
        if ($scoped) {
            foreach ($this->repository->references($game->id) as $entry) {
                if ($game->active && $entry->active) {
                    $options[$entry->kind][] = ['key' => $entry->key, 'name' => $entry->name];
                }
            }
        }

        return $options;
    }

    /** Validate stored data independently of the model's casting contract.
     * @return array{platforms: array<string, list<string>>, regions: list<string>}
     */
    private function definition(GameCatalogueEntry $game): array
    {
        $raw = $game->getRawOriginal('definition');
        try {
            $data = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $data = null;
        }
        if (! is_array($data) || array_diff(array_keys($data), ['platforms', 'regions']) !== []
            || ! is_array($data['platforms'] ?? null) || count($data['platforms']) > 32
            || ! is_array($data['regions'] ?? null) || ! array_is_list($data['regions']) || count($data['regions']) > 32) {
            throw new DomainConflictException(__('game_catalogue.invalid'), 'GAME_CATALOGUE_INVALID');
        }
        $platforms = [];
        foreach ($data['platforms'] as $key => $values) {
            if (! is_string($key) || ! is_array($values) || ! array_is_list($values) || count($values) > 32) {
                throw new DomainConflictException(__('game_catalogue.invalid'), 'GAME_CATALOGUE_INVALID');
            }
            $providers = [];
            foreach ($values as $value) {
                if (! is_string($value)) {
                    throw new DomainConflictException(__('game_catalogue.invalid'), 'GAME_CATALOGUE_INVALID');
                }
                $providers[] = $value;
            }
            $platforms[$key] = $providers;
        }
        $regions = [];
        foreach ($data['regions'] as $value) {
            if (! is_string($value)) {
                throw new DomainConflictException(__('game_catalogue.invalid'), 'GAME_CATALOGUE_INVALID');
            }
            $regions[] = $value;
        }

        return ['platforms' => $platforms, 'regions' => $regions];
    }

    /** @param array<string, list<array{key: string, name: string}>> $options
     * @return array<string, list<array{key: string, name: string}>>
     */
    public function restrict(array $options, ?OrganizationGame $setting): array
    {
        foreach ($options as $kind => $references) {
            if ($setting !== null && array_key_exists($kind, $setting->restrictions)) {
                $options[$kind] = array_values(array_filter($references, fn (array $entry): bool => in_array($entry['key'], $setting->restrictions[$kind], true)));
            }
        }

        return $options;
    }

    /** @return list<array{key: string, name: string, regions: list<array{key: string, name: string}>, platforms: list<array{key: string, name: string, providers: list<array{key: string, name: string}>}>}> */
    public function profiles(string $organizationId): array
    {
        $entries = $this->snapshot();
        $settings = [];
        foreach ($this->repository->settings($organizationId) as $setting) {
            $settings[$setting->game_id] = $setting;
        }
        $result = [];
        foreach ($entries as $game) {
            if ($game->kind !== 'game' || ! $game->active || ! ($settings[$game->id]->enabled ?? $game->default_enabled)) {
                continue;
            }
            $options = $this->restrict($this->options($game, false, $entries), $settings[$game->id] ?? null);
            $platforms = [];
            foreach ($options['platform'] as $platform) {
                $providers = array_values(array_filter($options['provider'], fn (array $provider): bool => in_array($provider['key'], $game->definition['platforms'][$platform['key']] ?? [], true)));
                $platforms[] = [...$platform, 'providers' => $providers];
            }
            if ($platforms !== [] && $options['region'] !== []) {
                $result[] = ['key' => $game->key, 'name' => $game->name, 'regions' => $options['region'], 'platforms' => $platforms];
            }
        }

        return $result;
    }

    public function assertReference(string $organizationId, string $gameId, string $kind, string $key): void
    {
        if ($this->database->transactionLevel() > 0) {
            $this->repository->revision(true);
        }
        $game = $this->repository->find($gameId);
        $setting = $this->repository->setting($organizationId, $game->id);
        if ($game->kind === 'game' && $game->active && ($setting->enabled ?? $game->default_enabled)
            && in_array($key, array_column($this->restrict($this->options($game), $setting)[$kind] ?? [], 'key'), true)) {
            return;
        }
        throw new DomainConflictException(__('game_profiles.context_unavailable'), 'GAME_CONTEXT_UNAVAILABLE');
    }
}
