<?php

namespace Tests\Fixtures;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamLineupEntry;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Support\Str;

trait ProvidesMatchLineups
{
    use ProvidesMatches;

    /** @return array{org:Organization,writer:User,reader:User,team:Team,lineup:TeamLineup,starter:Person,sub:Person,period:TeamMembership,match:MatchRecord,path:string} */
    private function matchLineupScenario(): array
    {
        [$org, $writer, $reader, $person, $other, $game] = $this->matchScenario();
        $team = Team::factory()->create(['organization_id' => $org->id, 'game_id' => $game->id]);
        $sub = Person::factory()->create(['organization_id' => $org->id, 'pseudonym' => 'Remplaçant historique']);
        $period = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $person->id, 'starts_at' => '2026-10-01 10:00:00']);
        $subPeriod = TeamMembership::factory()->create(['team_id' => $team->id, 'person_id' => $sub->id, 'role_key' => 'substitute', 'starts_at' => '2026-10-01 10:00:00']);
        $lineup = TeamLineup::factory()->create(['team_id' => $team->id, 'game_key' => $game->key, 'game_name' => $game->name, 'effective_at' => '2026-10-19 10:00:00']);
        TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'membership_id' => $period->id, 'pseudonym' => 'Titulaire historique']);
        TeamLineupEntry::factory()->create(['lineup_id' => $lineup->id, 'membership_id' => $subPeriod->id, 'membership_role_key' => 'substitute', 'pseudonym' => 'Remplaçant historique', 'slot_key' => 'substitute', 'position' => 2]);
        $lineup->forceFill(['status' => 'finalized', 'version' => (string) Str::uuid(), 'finalized_at' => now(), 'finalized_by' => $writer->id])->save();
        $payload = $this->matchPayload($game, $person, $other);
        $payload['participants'][0] = ['kind' => 'team', 'source_id' => $team->id];
        $created = $this->postJson($this->matchRoot($org), $payload)->assertCreated()->json('data');
        $match = MatchRecord::query()->where('id', $created['id'])->firstOrFail();

        return ['org' => $org, 'writer' => $writer, 'reader' => $reader, 'team' => $team, 'lineup' => $lineup, 'starter' => $person,
            'sub' => $sub, 'period' => $period, 'match' => $match, 'path' => $this->matchRoot($org).'/'.$match->id.'/participants/'.$created['participants'][0]['id'].'/lineups'];
    }
}
