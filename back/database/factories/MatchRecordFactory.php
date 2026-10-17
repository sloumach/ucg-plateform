<?php

namespace Database\Factories;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MatchRecord> */
class MatchRecordFactory extends Factory
{
    protected $model = MatchRecord::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'game_id' => GameCatalogueEntry::factory()->state(['default_enabled' => true]),
            'game_key' => fn (array $a) => GameCatalogueEntry::query()->whereKey($a['game_id'])->firstOrFail()->key,
            'game_name' => fn (array $a) => GameCatalogueEntry::query()->whereKey($a['game_id'])->firstOrFail()->name,
            'title' => 'Rencontre pilote', 'kind' => 'scrim', 'format' => 'bo1',
            'status' => 'proposed', 'visibility' => 'private', 'starts_at' => now()->addDay()->startOfHour(), 'ends_at' => now()->addDay()->startOfHour()->addHour(),
            'origin_timezone' => 'UTC', 'responsible_person_id' => fn (array $a) => Person::factory()->create(['organization_id' => $a['organization_id']])->id,
            'responsible_label' => 'Responsable pilote', 'created_by' => fn (array $a) => Organization::query()->whereKey($a['organization_id'])->firstOrFail()->owner_user_id,
            'version' => (string) Str::uuid()];
    }
}
