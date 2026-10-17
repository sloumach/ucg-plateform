<?php

namespace Database\Factories;

use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarChange;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CalendarChange> */
class CalendarChangeFactory extends Factory
{
    protected $model = CalendarChange::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['activity_id' => CalendarActivity::factory(),
            'organization_id' => fn (array $a) => CalendarActivity::query()->whereKey($a['activity_id'])->firstOrFail()->organization_id,
            'actor_user_id' => fn (array $a) => CalendarActivity::query()->whereKey($a['activity_id'])->firstOrFail()->created_by,
            'activity_version' => fn (array $a) => CalendarActivity::query()->whereKey($a['activity_id'])->firstOrFail()->version,
            'previous_version' => (string) Str::uuid(), 'kind' => 'rescheduled', 'title' => 'Activité déplacée', 'reason' => 'Changement opérationnel confirmé.',
            'previous_starts_at' => now()->addDay()->startOfHour(), 'previous_ends_at' => now()->addDay()->startOfHour()->addHour(), 'previous_timezone' => 'UTC',
            'starts_at' => now()->addDay()->startOfHour()->addHours(2), 'ends_at' => now()->addDay()->startOfHour()->addHours(3), 'origin_timezone' => 'UTC'];
    }
}
