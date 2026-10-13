<?php

namespace App\Modules\Teams\Infrastructure\Persistence;

use App\Modules\Teams\Domain\Contracts\TeamMembershipRepository;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Teams\Domain\Models\TeamMovement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentTeamMembershipRepository implements TeamMembershipRepository
{
    /** @return Builder<TeamMembership> */
    private function query(string $org): Builder
    {
        return TeamMembership::query()->where('organization_id', strtolower($org));
    }

    public function paginate(string $org, string $teamId, array $filters): LengthAwarePaginator
    {
        $query = $this->query($org)->where('team_id', $teamId);
        foreach (['person_id', 'role_key', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['at'])) {
            $this->effective($query, CarbonImmutable::parse($filters['at'])->utc());
        }

        return $query->orderByDesc('starts_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function movements(string $org, string $teamId, int $perPage, int $page): LengthAwarePaginator
    {
        return TeamMovement::query()->where('organization_id', $org)->with(['team:id,name', 'destinationTeam:id,name'])->where(fn (Builder $query) => $query->where('team_id', $teamId)->orWhere('destination_team_id', $teamId))
            ->orderByDesc('created_at')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(string $org, string $teamId, string $id): TeamMembership
    {
        return $this->query($org)->where('team_id', $teamId)->lockForUpdate()->findOrFail(strtolower($id));
    }

    public function count(string $org, string $personId): int
    {
        return $this->query($org)->where('person_id', $personId)->count();
    }

    public function hasLive(string $org, string $teamId, CarbonImmutable $at): bool
    {
        return $this->query($org)->where('team_id', $teamId)->where('status', 'active')->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))->exists();
    }

    public function overlaps(string $org, string $teamId, string $personId, string $role, CarbonImmutable $start, ?CarbonImmutable $end): bool
    {
        $roles = in_array($role, ['player', 'substitute'], true) ? ['player', 'substitute'] : [$role];

        return $this->query($org)->where('team_id', $teamId)->where('person_id', $personId)->whereIn('role_key', $roles)->where('status', '!=', 'cancelled')
            ->when($end !== null, fn (Builder $query) => $query->where('starts_at', '<', $end))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $start))->exists();
    }

    public function save(?TeamMembership $membership, array $values): TeamMembership
    {
        $membership ??= new TeamMembership;
        $membership->forceFill($values)->save();

        return $membership;
    }

    public function record(array $values): TeamMovement
    {
        $event = new TeamMovement;
        $event->forceFill($values)->save();

        return $event;
    }

    public function openForPerson(string $org, string $personId, CarbonImmutable $at): iterable
    {
        return $this->query($org)->where('person_id', $personId)->where('status', 'active')->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))->lazyById(100);
    }

    public function effectiveForAccount(string $org, string $teamId, string $personId, CarbonImmutable $at): array
    {
        $query = $this->query($org)->where('team_id', $teamId)->where('person_id', $personId);
        $this->effective($query, $at);

        return array_values($query->orderBy('starts_at')->limit(1000)->get()->all());
    }

    /** @param Builder<TeamMembership> $query */
    private function effective(Builder $query, CarbonImmutable $at): void
    {
        $query->where('status', '!=', 'cancelled')->where('starts_at', '<=', $at)->where(fn (Builder $period) => $period->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}
