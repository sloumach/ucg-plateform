<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\Contracts\MfaRepository;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\DatabaseManager;

final readonly class EloquentMfaRepository implements MfaRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function find(int $userId): User
    {
        return User::query()->findOrFail($userId);
    }

    public function lock(int $userId): User
    {
        return User::query()->lockForUpdate()->findOrFail($userId);
    }

    public function save(User $user): void
    {
        $user->save();
    }

    public function revokeOtherSessions(int $userId, string $currentSessionId): void
    {
        if (config('session.driver') === 'database') {
            $this->database->connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $userId)->where('id', '<>', $currentSessionId)->delete();
        }
    }
}
