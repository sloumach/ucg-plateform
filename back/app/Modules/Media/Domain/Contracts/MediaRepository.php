<?php

namespace App\Modules\Media\Domain\Contracts;

use App\Modules\Media\Domain\Models\MediaItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as Query;

/** @phpstan-type MediaScopes array<string, array{visible: Query|null, shared: Query|null, scrim: Query|null}> */
interface MediaRepository
{
    /** @param array<string, mixed> $filters
     * @param  MediaScopes  $contexts
     * @return LengthAwarePaginator<int, MediaItem>
     */
    public function paginate(string $organizationId, array $filters, bool $editor, array $contexts = []): LengthAwarePaginator;

    /** @param MediaScopes $contexts */
    public function find(string $organizationId, string $id, bool $editor, bool $lock = false, array $contexts = []): MediaItem;

    public function bindContext(MediaItem $item, string $column, string $id): void;

    /** @param array<string, mixed> $attributes */
    public function save(?MediaItem $record, array $attributes): MediaItem;
}
