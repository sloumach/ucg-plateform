<?php

namespace App\Modules\Media\Domain\Contracts;

use App\Modules\Media\Domain\Models\WorkTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface WorkTaskRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, WorkTask> */
    public function paginate(string $org, array $filters): LengthAwarePaginator;

    public function find(string $org, string $id, bool $lock = false): WorkTask;

    /** @param array<string, mixed> $values */
    public function save(?WorkTask $task, array $values): WorkTask;

    public function revise(WorkTask $task, int $actor): void;

    public function visibleIds(string $org): Builder;
}
