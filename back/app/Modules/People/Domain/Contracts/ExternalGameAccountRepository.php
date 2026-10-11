<?php

namespace App\Modules\People\Domain\Contracts;

use App\Modules\People\Domain\Models\ExternalGameAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ExternalGameAccountRepository
{
    public function find(string $organizationId, string $profileId, string $id, bool $lock = false): ExternalGameAccount;

    /** @return LengthAwarePaginator<int, ExternalGameAccount> */
    public function paginate(string $organizationId, string $profileId, int $perPage, int $page): LengthAwarePaginator;

    public function countForProfile(string $organizationId, string $profileId): int;

    /** @param array<string, mixed> $attributes */
    public function save(?ExternalGameAccount $account, array $attributes): ExternalGameAccount;
}
