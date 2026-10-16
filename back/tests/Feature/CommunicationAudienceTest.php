<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Application\Services\CommunicationService;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Domain\Models\CommunicationEvent;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CommunicationAudienceTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_team_and_game_targets_follow_current_roster_and_expire_at_exact_period_end(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        [$org, $user] = $this->reader();
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
        $period = TeamMembership::factory()->create(['organization_id' => $org->id, 'team_id' => $team->id, 'person_id' => $person->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $teamPost = $this->target($org, 'team', $team->id);
        $gamePost = $this->target($org, 'game', $team->game_id);
        $foreign = $this->target($org, 'team', Team::factory()->create(['organization_id' => $org->id])->id);
        $this->getJson($this->root($org).'/announcements')->assertJsonPath('meta.total', 2);
        $this->getJson($this->root($org).'/inbox')->assertJsonPath('meta.total', 2);
        $this->getJson($this->root($org).'/announcements/'.$foreign->id)->assertNotFound();
        $this->travel(1)->hours();
        $this->getJson($this->root($org).'/announcements')->assertJsonPath('meta.total', 0);
        $this->getJson($this->root($org).'/inbox')->assertJsonPath('meta.total', 0);
        foreach ([$teamPost, $gamePost] as $post) {
            $this->getJson($this->root($org).'/announcements/'.$post->id)->assertNotFound();
            $this->getJson($this->root($org).'/announcements/'.$post->id.'/comments')->assertNotFound();
            $this->postJson($this->root($org).'/announcements/'.$post->id.'/comments', ['version' => $post->version, 'body' => 'Denied'])->assertNotFound();
        }
        $this->assertDatabaseHas('team_memberships', ['id' => $period->id]);
    }

    public function test_disabled_game_or_archived_team_removes_targeted_content(): void
    {
        [$org, $user] = $this->reader();
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
        TeamMembership::factory()->create(['organization_id' => $org->id, 'team_id' => $team->id, 'person_id' => $person->id]);
        $this->target($org, 'team', $team->id);
        $this->target($org, 'game', $team->game_id);
        $this->getJson($this->root($org).'/announcements')->assertJsonPath('meta.total', 2);
        $team->forceFill(['status' => 'archived'])->save();
        $this->getJson($this->root($org).'/announcements')->assertJsonPath('meta.total', 0);
        $second = Team::factory()->create(['organization_id' => $org->id]);
        TeamMembership::factory()->create(['organization_id' => $org->id, 'team_id' => $second->id, 'person_id' => $person->id]);
        OrganizationGame::factory()->create(['organization_id' => $org->id, 'game_id' => $second->game_id, 'enabled' => false]);
        $this->getJson($this->root($org).'/announcements')->assertJsonPath('meta.total', 0);
    }

    public function test_role_target_requires_an_active_organization_assignment_and_rechecks_role_revocation(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        [$org, $user, $grant] = $this->reader();
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id, 'permissions' => []]);
        $targetGrant = OrganizationRoleAssignment::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'membership_id' => $grant->membership_id,
            'role_id' => $role->id, 'scope_id' => $org->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $post = $this->target($org, 'role', $role->id);
        $this->getJson($this->root($org).'/announcements')->assertJsonPath('meta.total', 1);
        $this->travel(1)->hours();
        $this->getJson($this->root($org).'/announcements')->assertJsonPath('meta.total', 0);
        $this->getJson($this->root($org).'/inbox')->assertJsonPath('meta.total', 0);
        $targetGrant->forceFill(['ends_at' => null])->save();
        $role->forceFill(['active' => false])->save();
        $this->getJson($this->root($org).'/announcements/'.$post->id)->assertNotFound();
    }

    public function test_writer_preview_does_not_allow_commenting_outside_audience_and_target_is_rechecked_at_publish(): void
    {
        [$org, $user, $grant] = $this->reader();
        $grant->role->forceFill(['permissions' => ['communication.read', 'communication.write', 'communication.publish', 'communication.comment']])->save();
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $post = $this->target($org, 'team', $team->id);
        $this->getJson($this->root($org).'/announcements/'.$post->id.'?management=1')->assertOk()->assertJsonPath('data.can_comment', false);
        $this->postJson($this->root($org).'/announcements/'.$post->id.'/comments', ['version' => $post->version, 'body' => 'No'])->assertNotFound();
        $draft = $this->postJson($this->root($org).'/announcements', ['title' => 'Draft', 'body' => 'Private', 'target_type' => 'team', 'target_id' => $team->id])->assertCreated()->json('data');
        $team->forceFill(['status' => 'archived'])->save();
        $this->patchJson($this->root($org).'/announcements/'.$draft['id'], ['version' => $draft['version'], 'action' => 'publish', 'confirm_publication' => true])->assertNotFound();
        $this->assertDatabaseHas('announcements', ['id' => $draft['id'], 'status' => 'draft']);
    }

    public function test_foreign_and_inactive_target_choices_do_not_leak_or_accept_other_tenant_records(): void
    {
        [$org, $user, $grant] = $this->reader();
        $grant->role->forceFill(['permissions' => ['communication.read', 'communication.write']])->save();
        Team::factory()->create(['organization_id' => $org->id, 'name' => 'Allowed']);
        Team::factory()->create(['name' => 'Foreign']);
        Team::factory()->create(['organization_id' => $org->id, 'status' => 'archived', 'name' => 'Archived']);
        $this->getJson($this->root($org).'/targets?type=team&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.label', 'Allowed')->assertJsonMissingPath('data.0.game_id');
        foreach (['team' => Team::factory()->create()->id, 'role' => OrganizationRole::factory()->create()->id] as $type => $id) {
            $this->postJson($this->root($org).'/announcements', ['title' => 'X', 'body' => 'Y', 'target_type' => $type, 'target_id' => $id])->assertNotFound();
        }
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_feed_and_inbox_queries_are_bounded_independently_of_page_size(): void
    {
        [$org, $user] = $this->reader();
        Announcement::factory()->published()->count(20)->create(['organization_id' => $org->id]);
        $service = app(CommunicationService::class);
        $context = $this->personContext($org, $user);
        DB::enableQueryLog();
        $service->paginate($context, ['per_page' => 1]);
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->paginate($context, ['per_page' => 100]);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($small, $large);
        $this->assertLessThan(50, $large);
    }

    /** @return array{Organization, User, OrganizationRoleAssignment} */
    private function reader(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $grant = $this->grantPeopleAccess($org, $user, ['communication.read', 'communication.comment']);
        $this->actingAsWithMfa($user);

        return [$org, $user, $grant];
    }

    private function target(Organization $org, string $type, string $id): Announcement
    {
        $post = Announcement::factory()->published()->create(['organization_id' => $org->id, 'target_type' => $type, $type.'_id' => $id]);
        $event = new CommunicationEvent;
        $event->forceFill(['organization_id' => $org->id, 'announcement_id' => $post->id, 'kind' => 'announcement',
            'actor_user_id' => $post->created_by])->save();

        return $post;
    }

    private function root(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/communication';
    }
}
