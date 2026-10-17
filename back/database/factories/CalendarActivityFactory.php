<?php

namespace Database\Factories;

use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CalendarActivity> */
class CalendarActivityFactory extends Factory
{
    protected $model = CalendarActivity::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'title' => 'Activité pilote', 'description' => null, 'kind' => 'training',
            'starts_at' => now()->addDay()->startOfHour(), 'ends_at' => now()->addDay()->startOfHour()->addHour(), 'origin_timezone' => 'UTC',
            'visibility' => 'private', 'created_by' => UserFactory::new(), 'version' => (string) Str::uuid()];
    }

    public function shared(): static
    {
        return $this->state(fn (array $a): array => ['visibility' => 'organization', 'sharing_authorized_by' => $a['created_by'], 'sharing_authorized_at' => now()]);
    }
}
