<?php

namespace Tests\Fixtures;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Domain\Models\Organization;
use Carbon\CarbonImmutable;

trait ProvidesLineups
{
    use ProvidesPeopleAccess;

    /** @param list<string> $permissions
     * @return array{Organization,User,Team,TeamMembership}
     */
    protected function lineupEditor(array $permissions = ['sports.read', 'teams.manage']): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13T12:00:00Z'));
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $period = TeamMembership::factory()->create(['team_id' => $team->id]);

        return [$org, $user, $team, $period];
    }

    /** @return array<string,mixed> */
    protected function lineupPayload(Team $team, TeamMembership $period): array
    {
        return ['purpose' => 'match', 'label' => 'Scrim du mardi', 'effective_at' => '2026-10-13T11:00:00+00:00',
            'team_version' => $team->version, 'entries' => [$this->lineupChoice($period)]];
    }

    /** @return array<string,string> */
    protected function lineupChoice(TeamMembership $period, string $slot = 'starter'): array
    {
        return ['membership_id' => $period->id, 'membership_version' => $period->version, 'slot_key' => $slot];
    }

    protected function lineupPath(Team $team): string
    {
        return '/api/v1/tenants/'.$team->organization_id.'/teams/'.$team->id.'/lineups';
    }
}
