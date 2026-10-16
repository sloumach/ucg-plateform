<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CommunicationControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    /** @var list<string> */
    private const array EDITOR = ['communication.read', 'communication.write', 'communication.publish', 'communication.comment'];

    public function test_guest_and_unassigned_owner_have_no_business_access(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->root($org).'/announcements')->assertUnauthorized();
        $this->actingAsWithMfa(User::findOrFail($org->owner_user_id));
        foreach (['announcements', 'inbox', 'preferences', 'targets?type=team'] as $path) {
            $this->getJson($this->root($org).'/'.$path)->assertForbidden();
        }
        $this->postJson($this->root($org).'/announcements', $this->input())->assertForbidden();
    }

    public function test_draft_is_encrypted_and_audited_without_content_or_external_delivery(): void
    {
        $org = $this->editor();
        Http::fake();
        Mail::fake();
        $response = $this->postJson($this->root($org).'/announcements', $this->input())->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.can_comment', false)
            ->assertJsonMissingPath('data.created_by')->assertJsonMissingPath('data.organization_id')
            ->assertJsonPath('notification.message', 'La communication a été enregistrée.');
        $stored = DB::table('announcements')->where('id', $response->json('data.id'))->firstOrFail();
        $this->assertNotSame('SECRET_TITLE', $stored->title);
        $this->assertNotSame('SECRET_BODY', $stored->body);
        $audit = json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SECRET_', $audit);
        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
        $this->assertDatabaseCount('communication_events', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_publication_requires_separate_permission_confirmation_and_a_current_version(): void
    {
        $org = $this->editor(['communication.read', 'communication.write']);
        $draft = $this->create($org);
        $path = $this->root($org).'/announcements/'.$draft['id'];
        $this->patchJson($path, ['version' => $draft['version'], 'action' => 'publish', 'confirm_publication' => true])->assertForbidden();
        $this->member($org, self::EDITOR);
        $this->patchJson($path, ['version' => $draft['version'], 'action' => 'publish'])->assertUnprocessable()->assertJsonValidationErrors('confirm_publication');
        $this->patchJson($path, ['version' => (string) Str::uuid(), 'action' => 'publish', 'confirm_publication' => true])->assertConflict();
        $published = $this->publish($org, $draft);
        $this->assertNotSame($draft['version'], $published['version']);
        $this->getJson($path)->assertOk()->assertJsonPath('data.can_comment', true);
        $this->assertDatabaseCount('communication_events', 0);
        $this->patchJson($path, ['version' => $published['version'], 'body' => 'Changed'])->assertConflict()->assertJsonPath('code', 'COMMUNICATION_CONTENT_FROZEN');
        $this->patchJson($path, ['version' => $published['version'], 'action' => 'publish', 'confirm_publication' => true])->assertConflict();
    }

    public function test_management_is_explicit_and_reader_cannot_count_or_fetch_drafts_expired_or_foreign_records(): void
    {
        $org = $this->editor(['communication.read']);
        $draft = Announcement::factory()->create(['organization_id' => $org->id]);
        $expired = Announcement::factory()->published()->create(['organization_id' => $org->id, 'published_at' => now()->subDay(), 'expires_at' => now()->subHour()]);
        $published = Announcement::factory()->published()->create(['organization_id' => $org->id]);
        $foreign = Announcement::factory()->published()->create();
        $this->getJson($this->root($org).'/announcements?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $published->id);
        foreach ([$draft, $expired, $foreign] as $record) {
            $this->getJson($this->root($org).'/announcements/'.$record->id)->assertNotFound();
            $this->getJson($this->root($org).'/announcements/'.$record->id.'/comments')->assertNotFound();
        }
        $this->getJson($this->root($org).'/announcements?management=1')->assertForbidden();
        $this->getJson($this->root($org).'/targets?type=role')->assertForbidden();
        $this->member($org, self::EDITOR);
        $this->getJson($this->root($org).'/announcements?management=1&status=draft')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_comments_are_paginated_require_current_audience_and_can_be_closed(): void
    {
        $org = $this->editor();
        $record = $this->publish($org, $this->create($org));
        $path = $this->root($org).'/announcements/'.$record['id'];
        $this->postJson($path.'/comments', ['version' => $record['version'], 'body' => '<img src=x>'])->assertCreated()
            ->assertJsonPath('data.body', '<img src=x>')->assertJsonPath('data.is_mine', true)->assertJsonMissingPath('data.created_by');
        $this->postJson($path.'/comments', ['version' => (string) Str::uuid(), 'body' => 'Stale'])->assertConflict();
        $closed = $this->patchJson($path, ['version' => $record['version'], 'action' => 'comments', 'comments_enabled' => false])->assertOk()->assertJsonPath('data.can_comment', false)->json('data');
        $this->postJson($path.'/comments', ['version' => $closed['version'], 'body' => 'No'])->assertConflict()->assertJsonPath('code', 'COMMUNICATION_COMMENTS_CLOSED');
        $this->member($org, ['communication.read']);
        $this->postJson($path.'/comments', ['version' => $closed['version'], 'body' => 'No'])->assertForbidden();
        $this->getJson($path.'/comments?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_mine', false);
        $this->assertDatabaseCount('announcement_comments', 1);
        $stored = DB::table('announcement_comments')->firstOrFail();
        $this->assertNotSame('<img src=x>', $stored->body);
    }

    public function test_notifications_recalculate_access_and_preferences_and_reads_are_idempotent_and_private(): void
    {
        $org = $this->editor();
        $record = $this->publish($org, $this->create($org), true);
        $this->getJson($this->root($org).'/inbox')->assertOk()->assertJsonCount(0, 'data');
        $reader = $this->member($org, ['communication.read']);
        $url = $this->root($org);
        $response = $this->getJson($url.'/inbox?unread=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'SECRET_TITLE')
            ->assertJsonPath('data.0.read', false)->assertJsonMissingPath('data.0.body')->assertJsonMissingPath('data.0.actor_user_id');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $event = $response->json('data.0.id');
        $this->postJson($url.'/inbox/'.$event.'/read')->assertOk();
        $this->postJson($url.'/inbox/'.$event.'/read')->assertOk();
        $this->assertDatabaseCount('communication_receipts', 1);
        $this->getJson($url.'/inbox?unread=1')->assertJsonCount(0, 'data');
        $this->getJson($url.'/inbox')->assertJsonPath('data.0.read', true);
        $preferences = $this->getJson($url.'/preferences')->assertJsonPath('data.version', null)->json('data');
        $saved = $this->patchJson($url.'/preferences', [...$preferences, 'announcements_enabled' => false])->assertOk()->json('data');
        $this->patchJson($url.'/preferences', [...$preferences, 'comments_enabled' => true])->assertConflict();
        $this->getJson($url.'/inbox')->assertJsonCount(0, 'data');
        $this->patchJson($url.'/preferences', [...$saved, 'announcements_enabled' => true])->assertOk();
        OrganizationRoleAssignment::query()->where('user_id', $reader->id)->update(['status' => 'revoked']);
        $this->getJson($url.'/inbox')->assertForbidden();
        $this->getJson($url.'/announcements/'.$record['id'])->assertForbidden();
        $this->postJson($url.'/inbox/'.$event.'/read')->assertForbidden();
    }

    public function test_comment_notifications_are_opt_in_for_author_or_existing_participants_only(): void
    {
        $org = $this->editor();
        $author = User::findOrFail(auth()->id());
        $record = $this->publish($org, $this->create($org));
        $other = $this->member($org, ['communication.read', 'communication.comment']);
        $path = $this->root($org).'/announcements/'.$record['id'].'/comments';
        $this->postJson($path, ['version' => $record['version'], 'body' => 'Reply', 'notify_members' => true])->assertCreated();
        $this->actingAsWithMfa($author);
        $url = $this->root($org);
        $this->getJson($url.'/inbox')->assertJsonCount(0, 'data');
        $this->patchJson($url.'/preferences', ['version' => null, 'announcements_enabled' => true, 'comments_enabled' => true])->assertOk();
        $this->getJson($url.'/inbox')->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'comment');
        $this->member($org, ['communication.read']);
        $this->patchJson($url.'/preferences', ['version' => null, 'announcements_enabled' => true, 'comments_enabled' => true])->assertOk();
        $this->getJson($url.'/inbox')->assertJsonCount(0, 'data');
        $this->actingAsWithMfa($other);
        $this->getJson($url.'/inbox')->assertJsonCount(0, 'data');
    }

    public function test_withdrawal_immediately_hides_content_comments_and_notifications_and_is_terminal(): void
    {
        $org = $this->editor();
        $record = $this->publish($org, $this->create($org), true);
        $this->postJson($this->root($org).'/announcements/'.$record['id'].'/comments', ['version' => $record['version'], 'body' => 'Reply'])->assertCreated();
        $reader = $this->member($org, ['communication.read']);
        $event = $this->getJson($this->root($org).'/inbox')->assertJsonCount(1, 'data')->json('data.0.id');
        $this->member($org, self::EDITOR);
        $path = $this->root($org).'/announcements/'.$record['id'];
        $withdrawn = $this->patchJson($path, ['version' => $record['version'], 'action' => 'withdraw'])->assertOk()->json('data');
        $this->patchJson($path, ['version' => $withdrawn['version'], 'title' => 'No'])->assertConflict();
        $this->actingAsWithMfa($reader);
        $this->getJson($path)->assertNotFound();
        $this->getJson($path.'/comments')->assertNotFound();
        $this->getJson($this->root($org).'/inbox')->assertJsonCount(0, 'data');
        $this->postJson($this->root($org).'/inbox/'.$event.'/read')->assertNotFound();
    }

    public function test_closed_validation_rejects_forged_ids_unsupported_contexts_and_mixed_actions(): void
    {
        $org = $this->editor();
        $root = $this->root($org).'/announcements';
        $record = $this->create($org);
        foreach (['event_id', 'task_id', 'match_id', 'session_id', 'organization_id', 'created_by'] as $key) {
            $this->postJson($root, [...$this->input(), $key => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->postJson($root, [...$this->input(), 'target_type' => 'event'])->assertUnprocessable();
        $this->postJson($root, [...$this->input(), 'target_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->patchJson($root.'/'.$record['id'], ['version' => $record['version']])->assertUnprocessable();
        $this->patchJson($root.'/'.$record['id'], ['version' => $record['version'], 'action' => 'publish', 'confirm_publication' => true, 'body' => 'Mixed'])->assertUnprocessable();
        foreach (['per_page=101', 'page=0', 'sort=body', 'status=draft'] as $query) {
            $this->getJson($root.'?'.$query)->assertUnprocessable();
        }
        $this->assertDatabaseCount('announcements', 1);
    }

    public function test_technical_operator_is_denied_even_with_business_assignments(): void
    {
        $org = $this->editor();
        PlatformRole::factory()->superadmin()->create(['user_id' => auth()->id()]);
        $this->getJson($this->root($org).'/announcements')->assertForbidden();
        $this->getJson($this->root($org).'/inbox')->assertForbidden();
    }

    public function test_audit_failure_rolls_back_publication_and_notification_event(): void
    {
        $org = $this->editor();
        $record = $this->create($org);
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Unavailable'));
        $this->patchJson($this->root($org).'/announcements/'.$record['id'], ['version' => $record['version'], 'action' => 'publish', 'confirm_publication' => true, 'notify_members' => true])->assertStatus(500);
        $this->assertDatabaseHas('announcements', ['id' => $record['id'], 'status' => 'draft']);
        $this->assertDatabaseCount('communication_events', 0);
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = self::EDITOR): Organization
    {
        $org = Organization::factory()->create();
        $this->member($org, $permissions);

        return $org;
    }

    /** @param list<string> $permissions */
    private function member(Organization $org, array $permissions): User
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $user;
    }

    private function root(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/communication';
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['title' => 'SECRET_TITLE', 'body' => 'SECRET_BODY', 'target_type' => 'organization', 'comments_enabled' => true];
    }

    /** @return array<string, mixed> */
    private function create(Organization $org): array
    {
        return $this->postJson($this->root($org).'/announcements', $this->input())->assertCreated()->json('data');
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function publish(Organization $org, array $record, bool $notify = false): array
    {
        return $this->patchJson($this->root($org).'/announcements/'.$record['id'], ['version' => $record['version'], 'action' => 'publish', 'confirm_publication' => true, 'notify_members' => $notify])->assertOk()->json('data');
    }
}
