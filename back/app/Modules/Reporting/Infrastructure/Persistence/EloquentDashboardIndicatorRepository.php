<?php

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Domain\Contracts\DashboardIndicatorRepository;
use App\Modules\Reporting\Domain\Models\DashboardIndicator;
use App\Modules\Reporting\Domain\Models\DashboardIndicatorRevision;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class EloquentDashboardIndicatorRepository implements DashboardIndicatorRepository
{
    public function __construct(private ConnectionInterface $database) {}

    public function generation(string $org, string $card, bool $lock = false): int
    {
        if ($lock) {
            $this->database->table('dashboard_source_versions')->insertOrIgnore(['organization_id' => $org, 'card' => $card, 'generation' => 0]);
        }
        $query = $this->database->table('dashboard_source_versions')->where('organization_id', $org)->where('card', $card);

        return (int) ($lock ? $query->lockForUpdate()->value('generation') : $query->value('generation'));
    }

    public function find(string $org, int $actor, string $id, bool $lock = false): DashboardIndicator
    {
        return DashboardIndicator::query()->where('organization_id', $org)->where('actor_user_id', $actor)->with('current')->when($lock, fn ($q) => $q->lockForUpdate())->whereKey($id)->firstOrFail();
    }

    public function fingerprint(string $org, int $actor, string $fingerprint): ?DashboardIndicator
    {
        return DashboardIndicator::query()->where('organization_id', $org)->where('actor_user_id', $actor)->where('fingerprint', $fingerprint)->with('current')->first();
    }

    public function count(string $org, int $actor): int
    {
        return DashboardIndicator::query()->where('organization_id', $org)->where('actor_user_id', $actor)->count();
    }

    public function save(?DashboardIndicator $record, array $attributes): DashboardIndicator
    {
        $record ??= new DashboardIndicator;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function append(array $attributes): void
    {
        (new DashboardIndicatorRevision)->forceFill($attributes)->save();
    }

    public function history(string $org, string $id, int $page): LengthAwarePaginator
    {
        return DashboardIndicatorRevision::query()->where('organization_id', $org)->where('indicator_id', $id)->orderByDesc('number')->paginate(10, ['*'], 'page', $page);
    }
}
