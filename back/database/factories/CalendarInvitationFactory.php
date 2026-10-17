<?php

namespace Database\Factories;

use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CalendarInvitation> */
class CalendarInvitationFactory extends Factory
{
    protected $model = CalendarInvitation::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['activity_id' => CalendarActivity::factory(),
            'organization_id' => fn (array $a) => CalendarActivity::query()->whereKey($a['activity_id'])->firstOrFail()->organization_id,
            'activity_version' => fn (array $a) => CalendarActivity::query()->whereKey($a['activity_id'])->firstOrFail()->version,
            'issued_by' => fn (array $a) => CalendarActivity::query()->whereKey($a['activity_id'])->firstOrFail()->created_by,
            'person_id' => fn (array $a) => Person::factory()->create(['organization_id' => $a['organization_id']])->id,
            'recipient_id' => null, 'response_deadline' => now()->addHours(12), 'reminder_offsets' => [60],
            'response' => 'pending', 'version' => (string) Str::uuid()];
    }
}
