<?php

namespace App\Modules\Media\Infrastructure\Persistence;

use App\Modules\Media\Domain\Contracts\WorkTaskRepository;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\Media\Domain\Models\WorkTaskRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

final readonly class EloquentWorkTaskRepository implements WorkTaskRepository
{
    public function __construct(private ConnectionInterface $database) {}

    public function paginate(string $org, array $filters): LengthAwarePaginator
    {
        $query = WorkTask::query()->where('organization_id', $org);
        foreach (['status', 'assignee_id'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (isset($filters['due_before'])) {
            $query->where('due_on', '<=', $filters['due_before']);
        }

        return $query->orderByDesc('created_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $id, bool $lock = false): WorkTask
    {
        $query = WorkTask::query()->where('organization_id', $org);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function save(?WorkTask $task, array $values): WorkTask
    {
        $task ??= new WorkTask;
        $task->forceFill($values)->save();

        return $task;
    }

    public function revise(WorkTask $task, int $actor): void
    {
        $number = (int) WorkTaskRevision::query()->where('organization_id', $task->organization_id)->where('task_id', $task->id)->max('number') + 1;
        $revision = new WorkTaskRevision;
        $revision->forceFill(['organization_id' => $task->organization_id, 'task_id' => $task->id, 'number' => $number,
            'actor_user_id' => $actor, 'snapshot' => ['title' => $task->title, 'description' => $task->description,
                'assignee_id' => $task->assignee_id, 'due_on' => $task->due_on, 'status' => $task->status, 'version' => $task->version], 'created_at' => now()])->save();
    }

    public function visibleIds(string $org): Builder
    {
        return $this->database->table('work_tasks')->where('organization_id', $org)->select('id');
    }
}
