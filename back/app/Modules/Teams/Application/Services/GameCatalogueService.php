<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\PlatformCatalogueGuard;
use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class GameCatalogueService
{
    public function __construct(private GameCatalogueRepository $repository, private PlatformCatalogueGuard $guard,
        private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, GameCatalogueEntry>
     */
    public function entries(array $filters): LengthAwarePaginator
    {
        $values = GameCatalogueInput::validate($this->validator, $filters, GameCatalogueInput::pageRules(true));
        $kind = (string) ($values['kind'] ?? 'game');
        $gameId = isset($values['game_id']) ? strtolower((string) $values['game_id']) : null;
        if (in_array($kind, GameCatalogueInput::SCOPED, true) !== ($gameId !== null)) {
            throw ValidationException::withMessages(['game_id' => __('game_catalogue.scope_invalid')]);
        }
        if ($gameId !== null) {
            $this->game($gameId);
        }

        return $this->repository->paginate($kind, $gameId, (int) ($values['per_page'] ?? 10), (int) ($values['page'] ?? 1));
    }

    public function version(): string
    {
        return $this->repository->revision();
    }

    public function canManage(int $actorId): bool
    {
        return $this->guard->allows($actorId);
    }

    public function find(string $id): GameCatalogueEntry
    {
        return $this->repository->find($id);
    }

    public function game(string $id): GameCatalogueEntry
    {
        $entry = $this->repository->find($id);
        if ($entry->kind !== 'game') {
            throw ValidationException::withMessages(['game_id' => __('game_catalogue.scope_invalid')]);
        }

        return $entry;
    }

    /** @param array<string, mixed> $input */
    public function save(int $actorId, string $requestId, array $input, ?string $id = null): GameCatalogueEntry
    {
        $values = GameCatalogueInput::validate($this->validator, $input, GameCatalogueInput::entryRules($id === null));
        $this->validator->make(['actor_id' => $actorId, 'request_id' => $requestId],
            ['actor_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid']], GameCatalogueInput::messages())->validate();
        try {
            return $this->database->transaction(function () use ($actorId, $requestId, $values, $id): GameCatalogueEntry {
                $this->guard->lock($actorId);
                $this->assertVersion((string) $values['catalogue_version'], true);
                $entry = $id === null ? null : $this->repository->find($id);
                if ($entry !== null && $entry->version !== $values['version']) {
                    throw new DomainConflictException(__('game_catalogue.stale'), 'GAME_CATALOGUE_VERSION_STALE');
                }
                $kind = $entry->kind ?? (string) $values['kind'];
                $gameId = $entry->game_id ?? (isset($values['game_id']) ? strtolower((string) $values['game_id']) : null);
                if (in_array($kind, GameCatalogueInput::SCOPED, true) !== ($gameId !== null)) {
                    throw ValidationException::withMessages(['game_id' => __('game_catalogue.scope_invalid')]);
                }
                if ($gameId !== null) {
                    $this->game($gameId);
                }
                $scope = $gameId ?? 'global';
                $key = $entry->key ?? (string) $values['key'];
                if (in_array($kind, ['platform', 'provider', 'region'], true) && strlen($key) > 32) {
                    throw ValidationException::withMessages(['key' => __('game_catalogue.reference_invalid')]);
                }
                $limit = $kind === 'game' ? GameCatalogueInput::MAX_GAMES
                    : ($gameId === null ? GameCatalogueInput::MAX_GLOBAL_REFERENCES : GameCatalogueInput::MAX_GAME_REFERENCES);
                if ($entry === null && $this->repository->count($kind, $scope) >= $limit) {
                    throw new DomainConflictException(__('game_catalogue.limit'), 'GAME_CATALOGUE_LIMIT_REACHED');
                }
                $definition = $values['definition'] ?? null;
                if ($kind === 'game') {
                    $this->validateDefinition($definition);
                } elseif ($definition !== null) {
                    throw ValidationException::withMessages(['definition' => __('game_catalogue.reference_invalid')]);
                }
                $before = $entry === null ? [] : $this->state($entry);
                $saved = $this->repository->save($entry, ['name' => trim((string) $values['name']), 'active' => (bool) $values['active'],
                    'definition' => $definition, 'version' => (string) Str::uuid(),
                    ...($entry === null ? ['kind' => $kind, 'key' => $key, 'game_id' => $gameId, 'scope_key' => $scope, 'default_enabled' => false] : [])]);
                $revision = $this->repository->advanceRevision();
                $this->repository->audit(['id' => (string) Str::uuid(), 'actor_user_id' => $actorId, 'entry_id' => $saved->id,
                    'request_id' => $requestId, 'catalogue_version' => $revision, 'before' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after' => json_encode($this->state($saved), JSON_THROW_ON_ERROR), 'created_at' => now()]);

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('game_catalogue.duplicate'), 'GAME_CATALOGUE_ALREADY_EXISTS');
        }
    }

    public function assertVersion(string $expected, bool $lock = false): void
    {
        if ($this->repository->revision($lock) !== $expected) {
            throw new DomainConflictException(__('game_catalogue.stale'), 'GAME_CATALOGUE_VERSION_STALE');
        }
    }

    public function validateDefinition(mixed $definition): void
    {
        if (! is_array($definition) || array_diff(array_keys($definition), ['platforms', 'regions']) !== []
            || ! is_array($definition['platforms'] ?? null) || count($definition['platforms']) > 32
            || ! is_array($definition['regions'] ?? null) || ! array_is_list($definition['regions']) || count($definition['regions']) > 32) {
            throw ValidationException::withMessages(['definition' => __('game_catalogue.reference_invalid')]);
        }
        $references = [];
        foreach ($this->repository->snapshot() as $entry) {
            if ($entry->kind !== 'game') {
                $references[$entry->kind][] = $entry->key;
            }
        }
        foreach ($definition['platforms'] as $platform => $providers) {
            if (! is_string($platform) || ! in_array($platform, $references['platform'] ?? [], true)
                || ! is_array($providers) || ! array_is_list($providers) || count($providers) > 32
                || count(array_unique($providers, SORT_REGULAR)) !== count($providers)
                || array_diff($providers, $references['provider'] ?? []) !== []) {
                throw ValidationException::withMessages(['definition.platforms' => __('game_catalogue.reference_invalid')]);
            }
        }
        if (array_diff($definition['regions'], $references['region'] ?? []) !== []) {
            throw ValidationException::withMessages(['definition.regions' => __('game_catalogue.reference_invalid')]);
        }
    }

    /** @return array<string, mixed> */
    public function project(GameCatalogueEntry $entry): array
    {
        return ['id' => $entry->id, 'kind' => $entry->kind, 'key' => $entry->key, 'name' => $entry->name, 'game_id' => $entry->game_id,
            'active' => $entry->active, 'definition' => $entry->definition, 'version' => $entry->version];
    }

    /** Minimal metadata audit; no labels, tenant data or arbitrary request fields.
     * @return array<string, mixed>
     */
    private function state(GameCatalogueEntry $entry): array
    {
        return ['kind' => $entry->kind, 'key' => $entry->key, 'game_id' => $entry->game_id, 'active' => $entry->active,
            'version' => $entry->version, 'definition' => $entry->definition];
    }
}
