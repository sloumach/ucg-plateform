<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Application\Contracts\MatchSchedule;
use App\Modules\Scheduling\Domain\CalendarTime;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class MatchScheduleService implements MatchSchedule
{
    public function period(string $start, string $end, string $timezone): array
    {
        try {
            $starts = CalendarTime::instant($start, $timezone);
            $ends = CalendarTime::instant($end, $timezone);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['starts_at' => __('matches.period')]);
        }
        $duration = $starts->diffInSeconds($ends, false);
        if ($duration <= 0 || $duration > CalendarTime::MAX_DURATION_SECONDS) {
            throw ValidationException::withMessages(['ends_at' => __('matches.period')]);
        }

        return ['starts_at' => $starts, 'ends_at' => $ends, 'origin_timezone' => $timezone];
    }
}
