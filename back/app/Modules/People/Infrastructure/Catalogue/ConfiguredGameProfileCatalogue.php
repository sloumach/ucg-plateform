<?php

namespace App\Modules\People\Infrastructure\Catalogue;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use Illuminate\Contracts\Config\Repository;

final readonly class ConfiguredGameProfileCatalogue implements GameProfileCatalogue
{
    public function __construct(private Repository $config) {}

    public function entries(string $organizationId): array
    {
        $games = $this->dictionary('games', 100, true);
        $platforms = $this->dictionary('platforms', 32);
        $providers = $this->dictionary('providers', 32);
        $regions = $this->dictionary('regions', 32);
        $overrides = $this->config->get('game_profiles.tenant_enabled_games', []);
        if (! is_array($overrides)) {
            $this->invalid();
        }
        $enabled = array_key_exists(strtolower($organizationId), $overrides)
            ? $overrides[strtolower($organizationId)] : $this->config->get('game_profiles.enabled_games', []);
        $enabled = $this->keys($enabled, 100, 64);
        $result = [];
        foreach ($enabled as $gameKey) {
            $game = $games[$gameKey] ?? null;
            if ($game === null || ! $game['active']) {
                continue;
            }
            $available = [];
            foreach ($game['platforms'] as $platformKey => $providerKeys) {
                $platform = $platforms[$platformKey] ?? null;
                if ($platform === null || ! $platform['active']) {
                    continue;
                }
                $availableProviders = [];
                foreach ($providerKeys as $providerKey) {
                    $provider = $providers[$providerKey] ?? null;
                    if ($provider !== null && $provider['active']) {
                        $availableProviders[] = ['key' => $providerKey, 'name' => $provider['name']];
                    }
                }
                $available[] = ['key' => $platformKey, 'name' => $platform['name'], 'providers' => $availableProviders];
            }
            $availableRegions = [];
            foreach ($game['regions'] ?? array_keys($regions) as $regionKey) {
                $region = $regions[$regionKey] ?? null;
                if ($region !== null && $region['active']) {
                    $availableRegions[] = ['key' => $regionKey, 'name' => $region['name']];
                }
            }
            if ($available !== [] && $availableRegions !== []) {
                $result[] = ['key' => $gameKey, 'name' => $game['name'], 'regions' => $availableRegions, 'platforms' => $available];
            }
        }

        return $result;
    }

    public function assertAvailable(string $organizationId, string $gameKey, string $platformKey, string $regionKey, ?string $providerKey = null): void
    {
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

    /** @return array<string, array{name: string, active: bool, platforms: array<string, list<string>>, regions: list<string>|null}> */
    private function dictionary(string $name, int $limit, bool $withPlatforms = false): array
    {
        $value = $this->config->get('game_profiles.'.$name, []);
        if (! is_array($value) || count($value) > $limit) {
            $this->invalid();
        }
        $result = [];
        foreach ($value as $key => $definition) {
            if (! is_string($key) || ! $this->validKey($key, $name === 'games' ? 64 : 32)
                || ! is_array($definition) || ! is_string($definition['name'] ?? null)
                || trim($definition['name']) === '' || mb_strlen($definition['name']) > 120
                || (array_key_exists('active', $definition) && ! is_bool($definition['active']))) {
                $this->invalid();
            }
            $bindings = [];
            if ($withPlatforms) {
                $mapping = $definition['platforms'] ?? null;
                if (! is_array($mapping) || count($mapping) > 32) {
                    $this->invalid();
                }
                foreach ($mapping as $platform => $providerKeys) {
                    if (! is_string($platform) || ! $this->validKey($platform, 32)) {
                        $this->invalid();
                    }
                    $bindings[$platform] = $this->keys($providerKeys, 32, 32);
                }
            }
            $regionKeys = $withPlatforms && array_key_exists('regions', $definition) ? $this->keys($definition['regions'], 32, 32) : null;
            $result[$key] = ['name' => $definition['name'], 'active' => $definition['active'] ?? true, 'platforms' => $bindings, 'regions' => $regionKeys];
        }

        return $result;
    }

    /** @return list<string> */
    private function keys(mixed $value, int $limit, int $length): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $limit) {
            $this->invalid();
        }
        $result = [];
        foreach ($value as $key) {
            if (! is_string($key) || ! $this->validKey($key, $length) || in_array($key, $result, true)) {
                $this->invalid();
            }
            $result[] = $key;
        }

        return $result;
    }

    private function validKey(string $key, int $length): bool
    {
        return strlen($key) <= $length && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key) === 1;
    }

    private function invalid(): never
    {
        throw new DomainConflictException(__('game_profiles.catalogue_invalid'), 'GAME_CATALOGUE_INVALID');
    }
}
