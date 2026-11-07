<?php

namespace App\Modules\Media\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Media\Application\Validation\WorkTaskInput;
use App\Modules\Media\Domain\Contracts\CommunicationRepository;
use App\Modules\Media\Domain\Contracts\WorkTaskRepository;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\People\Application\Contracts\WorkTaskPeopleDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class WorkTaskService
{
    public function __construct(private WorkTaskRepository $records, private CommunicationRepository $discussions, private WorkTaskPeopleDirectory $people,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit,
        private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $input
     * @return LengthAwarePaginator<int, WorkTask> */
    public function paginate(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->authorize($context);
        $page = $this->records->paginate($context->organizationId, WorkTaskInput::validate($this->validator, $input, 'index'));
        $this->decorate($context, array_values($page->items()));

        return $page;
    }

    public function find(TenantContext $context, string $id): WorkTask
    {
        $this->authorize($context);
        $task = $this->records->find($context->organizationId, $id);
        $this->decorate($context, [$task]);

        return $task;
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ip = null): WorkTask
    {
        $this->authorize($context, true);
        $values = WorkTaskInput::validate($this->validator, $input, $id === null ? 'store' : 'update');
        $task = $this->database->transaction(function () use ($context, $values, $id, $ip): WorkTask {
            $this->guard->lock($context);
            $this->authorize($context, true);
            $task = $id === null ? null : $this->records->find($context->organizationId, $id, true);
            if ($task !== null && $task->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('communication.task_stale'), 'WORK_TASK_VERSION_STALE');
            }
            $attributes = array_diff_key($values, ['version' => true]);
            if ($attributes === []) {
                throw ValidationException::withMessages(['task' => __('communication.task_empty')]);
            }
            foreach (['title', 'description'] as $field) {
                if (isset($attributes[$field])) {
                    $attributes[$field] = trim($attributes[$field]);
                }
            }
            if (isset($attributes['assignee_id'])) {
                $attributes['assignee_id'] = strtolower($attributes['assignee_id']);
                $this->people->assertAssignable($context, $attributes['assignee_id']);
            }
            if ($task === null) {
                // Each task owns one internal discussion; neither publication rights nor a public announcement are needed.
                $thread = $this->discussions->save(null, ['organization_id' => $context->organizationId, 'title' => $attributes['title'],
                    'body' => __('communication.task_discussion'), 'status' => 'draft', 'target_type' => 'organization',
                    'comments_enabled' => true, 'created_by' => $context->actorUserId, 'version' => (string) Str::uuid()]);
                $attributes = [...$attributes, 'organization_id' => $context->organizationId, 'announcement_id' => $thread->id,
                    'created_by' => $context->actorUserId, 'status' => $attributes['status'] ?? 'todo'];
            }
            $saved = $this->records->save($task, [...$attributes, 'version' => (string) Str::uuid()]);
            if (isset($thread)) {
                $this->discussions->save($thread, ['status' => 'published', 'published_by' => $context->actorUserId, 'published_at' => now()]);
            }
            $this->records->revise($saved, $context->actorUserId);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
                $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['work_task_id' => $saved->id, 'work_task_action' => $id === null ? 'created' : 'updated']));

            return $saved;
        });
        $this->decorate($context, [$task]);

        return $task;
    }

    /** @param array<string, mixed> $input
     * @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function people(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->authorize($context, true);
        $values = WorkTaskInput::validate($this->validator, $input, 'people');

        return $this->people->choices($context, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
    }

    /** @param list<WorkTask> $tasks */
    private function decorate(TenantContext $context, array $tasks): void
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (WorkTask $task): ?string => $task->assignee_id, $tasks))));
        $labels = $this->people->labels($context, $ids);
        foreach ($tasks as $task) {
            $task->setAttribute('assignee_label', $task->assignee_id === null ? null : ($labels[$task->assignee_id] ?? null));
        }
    }

    private function authorize(TenantContext $context, bool $write = false): void
    {
        $this->authorization->authorize($context, 'tasks.read');
        if ($write) {
            $this->authorization->authorize($context, 'tasks.manage');
        }
    }
}
