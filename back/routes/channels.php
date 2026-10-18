<?php

use App\Modules\Competition\Presentation\Broadcasting\MatchScoreChannel;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'users.{userId}',
    static fn (User $user, int $userId): bool => (int) $user->getKey() === $userId,
);

Broadcast::channel('tenants.{tenantId}.matches.{matchId}', MatchScoreChannel::class);
