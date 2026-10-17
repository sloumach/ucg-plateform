<?php

namespace App\Modules\Scheduling\Application\Data;

final readonly class CalendarConflictReport
{
    /** @param list<array<string,mixed>> $conflicts
     * @param  array<string,mixed>  $booking
     */
    public function __construct(public array $conflicts, public bool $truncated, public bool $availabilityChecked, public array $booking, public string $reviewToken = '', public bool $canOverride = false) {}
}
