<?php

namespace Database\Factories;

use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarReservation;
use App\Modules\Scheduling\Domain\Models\CalendarResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarReservation> */
class CalendarReservationFactory extends Factory
{
    protected $model = CalendarReservation::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['activity_id' => CalendarActivity::factory(), 'organization_id' => fn (array $v) => CalendarActivity::query()->whereKey($v['activity_id'])->firstOrFail()->organization_id,
            'resource_id' => fn (array $v) => CalendarResource::factory()->create(['organization_id' => $v['organization_id']])->id, 'created_at' => now()];
    }
}
