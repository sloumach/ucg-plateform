<?php

namespace App\Modules\Teams\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamLineupEntry;
use Illuminate\Http\Request;

final class TeamLineupResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var TeamLineup $lineup */
        $lineup = $this->resource;

        return ['id' => $lineup->id, 'team_id' => $lineup->team_id, 'game_id' => $lineup->game_id, 'purpose' => $lineup->purpose,
            'label' => $lineup->label, 'effective_at' => $lineup->effective_at->toIso8601String(), 'status' => $lineup->status, 'version' => $lineup->version,
            'team_name' => $lineup->team_name, 'game_key' => $lineup->game_key, 'game_name' => $lineup->game_name, 'season' => $lineup->season,
            'created_by' => $lineup->created_by, 'finalized_by' => $lineup->finalized_by, 'finalized_at' => $lineup->finalized_at?->toIso8601String(),
            'ruleset_id' => $lineup->ruleBinding?->ruleset_id, 'ruleset_version' => $lineup->ruleBinding?->ruleset_version,
            'ruleset_name' => $lineup->ruleBinding?->ruleset->name, 'lock_at' => $lineup->ruleBinding?->ruleset->lock_at?->toIso8601String(),
            'eligibility_status' => $lineup->finalEligibilityReport->status ?? 'unassessed', 'eligibility_report_id' => $lineup->finalEligibilityReport?->id,
            'entries' => $lineup->entries->map(fn (TeamLineupEntry $entry): array => ['id' => $entry->id, 'membership_id' => $entry->membership_id,
                'membership_version' => $entry->membership_version, 'person_id' => $entry->person_id, 'pseudonym' => $entry->pseudonym,
                'membership_role_key' => $entry->membership_role_key, 'slot_key' => $entry->slot_key, 'position' => $entry->position])->all()];
    }
}
