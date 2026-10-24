<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorContact;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipOpportunity;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesMatches;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class TenantBusinessRevocationTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesExternalCompetitions, ProvidesMatches, ProvidesPeopleAccess;

    /** @return array<string, array{string, string, string, string}> */
    public static function revocations(): array
    {
        $cases = [];
        foreach (['sponsors' => 'partnerships', 'contacts' => 'partnerships', 'opportunities' => 'partnerships',
            'agreements' => 'partnerships', 'campaigns' => 'partnerships', 'media' => 'media',
            'communication' => 'communication', 'calendar' => 'calendar', 'matches' => 'sports',
            'competitions' => 'sports.competitions'] as $module => $permission) {
            foreach (['membership', 'role', 'read', 'write'] as $revocation) {
                $cases[$module.' '.$revocation] = [$module, $permission.'.read', $permission.'.write', $revocation];
            }
        }

        return $cases;
    }

    #[Group('tenant-isolation')]
    #[DataProvider('revocations')]
    public function test_previously_read_routes_recheck_alpha_rights_without_revoking_beta(string $module, string $read, string $write, string $revocation): void
    {
        $this->travelTo('2026-10-18 10:00:00 UTC');
        $actor = User::factory()->withMfa()->create();
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $permissions = array_values(array_unique([$read, $write, 'sports.read', ...match ($module) {
            'matches' => ['sports.publish'], 'media' => ['media.publish'], 'calendar' => ['calendar.publish'], default => [],
        }]));
        $assignment = $this->grantPeopleAccess($alpha, $actor, $permissions);
        $this->grantPeopleAccess($beta, $actor, $permissions);
        $this->actingAsWithMfa($actor);
        $first = $this->scenario($module, $alpha, $actor);
        $second = $this->scenario($module, $beta, $actor);
        foreach ([$first, $second] as $scenario) {
            foreach ($scenario['reads'] as $url) {
                $this->getJson($url)->assertOk();
            }
        }
        $before = $this->snapshot($alpha->id, $first['tables']);
        $betaBefore = $this->snapshot($beta->id, $second['tables']);
        $audits = DB::table('organization_audit_events')->count();
        if ($revocation === 'membership') {
            OrganizationMembership::query()->whereKey($assignment->membership_id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } elseif ($revocation === 'role') {
            $assignment->forceFill(['status' => 'revoked'])->save();
        } else {
            $assignment->role->forceFill(['permissions' => array_values(array_diff($permissions, [$revocation === 'read' ? $read : $write]))])->save();
        }

        foreach ($first['reads'] as $url) {
            $response = $this->getJson($url);
            if ($revocation === 'write') {
                $response->assertOk();
            } else {
                $response->assertStatus($revocation === 'membership' ? 404 : 403)->assertJsonMissingPath('data');
            }
        }
        $this->patchJson($first['path'], $first['input'])->assertStatus($revocation === 'membership' ? 404 : 403);
        $this->assertSame($before, $this->snapshot($alpha->id, $first['tables']));
        $this->assertSame($betaBefore, $this->snapshot($beta->id, $second['tables']));
        $this->assertSame($audits, DB::table('organization_audit_events')->count());
        foreach ($second['reads'] as $url) {
            $this->getJson($url)->assertOk();
        }
        $this->patchJson($second['path'], $second['input'])->assertOk();
        $this->assertModelExists($second['model']);
        $this->assertSame($second['value'], $second['model']->refresh()->getAttribute($second['field']));
        $this->assertSame($before, $this->snapshot($alpha->id, $first['tables']));
    }

    /** @return array{reads: list<string>, path: string, input: array<string, mixed>, model: Model, tables: list<string>, field: string, value: mixed} */
    private function scenario(string $module, Organization $org, User $actor): array
    {
        $root = '/api/v1/tenants/'.$org->id;
        $field = 'title';
        $value = 'Correction Beta autorisée';
        $extra = [];
        if (in_array($module, ['sponsors', 'contacts', 'opportunities', 'agreements', 'campaigns'], true)) {
            $sponsor = Sponsor::factory()->create(['organization_id' => $org->id]);
            $collection = $root.'/sponsors';
            $record = $sponsor;
            $field = 'name';
            $tables = ['sponsors', 'sponsor_contacts', 'sponsorship_opportunities', 'sponsorship_interactions', 'sponsorship_agreements', 'sponsorship_campaigns', 'sponsorship_agreement_events', 'sponsorship_campaign_events'];
            if ($module === 'contacts') {
                $collection .= '/'.$sponsor->id.'/contacts';
                $data = $this->postJson($collection, ['name' => 'Contact privé'])->assertCreated()->json('data');
                $record = SponsorContact::query()->whereKey($data['id'])->firstOrFail();
            } elseif ($module === 'opportunities') {
                $collection .= '/'.$sponsor->id.'/opportunities';
                $data = $this->postJson($collection, ['title' => 'Offre privée'])->assertCreated()->json('data');
                $record = SponsorshipOpportunity::query()->whereKey($data['id'])->firstOrFail();
                $field = 'title';
            } elseif (in_array($module, ['agreements', 'campaigns'], true)) {
                $record = SponsorshipAgreement::factory()->create(['sponsor_id' => $sponsor->id, 'responsible_user_id' => $actor->id, 'prepared_by' => $actor->id]);
                $collection .= '/'.$sponsor->id.'/agreements';
                $field = 'title';
                if ($module === 'campaigns') {
                    $extra['agreement_version'] = $record->version;
                    $collection .= '/'.$record->id.'/campaigns';
                    $record = SponsorshipCampaign::factory()->create(['agreement_id' => $record->id, 'responsible_user_id' => $actor->id, 'created_by' => $actor->id]);
                }
            }
        } elseif ($module === 'media') {
            $record = MediaItem::factory()->create(['organization_id' => $org->id,
                'team_id' => Team::factory()->create(['organization_id' => $org->id])->id,
                'visibility' => 'organization', 'created_by' => $actor->id, 'sharing_authorized_by' => $actor->id, 'sharing_authorized_at' => now()]);
            $collection = $root.'/media';
            $extra['confirm_sharing'] = true;
            $tables = ['media_items'];
        } elseif ($module === 'communication') {
            $record = Announcement::factory()->published()->create(['organization_id' => $org->id, 'created_by' => $actor->id]);
            $collection = $root.'/communication/announcements';
            $field = 'comments_enabled';
            $value = false;
            $extra['action'] = 'comments';
            $tables = ['announcements', 'announcement_comments', 'communication_events'];
        } elseif ($module === 'calendar') {
            $record = CalendarActivity::factory()->shared()->create(['organization_id' => $org->id, 'created_by' => $actor->id]);
            $collection = $root.'/calendar/activities';
            $extra['confirm_sharing'] = true;
            $tables = ['calendar_activities', 'calendar_participants', 'calendar_changes', 'calendar_change_recipients'];
        } elseif ($module === 'matches') {
            $game = GameCatalogueEntry::factory()->create(['default_enabled' => true]);
            $person = Person::factory()->create(['organization_id' => $org->id]);
            $other = Person::factory()->create(['organization_id' => $org->id]);
            $data = $this->postJson($root.'/matches', [...$this->matchPayload($game, $person, $other), 'visibility' => 'organization', 'confirm_sharing' => true])->assertCreated()->json('data');
            $record = MatchRecord::query()->whereKey($data['id'])->firstOrFail();
            $collection = $root.'/matches';
            $tables = ['matches', 'match_participants', 'match_status_events'];
        } else {
            $collection = $root.'/external-competitions';
            $game = GameCatalogueEntry::factory()->create(['default_enabled' => true]);
            $data = $this->postJson($collection, $this->competitionPayload($game->id))->assertCreated()->json('data');
            $record = ExternalCompetition::query()->whereKey($data['id'])->firstOrFail();
            $field = 'name';
            $extra['reason'] = 'Correction vérifiée dans le tenant autorisé.';
            $tables = ['external_competitions', 'external_competition_revisions'];
        }

        $path = $collection.'/'.$record->id;
        $reads = [$collection, $path];
        if (in_array($module, ['contacts', 'opportunities'], true)) {
            $reads = [$collection];
        } elseif ($module === 'calendar') {
            $reads[0] .= '?from_date=2026-10-18&to_date=2026-10-20';
        }
        if (in_array($module, ['agreements', 'campaigns', 'matches'], true)) {
            $reads[] = $path.'/history';
        } elseif ($module === 'communication') {
            $reads[] = $path.'/comments';
            $reads[] = $root.'/communication/inbox';
        }

        return ['reads' => $reads, 'path' => $path, 'input' => ['version' => $record->version, $field => $value, ...$extra],
            'model' => $record, 'tables' => $tables, 'field' => $field, 'value' => $value];
    }

    /** @param list<string> $tables
     * @return array<string, list<array<string, mixed>>> */
    private function snapshot(string $org, array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = array_values(DB::table($table)->where('organization_id', $org)->orderBy('id')->get()
                ->map(fn (object $row): array => get_object_vars($row))->all());
        }

        return $rows;
    }
}
