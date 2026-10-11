<?php

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Domain\Contracts\ExternalGameAccountRepository;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class EloquentExternalGameAccountRepository implements ExternalGameAccountRepository
{
    public function find(string $organizationId, string $profileId, string $id, bool $lock = false): ExternalGameAccount
    {
        if (! Str::isUuid($organizationId) || ! Str::isUuid($profileId) || ! Str::isUuid($id)) {
            throw new ModelNotFoundException;
        }

        return ExternalGameAccount::query()->forProfile($organizationId, $profileId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->whereKey(strtolower($id))->firstOrFail();
    }

    public function paginate(string $organizationId, string $profileId, int $perPage, int $page): LengthAwarePaginator
    {
        return ExternalGameAccount::query()->forProfile($organizationId, $profileId)
            ->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function countForProfile(string $organizationId, string $profileId): int
    {
        return ExternalGameAccount::query()->forProfile($organizationId, $profileId)->count();
    }

    public function save(?ExternalGameAccount $account, array $attributes): ExternalGameAccount
    {
        $account ??= new ExternalGameAccount;
        $account->forceFill($attributes)->save();

        return $account;
    }
}
