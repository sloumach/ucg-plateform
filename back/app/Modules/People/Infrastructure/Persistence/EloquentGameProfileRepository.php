<?php

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Domain\Contracts\GameProfileRepository;
use App\Modules\People\Domain\Models\GameProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class EloquentGameProfileRepository implements GameProfileRepository
{
    public function find(string $organizationId, string $personId, string $id, bool $lock = false): GameProfile
    {
        if (! Str::isUuid($organizationId) || ! Str::isUuid($personId) || ! Str::isUuid($id)) {
            throw new ModelNotFoundException;
        }

        return GameProfile::query()->forPerson($organizationId, $personId)->withCount('externalAccounts')
            ->when($lock, fn ($query) => $query->lockForUpdate())->whereKey(strtolower($id))->firstOrFail();
    }

    public function paginate(string $organizationId, string $personId, int $perPage, int $page): LengthAwarePaginator
    {
        return GameProfile::query()->forPerson($organizationId, $personId)->withCount('externalAccounts')
            ->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function countForPerson(string $organizationId, string $personId): int
    {
        return GameProfile::query()->forPerson($organizationId, $personId)->count();
    }

    public function save(?GameProfile $profile, array $attributes): GameProfile
    {
        $profile ??= new GameProfile;
        $profile->forceFill($attributes)->save();

        return $profile->loadCount('externalAccounts');
    }
}
