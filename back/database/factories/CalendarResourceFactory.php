<?php

namespace Database\Factories;

use App\Modules\Scheduling\Domain\Models\CalendarResource;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CalendarResource> */
class CalendarResourceFactory extends Factory
{
    protected $model = CalendarResource::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'key' => strtolower((string) Str::uuid()), 'label' => 'Salle de travail', 'kind' => 'room', 'status' => 'active',
            'version' => (string) Str::uuid(), 'created_by' => fn (array $v) => Organization::query()->whereKey($v['organization_id'])->firstOrFail()->owner_user_id];
    }
}
