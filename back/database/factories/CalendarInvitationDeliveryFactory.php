<?php

namespace Database\Factories;

use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use App\Modules\Scheduling\Domain\Models\CalendarInvitationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CalendarInvitationDelivery> */
class CalendarInvitationDeliveryFactory extends Factory
{
    protected $model = CalendarInvitationDelivery::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['invitation_id' => CalendarInvitation::factory(),
            'organization_id' => fn (array $a) => CalendarInvitation::query()->whereKey($a['invitation_id'])->firstOrFail()->organization_id,
            'kind' => 'invitation', 'offset_minutes' => 0, 'run_id' => (string) Str::uuid(), 'created_at' => now()];
    }
}
