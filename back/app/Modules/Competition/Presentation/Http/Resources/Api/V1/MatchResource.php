<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\MatchWorkflow;
use App\Modules\Competition\Domain\Models\MatchParticipant;
use App\Modules\Competition\Domain\Models\MatchRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchRecord */
final class MatchResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'kind' => $this->kind, 'format' => $this->format,
            'game_id' => $this->game_id, 'game_key' => $this->game_key, 'game_name' => $this->game_name,
            'status' => $this->status, 'version' => $this->version, 'visibility' => $this->visibility,
            'starts_at' => $this->starts_at->toIso8601String(), 'ends_at' => $this->ends_at->toIso8601String(), 'origin_timezone' => $this->origin_timezone,
            'starts_at_origin' => $this->starts_at->setTimezone($this->origin_timezone)->toIso8601String(),
            'ends_at_origin' => $this->ends_at->setTimezone($this->origin_timezone)->toIso8601String(),
            'responsible_person_id' => $this->responsible_person_id, 'responsible_label' => $this->responsible_label,
            'participants' => $this->participants->map(fn (MatchParticipant $p): array => ['id' => $p->id, 'kind' => $p->kind, 'source_id' => $p->sourceId(),
                'label' => $p->label, 'reference_version' => $p->reference_version, 'slot' => $p->slot])->values()->all(),
            'editable' => $this->status === 'proposed', 'available_transitions' => MatchWorkflow::operational($this->status)];
    }
}
