<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class WorkTaskTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guests_receive_401_and_readers_cannot_create_tasks(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org))->assertUnauthorized();
        $this->editor($org, ['tasks.read']);
        $this->getJson($this->path($org))->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($this->path($org), ['title' => 'Interdit'])->assertForbidden();
        $this->getJson($this->path($org).'/people')->assertForbidden();
        $this->assertDatabaseCount('work_tasks', 0);
    }

    public function test_create_edit_assign_complete_and_reopen_preserve_encrypted_history(): void
    {
        $org = Organization::factory()->create();
        $this->editor($org);
        $person = Person::factory()->create(['organization_id' => $org->id, 'status' => 'active', 'pseudonym' => 'Camille']);
        $root = $this->path($org);
        $data = $this->postJson($root, ['title' => ' Dossier tournoi ', 'description' => '<script>Pièces</script>', 'assignee_id' => $person->id, 'due_on' => '2026-12-15'])
            ->assertCreated()->assertJsonPath('data.title', 'Dossier tournoi')->assertJsonPath('data.assignee_label', 'Camille')
            ->assertJsonPath('data.status', 'todo')->assertJsonPath('notification.type', 'success')->json('data');
        foreach (['in_progress', 'done', 'todo'] as $status) {
            $data = $this->patchJson($root.'/'.$data['id'], ['version' => $data['version'], 'status' => $status])
                ->assertOk()->assertJsonPath('data.status', $status)->json('data');
        }
        $this->patchJson($root.'/'.$data['id'], ['version' => $data['version'], 'assignee_id' => null, 'due_on' => null, 'description' => null])
            ->assertOk()->assertJsonPath('data.assignee_id', null)->assertJsonPath('data.due_on', null);
        $this->assertDatabaseCount('work_tasks', 1);
        $this->assertDatabaseCount('work_task_revisions', 5);
        $this->assertDatabaseCount('announcements', 1);
        $this->assertStringNotContainsString('Dossier tournoi', DB::table('work_tasks')->value('title'));
        $this->assertStringNotContainsString('Pièces', DB::table('work_task_revisions')->orderBy('number')->value('snapshot'));
        $this->getJson('/api/v1/tenants/'.$org->id.'/communication/announcements')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_stale_edit_returns_409_without_partial_history(): void
    {
        $org = Organization::factory()->create();
        $this->editor($org);
        $data = $this->postJson($this->path($org), ['title' => 'Version initiale'])->assertCreated()->json('data');
        $this->patchJson($this->path($org).'/'.$data['id'], ['version' => $data['version'], 'title' => 'Version actuelle'])->assertOk();
        $this->patchJson($this->path($org).'/'.$data['id'], ['version' => $data['version'], 'title' => 'Version perdue'])
            ->assertConflict()->assertJsonPath('code', 'WORK_TASK_VERSION_STALE')->assertJsonPath('message', 'Cette tâche a changé. Rechargez-la avant de réessayer.');
        $this->getJson($this->path($org).'/'.$data['id'])->assertOk()->assertJsonPath('data.title', 'Version actuelle');
        $this->assertDatabaseCount('work_task_revisions', 2);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_tasks_and_assignees_return_404_without_writing(): void
    {
        $org = Organization::factory()->create();
        $this->editor($org);
        $foreign = WorkTask::factory()->create();
        $person = Person::factory()->create();
        $this->getJson($this->path($org).'/'.$foreign->id)->assertNotFound();
        $this->patchJson($this->path($org).'/'.$foreign->id, ['version' => $foreign->version, 'title' => 'Intrusion'])->assertNotFound();
        $this->postJson($this->path($org), ['title' => 'Intrusion', 'assignee_id' => $person->id])->assertNotFound();
        $this->assertDatabaseCount('work_tasks', 1);
        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseCount('work_task_revisions', 0);
    }

    public function test_empty_edit_returns_422_without_changing_the_version_or_history(): void
    {
        $org = Organization::factory()->create();
        $this->editor($org);
        $task = WorkTask::factory()->create(['organization_id' => $org->id]);
        $this->patchJson($this->path($org).'/'.$task->id, ['version' => $task->version])->assertUnprocessable()
            ->assertJsonPath('errors.task.0', 'Renseignez au moins une modification de la tâche.');
        $this->assertDatabaseHas('work_tasks', ['id' => $task->id, 'version' => $task->version]);
        $this->assertDatabaseCount('work_task_revisions', 0);
    }

    public function test_former_people_are_not_assignable_or_exposed_in_choices(): void
    {
        $org = Organization::factory()->create();
        $this->editor($org);
        $former = Person::factory()->create(['organization_id' => $org->id, 'status' => 'former']);
        Person::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
        Person::factory()->create();
        $this->getJson($this->path($org).'/people?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data');
        $this->postJson($this->path($org), ['title' => 'Impossible', 'assignee_id' => $former->id])->assertNotFound();
        $this->assertDatabaseCount('work_tasks', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInput(): array
    {
        return ['missing title' => [[], 'title'], 'blank title' => [['title' => '   '], 'title'],
            'long title' => [['title' => str_repeat('x', 161)], 'title'], 'long description' => [['description' => str_repeat('x', 4001)], 'description'],
            'unknown status' => [['status' => 'deleted'], 'status'], 'bad date' => [['due_on' => '2026-02-30'], 'due_on'],
            'invalid reference' => [['assignee_id' => 'abc'], 'assignee_id'], 'unknown field' => [['organization_id' => 'spoof'], 'organization_id'],
            'thread spoof' => [['announcement_id' => 'spoof'], 'announcement_id']];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidInput')]
    public function test_invalid_input_returns_422_without_creating_a_thread(array $input, string $field): void
    {
        $org = Organization::factory()->create();
        $this->editor($org);
        $payload = $input === [] ? [] : ['title' => 'Valide', ...$input];
        $this->postJson($this->path($org), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('work_tasks', 0);
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_lists_are_paginated_filtered_and_resolve_labels_in_one_query(): void
    {
        $org = Organization::factory()->create();
        $this->editor($org);
        $person = Person::factory()->create(['organization_id' => $org->id, 'status' => 'active']);
        WorkTask::factory()->count(3)->create(['organization_id' => $org->id, 'assignee_id' => $person->id, 'due_on' => '2026-12-15']);
        WorkTask::factory()->create(['organization_id' => $org->id, 'status' => 'done']);
        WorkTask::factory()->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson($this->path($org).'?status=todo&assignee_id='.$person->id.'&due_before=2026-12-16&per_page=2&page=2')
                ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
            $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "people"'));
            $this->assertCount(1, $queries);
        } finally {
            DB::disableQueryLog();
        }
        $this->getJson($this->path($org).'?per_page=101')->assertUnprocessable();
        $this->getJson($this->path($org).'?sort=spoof')->assertUnprocessable();
    }

    #[Group('tenant-isolation')]
    public function test_task_discussion_and_notifications_disappear_after_task_permission_revocation(): void
    {
        $org = Organization::factory()->create();
        $author = $this->editor($org);
        $task = $this->postJson($this->path($org), ['title' => 'Discussion privée'])->assertCreated()->json('data');
        $root = '/api/v1/tenants/'.$org->id.'/communication';
        $path = $root.'/discussions/task/'.$task['id'];
        $context = $this->getJson($path)->assertOk()->json('context');
        $this->patchJson($root.'/preferences', ['version' => null, 'announcements_enabled' => true, 'comments_enabled' => true])->assertOk();
        $reader = $this->editor($org, ['tasks.read', 'communication.read', 'communication.comment']);
        $comment = $this->postJson($path, ['version' => $context['version'], 'thread_version' => $context['thread_version'], 'body' => 'Réponse', 'notify_members' => true])
            ->assertCreated()->json('data');
        $this->actingAsWithMfa($author);
        $this->getJson($root.'/inbox')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($root.'/announcements/'.$comment['announcement_id'])->assertOk()->assertJsonPath('data.target_type', 'task')->assertJsonPath('data.discussion', true);
        $grant = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $author->id)->firstOrFail();
        $grant->role->forceFill(['permissions' => ['communication.read', 'communication.write', 'communication.comment']])->save();
        $this->getJson($path)->assertForbidden();
        $this->getJson($root.'/announcements/'.$comment['announcement_id'].'?management=1')->assertNotFound();
        $this->getJson($root.'/inbox')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($path, ['version' => $context['version'], 'thread_version' => $context['thread_version'], 'body' => 'Refus'])->assertForbidden();
        $this->assertDatabaseCount('announcement_comments', 1);
        $this->assertNotSame($author->id, $reader->id);
    }

    public function test_audit_failure_rolls_back_task_thread_and_revision(): void
    {
        $org = Organization::factory()->create();
        $this->editor($org);
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($this->path($org), ['title' => 'Atomicité'])->assertStatus(500);
        $this->assertDatabaseCount('work_tasks', 0);
        $this->assertDatabaseCount('work_task_revisions', 0);
        $this->assertDatabaseCount('announcements', 0);
    }

    /** @param list<string> $permissions */
    private function editor(Organization $org, array $permissions = ['tasks.read', 'tasks.manage', 'communication.read', 'communication.comment']): User
    {
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $user;
    }

    private function path(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/tasks';
    }
}
