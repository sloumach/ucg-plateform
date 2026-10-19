<?php

namespace App\Modules\Performance\Infrastructure\Persistence;

use App\Modules\Performance\Domain\Contracts\MetricCatalogueRepository;
use App\Modules\Performance\Domain\Models\MetricDefinition;
use App\Modules\Performance\Domain\Models\MetricRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final readonly class EloquentMetricCatalogueRepository implements MetricCatalogueRepository
{
    public function __construct(private ConnectionInterface $database) {}

    public function state(bool $lock = false): array
    {
        $row = $this->database->table('metric_catalogue_state')->where('id', 1)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($row === null) {
            throw new RuntimeException('Metric catalogue state missing.');
        }

        return ['version' => $row->version, 'number' => (int) $row->number];
    }

    public function count(): int
    {
        return MetricDefinition::query()->count();
    }

    public function find(string $id): MetricDefinition
    {
        return MetricDefinition::query()->with('current')->findOrFail($id);
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        return MetricDefinition::query()->with('current')->when(isset($filters['game_id']), fn (Builder $q) => $q->where('game_id', strtolower($filters['game_id'])))
            ->when(isset($filters['code']), fn (Builder $q) => $q->where('code', $filters['code']))
            ->whereHas('current', function (Builder $q) use ($filters): void {
                foreach (['status', 'availability', 'aggregation', 'mode_id', 'role_id', 'release_id'] as $field) {
                    if (isset($filters[$field])) {
                        $q->where($field, str_ends_with($field, '_id') ? strtolower($filters[$field]) : $filters[$field]);
                    }
                }
            })
            ->orderBy('game_id')->orderBy('code')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function create(array $attributes): MetricDefinition
    {
        $row = new MetricDefinition;
        $row->forceFill($attributes)->save();

        return $row;
    }

    public function append(array $attributes): MetricRevision
    {
        $row = new MetricRevision;
        $row->forceFill($attributes)->save();

        return $row;
    }

    public function history(string $metric, int $page, int $size): LengthAwarePaginator
    {
        return MetricRevision::query()->where('metric_id', $metric)->orderByDesc('number')->paginate($size, ['*'], 'page', $page);
    }

    public function revision(string $metric, string $revision): MetricRevision
    {
        return MetricRevision::query()->where('metric_id', $metric)->findOrFail($revision);
    }
}
