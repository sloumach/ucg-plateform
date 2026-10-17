<?php

namespace Database\Factories;

use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarConflictOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarConflictOverride> */
class CalendarConflictOverrideFactory extends Factory
{
    protected $model = CalendarConflictOverride::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['activity_id' => CalendarActivity::factory(), 'organization_id' => fn (array $v) => CalendarActivity::query()->whereKey($v['activity_id'])->firstOrFail()->organization_id,
            'activity_version' => fn (array $v) => CalendarActivity::query()->whereKey($v['activity_id'])->firstOrFail()->version,
            'actor_user_id' => fn (array $v) => CalendarActivity::query()->whereKey($v['activity_id'])->firstOrFail()->created_by,
            'reason' => 'Décision opérationnelle explicite', 'snapshot' => ['conflicts' => [], 'availability_checked' => false], 'conflict_count' => 1, 'created_at' => now()];
    }
}
