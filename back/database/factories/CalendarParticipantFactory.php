<?php

namespace Database\Factories;

use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarParticipant> */
class CalendarParticipantFactory extends Factory
{
    protected $model = CalendarParticipant::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['activity_id' => CalendarActivity::factory(),
            'organization_id' => fn (array $a) => CalendarActivity::query()->whereKey($a['activity_id'])->firstOrFail()->organization_id,
            'person_id' => fn (array $a) => Person::factory()->create(['organization_id' => $a['organization_id']])->id];
    }
}
