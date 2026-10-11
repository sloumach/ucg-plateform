<?php

namespace App\Modules\People\Domain\Contracts;

use App\Modules\People\Domain\Models\GameProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface GameProfileRepository
{
    public function find(string $organizationId, string $personId, string $id, bool $lock = false): GameProfile;

    /** @return LengthAwarePaginator<int, GameProfile> */
    public function paginate(string $organizationId, string $personId, int $perPage, int $page): LengthAwarePaginator;

    public function countForPerson(string $organizationId, string $personId): int;

    /** @param array<string, mixed> $attributes */
    public function save(?GameProfile $profile, array $attributes): GameProfile;
}
