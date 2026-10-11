<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

final readonly class GameProfileFreshness
{
    private const int MAX_DAYS = 3650;

    public function __construct(private Repository $config) {}

    public function assertConfigured(): void
    {
        $this->days();
    }

    public function status(?CarbonImmutable $observedAt): string
    {
        $days = $this->days();

        return $observedAt === null ? 'unknown' : (now()->lessThanOrEqualTo($observedAt->addDays($days)) ? 'recent' : 'stale');
    }

    private function days(): int
    {
        $days = $this->config->get('game_profiles.freshness_days', 30);
        if (! is_int($days) || $days < 1 || $days > self::MAX_DAYS) {
            throw new DomainConflictException(__('game_profiles.catalogue_invalid'), 'GAME_CATALOGUE_INVALID');
        }

        return $days;
    }
}
