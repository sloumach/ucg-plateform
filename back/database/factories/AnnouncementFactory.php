<?php

namespace Database\Factories;

use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'title' => 'Annonce de démonstration', 'body' => 'Message interne.',
            'target_type' => 'organization', 'status' => 'draft', 'comments_enabled' => true,
            'created_by' => UserFactory::new(), 'version' => (string) Str::uuid()];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'published', 'published_at' => now(), 'published_by' => $attributes['created_by']]);
    }
}
