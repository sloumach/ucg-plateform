<?php

namespace App\Modules\Competition\Application\Contracts;

interface MatchScorePublisher
{
    public function changed(string $organizationId, string $matchId): void;
}
