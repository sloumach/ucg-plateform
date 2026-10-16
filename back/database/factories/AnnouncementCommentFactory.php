<?php

namespace Database\Factories;

use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Domain\Models\AnnouncementComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnnouncementComment> */
class AnnouncementCommentFactory extends Factory
{
    protected $model = AnnouncementComment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['announcement_id' => Announcement::factory()->published(),
            'organization_id' => fn (array $a) => Announcement::query()->whereKey($a['announcement_id'])->firstOrFail()->organization_id,
            'body' => 'Commentaire interne.', 'created_by' => UserFactory::new(), 'created_at' => now()];
    }
}
