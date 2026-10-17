<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Scheduling\Domain\Models\CalendarFeed;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CalendarFeed> */
class CalendarFeedFactory extends Factory
{
    protected $model = CalendarFeed::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'user_id' => User::factory(), 'token_hash' => hash('sha256', random_bytes(32)),
            'before_days' => 7, 'after_days' => 60, 'expires_at' => now()->addDays(30), 'version' => (string) Str::uuid()];
    }
}
