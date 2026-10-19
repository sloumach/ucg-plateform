<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Teams\Application\Contracts\MetricGameDirectory;
use Illuminate\Validation\ValidationException;

final readonly class MetricGameDirectoryService implements MetricGameDirectory
{
    public function __construct(private GameCatalogueService $catalogue) {}

    public function version(): string
    {
        return $this->catalogue->version();
    }

    public function lock(string $expected): void
    {
        $this->catalogue->assertVersion(strtolower($expected), true);
    }

    public function resolve(string $game, ?string $mode, ?string $role, ?string $release, bool $active): array
    {
        $parent = $this->catalogue->game($game);
        if ($active && ! $parent->active) {
            throw ValidationException::withMessages(['game_id' => __('metric_catalogue.reference')]);
        }
        $snapshot = ['game_id' => $parent->id, 'game_key' => $parent->key, 'game_name' => $parent->name];
        foreach (['mode' => $mode, 'role' => $role, 'release' => $release] as $kind => $id) {
            $entry = $id === null ? null : $this->catalogue->find($id);
            if ($entry !== null && ($entry->kind !== $kind || $entry->game_id !== $parent->id || ($active && ! $entry->active))) {
                throw ValidationException::withMessages([$kind.'_id' => __('metric_catalogue.reference')]);
            }
            $snapshot[$kind.'_id'] = $entry?->id;
            $snapshot[$kind.'_name'] = $entry?->name;
        }

        return $snapshot;
    }
}
