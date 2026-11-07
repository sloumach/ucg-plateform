<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Media\Domain\Contracts\WorkTaskRepository;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\RetentionControlService;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkTaskSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_empty_round_trip_recreates_guards_and_complete_export_inventory(): void
    {
        $this->assertDatabaseCount('work_tasks', 0);
        $migration = require database_path('migrations/2026_11_07_145755_create_work_tasks_and_revisions.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('work_tasks'));
        $migration->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        $task = WorkTask::factory()->create();
        WorkTask::factory()->create();
        app(WorkTaskRepository::class)->revise($task, $task->created_by);
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($task->organization_id, 1000));
        foreach (['work_tasks', 'work_task_revisions'] as $table) {
            $exported = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === $table));
            $this->assertCount(1, $exported);
            $this->assertSame($task->organization_id, $exported[0]['data']['organization_id']);
        }
        $this->reject(fn () => DB::table('work_tasks')->where('id', $task->id)->delete());
    }

    public function test_sql_preserves_identity_history_and_tenant_foreign_keys(): void
    {
        $task = WorkTask::factory()->create();
        $foreign = Person::factory()->create();
        $records = app(WorkTaskRepository::class);
        $records->revise($task, $task->created_by);
        foreach ([['organization_id' => $foreign->organization_id], ['announcement_id' => (string) Str::uuid()], ['status' => 'removed'], ['assignee_id' => $foreign->id]] as $attributes) {
            $this->reject(fn () => DB::table('work_tasks')->where('id', $task->id)->update([...$attributes, 'version' => (string) Str::uuid()]));
        }
        $this->reject(fn () => DB::table('work_tasks')->where('id', $task->id)->update(['title' => 'No version']));
        $this->reject(fn () => DB::table('work_task_revisions')->where('task_id', $task->id)->update(['snapshot' => 'Overwrite']));
        $this->reject(fn () => DB::table('work_task_revisions')->where('task_id', $task->id)->delete());
        $this->assertDatabaseCount('work_task_revisions', 1);
        $this->assertDatabaseHas('work_tasks', ['id' => $task->id, 'version' => $task->version]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Work task rollback cannot discard history.');
        $migration = require database_path('migrations/2026_11_07_145755_create_work_tasks_and_revisions.php');
        $migration->down();
    }

    public function test_hold_blocks_task_updates_and_revision_inserts_in_sql(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $task = WorkTask::factory()->create(['organization_id' => $org->id]);
        config(['retention.control_enabled' => true, 'retention.control_operators' => [$org->owner_user_id => [$org->id]]]);
        $audit = new OrganizationAuditData($org->owner_user_id, 'Fixture task preservation', (string) Str::uuid7());
        app(RetentionControlService::class)->run($org->id, $audit, 'hold', 0, 'task-hold', $org->id);
        $this->reject(fn () => DB::table('work_tasks')->where('id', $task->id)->update(['status' => 'done', 'version' => (string) Str::uuid()]));
        $this->reject(fn () => app(WorkTaskRepository::class)->revise($task, $task->created_by));
        $this->assertDatabaseCount('work_task_revisions', 0);
        app(RetentionControlService::class)->run($org->id, new OrganizationAuditData($org->owner_user_id, 'Fixture task release', (string) Str::uuid7()), 'release-hold', 1, 'task-hold', $org->id);
        $this->assertSame(1, DB::table('work_tasks')->where('id', $task->id)->update(['status' => 'done', 'version' => (string) Str::uuid()]));
    }

    /** @param \Closure(): mixed $attempt */
    private function reject(\Closure $attempt): void
    {
        try {
            DB::transaction($attempt);
            $this->fail('Task schema violation must be rejected.');
        } catch (QueryException $error) {
            $this->assertContains($error->errorInfo[0] ?? (string) $error->getCode(), ['23000', '23503', '23514', 'P0001']);
        }
    }
}
