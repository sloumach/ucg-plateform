<?php

namespace App\Modules\Media\Domain\Contracts;

use App\Modules\Media\Domain\Models\MediaItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MediaRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, MediaItem>
     */
    public function paginate(string $organizationId, array $filters, bool $editor): LengthAwarePaginator;

    public function find(string $organizationId, string $id, bool $editor, bool $lock = false): MediaItem;

    /** @param array<string, mixed> $attributes */
    public function save(?MediaItem $record, array $attributes): MediaItem;
}
