<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Application\Services\MediaService;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MediaControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guests_cannot_read_or_write_media(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org))->assertUnauthorized();
        $this->postJson($this->path($org), [])->assertUnauthorized();
    }

    public function test_editor_creates_private_media_with_canonical_link_and_only_minimal_audit(): void
    {
        $org = $this->editor();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        $data = $this->postJson($this->path($org), $this->input($org))->assertCreated()
            ->assertJsonPath('notification.message', 'Le média a été enregistré.')
            ->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.effective_visibility', 'private')
            ->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=abcdefghijk')
            ->assertJsonPath('data.status_source', 'manual')->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.team_label', 'Équipe pilote')
            ->assertJsonMissingPath('data.organization_id')->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.score')->json('data');
        $this->assertDatabaseHas('media_items', ['id' => $data['id'], 'organization_id' => $org->id, 'visibility' => 'private']);
        $this->assertDatabaseCount('media_items', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE_TITLE', $audit);
        $this->assertStringNotContainsString('abcdefghijk', $audit);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_reader_cannot_enumerate_private_scheduled_or_archived_media(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $org = $this->editor(['media.read']);
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $private = MediaItem::factory()->create(['team_id' => $team->id, 'organization_id' => $org->id]);
        $shared = MediaItem::factory()->create(['team_id' => $team->id, 'organization_id' => $org->id, 'visibility' => 'organization',
            'sharing_authorized_by' => auth()->id(), 'sharing_authorized_at' => now()]);
        $scheduled = MediaItem::factory()->create(['team_id' => $team->id, 'organization_id' => $org->id, 'visibility' => 'public', 'is_scrim' => true,
            'publish_at' => now()->addHour(), 'sharing_authorized_by' => auth()->id(), 'sharing_authorized_at' => now()]);
        $archive = MediaItem::factory()->create(['team_id' => $team->id, 'organization_id' => $org->id, 'archived_at' => now()]);
        $response = $this->getJson($this->path($org))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $shared->id);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        foreach ([$private, $scheduled, $archive] as $hidden) {
            $this->getJson($this->path($org, $hidden->id))->assertNotFound();
        }
        $this->postJson($this->path($org), $this->input($org))->assertForbidden();
        $this->getJson($this->path($org).'?archived=1')->assertForbidden();
        $this->patchJson($this->path($org, $private->id), ['version' => $private->version, 'title' => 'No'])->assertForbidden();
        $this->travel(1)->hours();
        $this->getJson($this->path($org, $scheduled->id))->assertOk()->assertJsonPath('data.effective_visibility', 'public');
        $this->getJson($this->path($org))->assertJsonCount(2, 'data');
    }

    public function test_shared_media_require_publish_permission_and_explicit_confirmation(): void
    {
        $org = $this->editor();
        $input = [...$this->input($org), 'visibility' => 'organization', 'confirm_sharing' => true];
        $this->postJson($this->path($org), $input)->assertForbidden();
        $this->assertDatabaseCount('media_items', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
        $publisher = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $publisher, ['media.read', 'media.write', 'media.publish']);
        $this->actingAsWithMfa($publisher);
        unset($input['confirm_sharing']);
        $this->postJson($this->path($org), $input)->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $record = $this->postJson($this->path($org), [...$input, 'confirm_sharing' => true])->assertCreated()->json('data');
        $this->assertDatabaseHas('media_items', ['id' => $record['id'], 'sharing_authorized_by' => auth()->id()]);
        $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'title' => 'New title'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
    }

    public function test_scrim_is_private_by_default_and_delayed_sharing_is_explicit_and_retractable(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $org = $this->editor(['media.read', 'media.write', 'media.publish']);
        $record = $this->postJson($this->path($org), [...$this->input($org), 'is_scrim' => true])->assertCreated()
            ->assertJsonPath('data.visibility', 'private')->json('data');
        $path = $this->path($org, $record['id']);
        $share = ['version' => $record['version'], 'visibility' => 'organization', 'confirm_sharing' => true];
        $this->patchJson($path, $share)->assertUnprocessable()->assertJsonValidationErrors('publish_at');
        $this->patchJson($path, [...$share, 'publish_at' => '2026-10-16T11:59:59+00:00'])->assertUnprocessable()->assertJsonValidationErrors('publish_at');
        $record = $this->patchJson($path, [...$share, 'publish_at' => '2026-10-16T13:00:00+00:00'])
            ->assertOk()->assertJsonPath('data.effective_visibility', 'private')->json('data');
        $this->patchJson($path, ['version' => $record['version'], 'visibility' => 'private'])->assertOk()
            ->assertJsonPath('data.publish_at', null)->assertJsonPath('data.effective_visibility', 'private');
        $this->assertDatabaseHas('media_items', ['id' => $record['id'], 'sharing_authorized_by' => null, 'sharing_authorized_at' => null]);
    }

    public function test_media_links_and_scores_are_separate_and_status_observation_is_not_refreshed_by_title_edit(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $org = $this->editor();
        $record = $this->postJson($this->path($org), [...$this->input($org), 'kind' => 'stream', 'provider' => 'twitch',
            'url' => 'https://www.twitch.tv/demo_channel', 'status' => 'live'])->assertCreated()->json('data');
        $this->travel(1)->hours();
        $record = $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'title' => 'Rename'])
            ->assertOk()->assertJsonPath('data.status_observed_at', '2026-10-16T12:00:00+00:00')->json('data');
        $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'status' => 'ended'])
            ->assertUnprocessable()->assertJsonValidationErrors('status_observed_at');
        $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'status' => 'ended', 'status_observed_at' => '2026-10-16T11:59:59+00:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('status_observed_at');
        $record = $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'status' => 'unavailable', 'status_observed_at' => '2026-10-16T13:00:00+00:00'])
            ->assertOk()->assertJsonPath('data.status', 'unavailable')->json('data');
        $this->patchJson($this->path($org, $record['id']), ['version' => $record['version'], 'score' => '2-0'])
            ->assertUnprocessable()->assertJsonValidationErrors('score');
    }

    public function test_foreign_and_inactive_team_and_cross_tenant_media_are_not_found(): void
    {
        $org = $this->editor();
        $foreign = MediaItem::factory()->create();
        $input = $this->input($org);
        $this->getJson($this->path($org, $foreign->id))->assertNotFound();
        $this->patchJson($this->path($org, $foreign->id), ['version' => $foreign->version, 'title' => 'No'])->assertNotFound();
        $this->postJson($this->path($org), [...$input, 'team_id' => $foreign->team_id])->assertNotFound();
        Team::whereKey($input['team_id'])->update(['status' => 'archived']);
        $this->postJson($this->path($org), $input)->assertNotFound();
        $this->assertDatabaseCount('media_items', 1);
    }

    public function test_validation_rejects_unknown_references_bad_links_status_and_unbounded_filters(): void
    {
        $org = $this->editor();
        $input = $this->input($org);
        $this->postJson($this->path($org), [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'team_id', 'url', 'kind', 'provider']);
        $this->postJson($this->path($org), [...$input, 'url' => 'https://youtube.com.evil.test/watch?v=abcdefghijk'])
            ->assertUnprocessable()->assertJsonPath('errors.url.0', 'Utilisez un lien HTTPS Twitch ou YouTube correspondant au type choisi, sans paramètres de suivi.');
        $this->postJson($this->path($org), [...$input, 'status' => 'live'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($this->path($org), [...$input, 'match_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('match_id');
        $this->postJson($this->path($org), [...$input, 'status_observed_at' => now()->addDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('status_observed_at');
        foreach (['per_page=101', 'page=0', 'sort=title%3BDROP', 'kind=other', 'organization_id=forged'] as $query) {
            $this->getJson($this->path($org).'?'.$query)->assertUnprocessable();
        }
        $this->assertDatabaseCount('media_items', 0);
    }

    public function test_search_pagination_and_reference_labels_are_batched_without_other_team_fields(): void
    {
        $org = $this->editor();
        $team = Team::factory()->create(['organization_id' => $org->id]);
        MediaItem::factory()->count(3)->create(['organization_id' => $org->id, 'team_id' => $team->id, 'title' => 'Alpha%one']);
        MediaItem::factory()->create(['title' => 'Alpha%foreign']);
        $response = $this->getJson($this->path($org).'?q=Alpha%25&per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.team_label', $team->name);
        $this->assertNotNull($response->json('data.0.id'));
        $this->getJson($this->path($org).'/teams?per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.organization_id')->assertJsonMissingPath('data.0.game_id');
        DB::enableQueryLog();
        app(MediaService::class)->paginate($this->personContext($org, User::findOrFail(auth()->id())), ['per_page' => 100]);
        $labelQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'select "name", "id" from "teams"'));
        DB::disableQueryLog();
        $this->assertCount(1, $labelQueries);
    }

    public function test_versions_and_terminal_archive_protect_updates_and_archives(): void
    {
        $org = $this->editor();
        $record = $this->postJson($this->path($org), $this->input($org))->assertCreated()->json('data');
        $path = $this->path($org, $record['id']);
        $this->patchJson($path, ['version' => (string) Str::uuid(), 'title' => 'Stale'])->assertConflict()->assertJsonPath('code', 'MEDIA_VERSION_STALE');
        $this->patchJson($path, ['version' => $record['version']])->assertUnprocessable();
        $this->patchJson($path, ['version' => $record['version'], 'archive' => true, 'title' => 'Mixed'])->assertUnprocessable()->assertJsonValidationErrors('archive');
        $record = $this->patchJson($path, ['version' => $record['version'], 'archive' => true])->assertOk()->json('data');
        $this->patchJson($path, ['version' => $record['version'], 'title' => 'Frozen'])->assertConflict()->assertJsonPath('code', 'MEDIA_ARCHIVED');
        $this->getJson($this->path($org))->assertJsonCount(0, 'data');
        $this->getJson($this->path($org).'?archived=1')->assertJsonCount(1, 'data');
        $this->deleteJson($path)->assertStatus(405);
    }

    public function test_audit_failure_rolls_back_media(): void
    {
        $org = $this->editor();
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($this->path($org), $this->input($org))->assertStatus(500);
        $this->assertDatabaseCount('media_items', 0);
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = ['media.read', 'media.write']): Organization
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $org;
    }

    private function path(Organization $org, ?string $id = null): string
    {
        return '/api/v1/tenants/'.$org->id.'/media'.($id === null ? '' : '/'.$id);
    }

    /** @return array<string, mixed> */
    private function input(Organization $org): array
    {
        return ['team_id' => Team::factory()->create(['organization_id' => $org->id])->id, 'title' => 'PRIVATE_TITLE',
            'kind' => 'vod', 'provider' => 'youtube', 'url' => 'https://youtu.be/abcdefghijk'];
    }
}
