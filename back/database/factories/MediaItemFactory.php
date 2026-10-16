<?php

namespace Database\Factories;

use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Teams\Domain\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MediaItem> */
class MediaItemFactory extends Factory
{
    protected $model = MediaItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'organization_id' => fn (array $attributes) => Team::query()->whereKey($attributes['team_id'])->firstOrFail()->organization_id,
            'title' => 'Vidéo de démonstration', 'kind' => 'vod', 'provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=abcdefghijk',
            'status' => 'ended', 'status_observed_at' => now(), 'is_scrim' => false, 'visibility' => 'private', 'publish_at' => null,
            'archived_at' => null, 'created_by' => UserFactory::new(), 'version' => (string) Str::uuid()];
    }
}
