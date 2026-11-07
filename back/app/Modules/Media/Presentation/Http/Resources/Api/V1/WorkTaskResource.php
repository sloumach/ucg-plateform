<?php

namespace App\Modules\Media\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Media\Domain\Models\WorkTask;
use Illuminate\Http\Request;

final class WorkTaskResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WorkTask $task */
        $task = $this->resource;

        return ['id' => $task->id, 'title' => $task->title, 'description' => $task->description,
            'assignee_id' => $task->assignee_id, 'assignee_label' => $task->getAttribute('assignee_label'),
            'due_on' => $task->due_on, 'status' => $task->status, 'version' => $task->version,
            'created_at' => $task->created_at?->toIso8601String(), 'updated_at' => $task->updated_at?->toIso8601String()];
    }
}
