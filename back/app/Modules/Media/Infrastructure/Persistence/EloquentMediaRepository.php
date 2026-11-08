<?php

namespace App\Modules\Media\Infrastructure\Persistence;

use App\Modules\Media\Domain\Contracts\MediaRepository;
use App\Modules\Media\Domain\Models\MediaContext;
use App\Modules\Media\Domain\Models\MediaItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** @phpstan-import-type MediaScopes from MediaRepository */
final readonly class EloquentMediaRepository implements MediaRepository
{
    public function partnershipIds(string $org, array $contexts): \Illuminate\Database\Query\Builder
    {
        return $this->visible($org, false, $contexts)->where('status', '!=', 'unavailable')->select('media_items.id')->toBase();
    }

    public function partnershipChoices(string $org, \Illuminate\Database\Query\Builder $visible, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = MediaItem::query()->where('organization_id', $org)->whereIn('id', $visible)->orderBy('created_at')->orderBy('id')
            ->paginate($perPage, ['id', 'title', 'version'], 'page', $page);

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn (MediaItem $row): array => ['id' => $row->id, 'label' => $row->title, 'version' => $row->version], $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage());
    }

    public function partnershipMetadata(string $org, array $ids, \Illuminate\Database\Query\Builder $visible): array
    {
        return MediaItem::query()->where('organization_id', $org)->whereIn('id', $ids)->whereIn('id', $visible)->get(['id', 'title', 'version'])
            ->mapWithKeys(fn (MediaItem $row): array => [$row->id => ['id' => $row->id, 'label' => $row->title, 'version' => $row->version]])->all();
    }

    public function paginate(string $organizationId, array $filters, bool $editor, array $contexts = []): LengthAwarePaginator
    {
        $query = $this->visible($organizationId, $editor, $contexts);
        if (isset($filters['context_type'], $filters['context_id'])) {
            $column = MediaContext::COLUMNS[$filters['context_type']];
            $query->whereHas('context', fn (Builder $binding) => $binding->where('organization_id', $organizationId)->where($column, strtolower($filters['context_id'])));
        }
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

    public function find(string $organizationId, string $id, bool $editor, bool $lock = false, array $contexts = []): MediaItem
    {
        $query = $this->visible($organizationId, $editor, $contexts);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function save(?MediaItem $record, array $attributes): MediaItem
    {
        $record ??= new MediaItem;
        $record->forceFill($attributes)->save();

        return $record->loadMissing('context');
    }

    public function bindContext(MediaItem $item, string $column, string $id): void
    {
        if (! in_array($column, ['activity_id', 'match_id'], true)) {
            throw new \InvalidArgumentException('Unsupported media context.');
        }
        $binding = new MediaContext;
        $binding->forceFill(['id' => (string) Str::uuid(), 'organization_id' => $item->organization_id,
            'media_id' => $item->id, $column => $id, 'created_at' => now()])->save();
        $item->setRelation('context', $binding);
    }

    /**
     * @param  MediaScopes  $contexts
     * @return Builder<MediaItem>
     */
    private function visible(string $organizationId, bool $editor, array $contexts): Builder
    {
        $query = MediaItem::query()->where('organization_id', strtolower($organizationId))->with('context');
        $query->where(fn (Builder $scope) => $scope->whereDoesntHave('context')->orWhereHas('context', function (Builder $bindings) use ($contexts, $organizationId, $editor): void {
            $bindings->where('organization_id', $organizationId)->where(function (Builder $allowed) use ($contexts, $editor): void {
                $allowed->whereRaw('1 = 0');
                foreach ($contexts as $column => $rules) {
                    if ($rules['visible'] === null) {
                        continue;
                    }
                    $allowed->orWhere(function (Builder $source) use ($column, $rules, $editor): void {
                        $source->whereIn($column, clone $rules['visible']);
                        if (! $editor) {
                            if ($rules['shared'] === null || $rules['scrim'] === null) {
                                $source->whereRaw('1 = 0');

                                return;
                            }
                            $source->whereIn($column, clone $rules['shared'])->where(fn (Builder $scrim) => $scrim
                                ->whereNotIn($column, clone $rules['scrim'])->orWhere(fn (Builder $release) => $release
                                ->where('media_items.is_scrim', true)->whereNotNull('media_items.publish_at')));
                        }
                    });
                }
            });
        }));
        if (! $editor) {
            $query->whereNull('archived_at')->whereIn('visibility', ['organization', 'public'])
                ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
        }

        return $query;
    }
}
