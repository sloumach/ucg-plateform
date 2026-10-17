<?php

namespace Database\Factories;

use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarChange;
use App\Modules\Scheduling\Domain\Models\CalendarChangeRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarChangeRecipient> */
class CalendarChangeRecipientFactory extends Factory
{
    protected $model = CalendarChangeRecipient::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['change_id' => CalendarChange::factory(),
            'organization_id' => fn (array $a) => CalendarChange::query()->whereKey($a['change_id'])->firstOrFail()->organization_id,
            'person_id' => fn (array $a) => Person::factory()->create(['organization_id' => $a['organization_id']])->id,
            'recipient_id' => null];
    }
}
