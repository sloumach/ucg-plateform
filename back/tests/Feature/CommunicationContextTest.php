<?php

namespace Tests\Feature;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Application\Services\CommunicationService;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CommunicationContextTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    private const array EDITOR = ['communication.read', 'communication.write', 'communication.publish', 'communication.comment', 'calendar.read', 'sports.read', 'academy.read'];

    /** @return array<string, array{string, string}> */
    public static function contexts(): array
    {
        return ['planning' => ['event', 'calendar.read'], 'match' => ['match', 'sports.read'], 'academy' => ['session', 'academy.read']];
    }

    #[DataProvider('contexts')]
    public function test_context_announcements_recheck_source_permissions_for_feed_preview_comments_and_inbox(string $type, string $permission): void
    {
        [$org, $author] = $this->editor();
        $source = $this->source($org, $type, $author);
        $draft = $this->postJson($this->root($org).'/announcements', ['title' => 'Contexte privé', 'body' => 'Message', 'target_type' => $type, 'target_id' => $source->id])
            ->assertCreated()->assertJsonPath('data.target_type', $type)->assertJsonPath('data.target_id', $source->id)->json('data');
        $path = $this->root($org).'/announcements/'.$draft['id'];
        $published = $this->patchJson($path, ['version' => $draft['version'], 'action' => 'publish', 'confirm_publication' => true, 'notify_members' => true])
            ->assertOk()->assertJsonPath('data.can_comment', true)->json('data');
        $reader = $this->member($org, self::EDITOR);
        // The source belongs to the organization audience, independently of who wrote the announcement.
        $this->getJson($path)->assertOk();
        $this->getJson($this->root($org).'/inbox')->assertJsonPath('meta.total', 1);
        $grant = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $reader->id)->firstOrFail();
        $grant->role->forceFill(['permissions' => array_values(array_diff(self::EDITOR, [$permission]))])->save();
        $this->getJson($this->root($org).'/announcements')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->root($org).'/announcements?management=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->root($org).'/inbox')->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['', '?management=1', '/comments'] as $suffix) {
            $this->getJson($path.$suffix)->assertNotFound();
        }
        $this->postJson($path.'/comments', ['version' => $published['version'], 'body' => 'Refus'])->assertNotFound();
        $this->assertDatabaseCount('announcement_comments', 0);
    }

    #[DataProvider('contexts')]
    public function test_direct_discussion_is_created_with_first_comment_without_publication_permission_and_keeps_one_thread(string $type, string $permission): void
    {
        [$org, $user] = $this->editor(['communication.read', 'communication.comment', $permission]);
        $source = $this->source($org, $type, $user);
        $path = $this->root($org).'/discussions/'.$type.'/'.$source->id;
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('context.version', $source->version)
            ->assertJsonPath('context.thread_version', null)->assertJsonPath('context.can_comment', true);
        $this->assertDatabaseCount('announcements', 0);
        $this->postJson($path, ['version' => $source->version, 'thread_version' => null, 'body' => '<script>Premier</script>'])
            ->assertCreated()->assertJsonPath('data.body', '<script>Premier</script>')->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('notification.type', 'success');
        $first = $this->getJson($path.'?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->json('context');
        $this->postJson($path, ['version' => $source->version, 'thread_version' => $first['thread_version'], 'body' => 'Deuxième'])->assertCreated();
        $this->postJson($path, ['version' => $source->version, 'thread_version' => null, 'body' => 'Ancien écran'])->assertConflict();
        $this->getJson($path.'?per_page=1&page=2')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.body', 'Deuxième');
        $this->getJson($this->root($org).'/announcements')->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseCount('communication_contexts', 1);
        $this->assertDatabaseCount('announcement_comments', 2);
        $this->assertNotSame('<script>Premier</script>', DB::table('announcement_comments')->firstOrFail()->body);
        $this->assertStringNotContainsString('Premier', json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR));
        $this->getJson($this->root($org).'/context-targets?type='.$type.'&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $source->id);
    }

    #[DataProvider('contexts')]
    #[Group('tenant-isolation')]
    public function test_foreign_context_cannot_be_read_targeted_or_commented_even_for_actor_in_both_organizations(string $type, string $permission): void
    {
        [$org, $user] = $this->editor();
        $beta = Organization::factory()->create();
        $this->grantPeopleAccess($beta, $user, self::EDITOR);
        $source = $this->source($beta, $type, $user);
        $path = $this->root($org).'/discussions/'.$type.'/'.$source->id;
        $this->getJson($path)->assertNotFound();
        $this->postJson($path, ['version' => $source->version, 'thread_version' => null, 'body' => 'Refus'])->assertNotFound();
        $this->postJson($this->root($org).'/announcements', ['title' => 'Non', 'body' => 'Non', 'target_type' => $type, 'target_id' => $source->id])->assertNotFound();
        $this->getJson($this->root($beta).'/discussions/'.$type.'/'.$source->id)->assertOk();
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('announcement_comments', 0);
    }

    public function test_private_source_cannot_be_disclosed_by_communication_management_and_unknown_context_types_are_refused(): void
    {
        [$org] = $this->editor();
        $private = CalendarActivity::factory()->create(['organization_id' => $org->id]);
        $path = $this->root($org);
        $this->postJson($path.'/announcements', ['title' => 'Non', 'body' => 'Non', 'target_type' => 'event', 'target_id' => $private->id])->assertNotFound();
        $this->getJson($path.'/context-targets?type=event')->assertJsonPath('meta.total', 0);
        $this->getJson($path.'/discussions/task/'.$private->id)->assertNotFound();
        $this->getJson($path.'/context-targets?type=task')->assertUnprocessable();
        $this->assertDatabaseCount('communication_contexts', 0);
    }

    public function test_source_version_and_closed_payload_are_checked_before_any_history_is_created(): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, 'event', $user);
        $path = $this->root($org).'/discussions/event/'.$source->id;
        $payload = ['version' => $source->version, 'thread_version' => null, 'body' => 'Texte'];
        $this->postJson($path, [...$payload, 'version' => (string) Str::uuid()])->assertConflict();
        $this->postJson($path, [...$payload, 'organization_id' => $org->id])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->postJson($path, ['version' => $source->version, 'body' => 'Texte'])->assertUnprocessable()->assertJsonValidationErrors('thread_version');
        $this->getJson($path.'?per_page=101')->assertUnprocessable();
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('communication_contexts', 0);
        $this->assertDatabaseCount('announcement_comments', 0);
    }

    public function test_context_remains_immutable_and_source_is_rechecked_before_publishing_a_draft(): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, 'event', $user);
        $draft = $this->postJson($this->root($org).'/announcements', ['title' => 'Titre', 'body' => 'Texte', 'target_type' => 'event', 'target_id' => $source->id])->assertCreated()->json('data');
        $path = $this->root($org).'/announcements/'.$draft['id'];
        $this->patchJson($path, ['version' => $draft['version'], 'target_type' => 'organization', 'target_id' => null])->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $source->forceFill(['archived_at' => now()])->save();
        $this->patchJson($path, ['version' => $draft['version'], 'action' => 'publish', 'confirm_publication' => true])->assertNotFound();
        $this->assertDatabaseHas('announcements', ['id' => $draft['id'], 'status' => 'draft']);
        $this->assertDatabaseCount('communication_events', 0);
    }

    public function test_database_rejects_foreign_reference_invalid_shapes_and_mutation_of_binding(): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, 'event', $user);
        $draft = $this->postJson($this->root($org).'/announcements', ['title' => 'Titre', 'body' => 'Texte', 'target_type' => 'event', 'target_id' => $source->id])->assertCreated()->json('data');
        $foreign = CalendarActivity::factory()->create();
        foreach ([fn () => DB::table('communication_contexts')->where('announcement_id', $draft['id'])->update(['activity_id' => $foreign->id]),
            fn () => DB::table('communication_contexts')->where('announcement_id', $draft['id'])->delete()] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('A binding cannot be changed or deleted.');
            } catch (QueryException) {
                $this->assertDatabaseHas('communication_contexts', ['announcement_id' => $draft['id'], 'activity_id' => $source->id]);
            }
        }
        $parent = Announcement::factory()->create(['organization_id' => $org->id]);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('communication_contexts')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org->id,
            'announcement_id' => $parent->id, 'activity_id' => $foreign->id, 'discussion' => false, 'created_at' => now()]));
    }

    #[Group('tenant-isolation')]
    public function test_discussion_notifications_and_old_urls_disappear_when_calendar_audience_changes(): void
    {
        [$org, $author] = $this->editor();
        $source = $this->source($org, 'event', $author);
        $path = $this->root($org).'/discussions/event/'.$source->id;
        $payload = ['version' => $source->version, 'thread_version' => null, 'body' => 'Premier'];
        $this->postJson($path, $payload)->assertCreated();
        $thread = $this->getJson($path)->json('context.thread_version');
        $this->patchJson($this->root($org).'/preferences', ['version' => null, 'announcements_enabled' => true, 'comments_enabled' => true])->assertOk();
        $reader = $this->member($org, self::EDITOR);
        $this->patchJson($this->root($org).'/preferences', ['version' => null, 'announcements_enabled' => true, 'comments_enabled' => true])->assertOk();
        $this->postJson($path, [...$payload, 'thread_version' => $thread, 'body' => 'Réponse du lecteur', 'notify_members' => true])->assertCreated();
        $this->actingAsWithMfa($author);
        $this->getJson($this->root($org).'/inbox')->assertJsonPath('meta.total', 1);
        $this->postJson($path, [...$payload, 'thread_version' => $thread, 'body' => 'Réponse auteur', 'notify_members' => true])->assertCreated();
        $this->actingAsWithMfa($reader);
        $inbox = $this->getJson($this->root($org).'/inbox')->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $source->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null, 'version' => (string) Str::uuid()])->save();
        $this->getJson($path)->assertNotFound();
        $this->postJson($path, [...$payload, 'thread_version' => $thread])->assertNotFound();
        $this->getJson($this->root($org).'/inbox')->assertJsonPath('meta.total', 0);
        $this->getJson($this->root($org).'/announcements/'.$inbox['announcement_id'].'?management=1')->assertNotFound();
        $this->postJson($this->root($org).'/announcements/'.$inbox['announcement_id'].'/comments', ['version' => $thread, 'body' => 'Contournement'])->assertNotFound();
        $this->postJson($this->root($org).'/inbox/'.$inbox['id'].'/read')->assertNotFound();
        $this->assertDatabaseCount('announcement_comments', 3);
        $this->assertDatabaseCount('communication_contexts', 1);
    }

    public function test_discussion_requires_authentication_read_and_comment_permissions(): void
    {
        $org = Organization::factory()->create();
        $source = CalendarActivity::factory()->shared()->create(['organization_id' => $org->id]);
        $path = $this->root($org).'/discussions/event/'.$source->id;
        $this->getJson($path)->assertUnauthorized();
        $this->postJson($path, [])->assertUnauthorized();
        $this->member($org, ['calendar.read']);
        $this->getJson($path)->assertForbidden();
        $this->member($org, ['communication.read', 'calendar.read']);
        $this->getJson($path)->assertOk()->assertJsonPath('context.can_comment', false);
        $this->postJson($path, ['version' => $source->version, 'thread_version' => null, 'body' => 'Non'])->assertForbidden();
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_first_discussion_and_comment_roll_back_together_when_audit_fails(): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, 'event', $user);
        $this->mock(TenantAuditJournal::class)
            ->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($this->root($org).'/discussions/event/'.$source->id,
            ['version' => $source->version, 'thread_version' => null, 'body' => 'Rollback', 'notify_members' => true])->assertStatus(500);
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('communication_contexts', 0);
        $this->assertDatabaseCount('announcement_comments', 0);
        $this->assertDatabaseCount('communication_events', 0);
    }

    public function test_context_queries_do_not_grow_with_page_size(): void
    {
        [$org, $user] = $this->editor();
        $source = $this->source($org, 'event', $user);
        for ($i = 0; $i < 4; $i++) {
            $draft = $this->postJson($this->root($org).'/announcements', ['title' => 'Annonce', 'body' => 'Texte', 'target_type' => 'event', 'target_id' => $source->id])->assertCreated()->json('data');
            $this->patchJson($this->root($org).'/announcements/'.$draft['id'], ['version' => $draft['version'], 'action' => 'publish', 'confirm_publication' => true])->assertOk();
        }
        $service = app(CommunicationService::class);
        $context = $this->personContext($org, $user);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->paginate($context, ['per_page' => 1]);
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $page = $service->paginate($context, ['per_page' => 100]);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(4, $page->total());
        $this->assertSame($small, $large);
    }

    public function test_empty_context_migration_round_trip_and_data_loss_guard(): void
    {
        $migration = require database_path('migrations/2026_11_05_123111_create_communication_contexts_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('communication_contexts'));
        $migration->up();
        [$org, $user] = $this->editor();
        $source = $this->source($org, 'event', $user);
        $this->postJson($this->root($org).'/discussions/event/'.$source->id,
            ['version' => $source->version, 'thread_version' => null, 'body' => 'Conserver'])->assertCreated();
        try {
            $migration->down();
            $this->fail('Contextual history must not be dropped.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('communication_contexts', 1);
            $this->assertDatabaseCount('announcement_comments', 1);
        }
    }

    /** @param list<string> $permissions
     * @return array{Organization, User} */
    private function editor(array $permissions = self::EDITOR): array
    {
        $org = Organization::factory()->create();

        return [$org, $this->member($org, $permissions)];
    }

    /** @param list<string> $permissions */
    private function member(Organization $org, array $permissions): User
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $user;
    }

    private function source(Organization $org, string $type, User $user): CalendarActivity|MatchRecord|AcademySession
    {
        if ($type === 'event') {
            return CalendarActivity::factory()->shared()->create(['organization_id' => $org->id, 'created_by' => $user->id]);
        }
        if ($type === 'match') {
            return MatchRecord::factory()->create(['organization_id' => $org->id, 'created_by' => $user->id, 'visibility' => 'organization',
                'sharing_authorized_by' => $user->id, 'sharing_authorized_at' => now()]);
        }
        $cohort = Cohort::factory()->create(['organization_id' => $org->id]);
        $cycle = AcademyCycle::factory()->create(['organization_id' => $org->id, 'cohort_id' => $cohort->id]);
        $mentor = AcademyEnrollment::factory()->create(['cycle_id' => $cycle->id, 'role' => 'mentor']);

        return AcademySession::factory()->create(['responsible_enrollment_id' => $mentor->id]);
    }

    private function root(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/communication';
    }
}
