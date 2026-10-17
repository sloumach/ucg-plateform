<?php

namespace App\Modules\Scheduling\Domain;

final readonly class CalendarAudience
{
    /** @param list<string> $teamIds */
    public function __construct(public int $userId, public ?string $personId, public array $teamIds) {}
}
