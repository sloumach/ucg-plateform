<?php

namespace Database\Factories;

use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkTask>
 */
class WorkTaskFactory extends Factory
{
    protected $model = WorkTask::class;

    public function configure(): static
    {
        return $this->afterCreating(function (WorkTask $task): void {
            Announcement::query()->findOrFail($task->announcement_id)->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $task->created_by])->save();
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(), 'title' => 'Préparer le dossier', 'description' => 'Vérifier les pièces.',
            'created_by' => UserFactory::new(), 'status' => 'todo', 'version' => (string) Str::uuid(),
            'announcement_id' => fn (array $attributes): string => Announcement::factory()->create(['organization_id' => $attributes['organization_id'], 'created_by' => $attributes['created_by']])->id,
        ];
    }
}
