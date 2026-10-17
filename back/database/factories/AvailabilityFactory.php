<?php

namespace Database\Factories;

use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Availability> */
class AvailabilityFactory extends Factory
{
    protected $model = Availability::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'person_id' => fn (array $v) => Person::factory()->create(['organization_id' => $v['organization_id']])->id,
            'status' => 'available', 'comment' => null, 'starts_at' => '2026-10-18 08:00:00', 'ends_at' => '2026-10-18 09:00:00', 'origin_timezone' => 'Europe/Paris',
            'cancelled_at' => null, 'version' => (string) Str::uuid(), 'number' => 1,
            'created_by' => fn (array $v) => Organization::query()->whereKey($v['organization_id'])->firstOrFail()->owner_user_id];
    }
}
