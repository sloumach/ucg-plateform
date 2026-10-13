<?php

namespace Database\Factories;

use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamLineupEntry;
use App\Modules\Teams\Domain\Models\TeamMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeamLineupEntry> */
class TeamLineupEntryFactory extends Factory
{
    protected $model = TeamLineupEntry::class;

    public function definition(): array
    {
        return ['lineup_id' => TeamLineup::factory(),
            'organization_id' => fn (array $a) => TeamLineup::query()->whereKey($a['lineup_id'])->firstOrFail()->organization_id,
            'membership_id' => fn (array $a) => TeamMembership::factory()->create(['team_id' => TeamLineup::query()->whereKey($a['lineup_id'])->firstOrFail()->team_id])->id,
            'membership_version' => fn (array $a) => TeamMembership::query()->whereKey($a['membership_id'])->firstOrFail()->version,
            'person_id' => fn (array $a) => TeamMembership::query()->whereKey($a['membership_id'])->firstOrFail()->person_id,
            'pseudonym' => 'Camille', 'membership_role_key' => 'player', 'slot_key' => 'starter', 'position' => 1];
    }
}
