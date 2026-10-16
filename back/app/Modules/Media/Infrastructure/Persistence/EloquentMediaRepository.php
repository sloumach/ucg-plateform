<?php

namespace App\Modules\Media\Infrastructure\Persistence;

use App\Modules\Media\Domain\Contracts\MediaRepository;
use App\Modules\Media\Domain\Models\MediaItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentMediaRepository implements MediaRepository
{
    public function paginate(string $organizationId, array $filters, bool $editor): LengthAwarePaginator
    {
        $query = $this->visible($organizationId, $editor);
        if ($editor && ($filters['archived'] ?? false)) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }
        foreach (['kind', 'status', 'team_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['q'])) {
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['q'])));
            $query->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$prefix.'%']);
        }

        return $query->orderByDesc('created_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $organizationId, string $id, bool $editor, bool $lock = false): MediaItem
    {
        $query = $this->visible($organizationId, $editor);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function save(?MediaItem $record, array $attributes): MediaItem
    {
        $record ??= new MediaItem;
        $record->forceFill($attributes)->save();

        return $record;
    }

    /** @return Builder<MediaItem> */
    private function visible(string $organizationId, bool $editor): Builder
    {
        $query = MediaItem::query()->where('organization_id', strtolower($organizationId));
        if (! $editor) {
            $query->whereNull('archived_at')->whereIn('visibility', ['organization', 'public'])
                ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
        }

        return $query;
    }
}
