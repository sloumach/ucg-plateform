<?php

namespace App\Modules\Scheduling\Domain;

use Illuminate\Database\Query\Builder;

final readonly class CalendarAudience
{
    /** @param list<string> $teamIds */
    public function __construct(public int $userId, public ?string $personId, public array $teamIds, public ?Builder $activityIds = null, public ?Builder $explicitActivityIds = null) {}
}
