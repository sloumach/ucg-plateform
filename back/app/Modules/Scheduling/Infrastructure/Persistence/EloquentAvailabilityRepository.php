<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\Contracts\AvailabilityRepository;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\AvailabilityRevision;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class EloquentAvailabilityRepository implements AvailabilityRepository
{
    public function paginate(string $org, ?string $personId, CarbonImmutable $from, CarbonImmutable $to, array $filters): LengthAwarePaginator
    {
        $query = $this->query($org, $personId)->where('starts_at', '<', $to)->where('ends_at', '>', $from);
        if (! ($filters['collective'] ?? false) && $personId === null) {
            $query->whereRaw('1=0');
        }
        $query->when($filters['cancelled'] ?? false, fn (Builder $q) => $q->whereNotNull('cancelled_at'), fn (Builder $q) => $q->whereNull('cancelled_at'));
        foreach (['person_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, strtolower($filters[$field]));
            }
        }

        return $query->orderBy('starts_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $id, ?string $personId, bool $lock = false): Availability
    {
        $query = $this->query($org, $personId);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function overlaps(string $org, string $personId, CarbonImmutable $from, CarbonImmutable $to, ?string $except): bool
    {
        return $this->query($org, $personId)->whereNull('cancelled_at')->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except))->exists();
    }

    public function save(?Availability $record, array $attributes, int $actorId, string $action): Availability
    {
        $record ??= new Availability;
        $record->forceFill($attributes)->save();
        // Copy the stored ciphertext: history preserves the exact state without duplicating plaintext.
        $snapshot = array_intersect_key($record->getRawOriginal(), array_flip(['organization_id', 'person_id', 'status', 'comment', 'starts_at', 'ends_at', 'origin_timezone', 'cancelled_at', 'version', 'number']));
        AvailabilityRevision::query()->insert([...$snapshot, 'id' => (string) Str::uuid(), 'availability_id' => $record->id,
            'action' => $action, 'actor_user_id' => $actorId, 'created_at' => now()]);

        return $record;
    }

    public function history(Availability $record, int $page, int $perPage): LengthAwarePaginator
    {
        return AvailabilityRevision::query()->where('organization_id', $record->organization_id)->where('availability_id', $record->id)
            ->orderByDesc('number')->paginate($perPage, ['*'], 'page', $page);
    }

    /** @return Builder<Availability> */
    private function query(string $org, ?string $personId): Builder
    {
        return Availability::query()->where('organization_id', $org)->when($personId !== null, fn (Builder $q) => $q->where('person_id', $personId));
    }
}
