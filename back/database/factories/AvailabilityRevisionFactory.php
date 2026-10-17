<?php

namespace Database\Factories;

use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\AvailabilityRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AvailabilityRevision> */
class AvailabilityRevisionFactory extends Factory
{
    protected $model = AvailabilityRevision::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['availability_id' => Availability::factory(),
            'organization_id' => fn (array $v) => Availability::query()->whereKey($v['availability_id'])->firstOrFail()->organization_id,
            'person_id' => fn (array $v) => Availability::query()->whereKey($v['availability_id'])->firstOrFail()->person_id,
            'status' => 'available', 'comment' => null, 'starts_at' => '2026-10-18 08:00:00', 'ends_at' => '2026-10-18 09:00:00', 'origin_timezone' => 'Europe/Paris',
            'cancelled_at' => null, 'version' => fn (array $v) => Availability::query()->whereKey($v['availability_id'])->firstOrFail()->version, 'number' => 1, 'action' => 'created',
            'actor_user_id' => fn (array $v) => Availability::query()->whereKey($v['availability_id'])->firstOrFail()->getAttribute('created_by'), 'created_at' => now()];
    }
}
