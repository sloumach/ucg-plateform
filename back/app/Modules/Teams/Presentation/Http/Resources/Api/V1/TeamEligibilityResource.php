<?php

namespace App\Modules\Teams\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Teams\Domain\Models\TeamLineupEligibilityReport;
use Illuminate\Http\Request;

final class TeamEligibilityResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof TeamLineupEligibilityReport) {
            $report = $this->resource;

            return ['id' => $report->id, 'lineup_id' => $report->lineup_id, 'lineup_version' => $report->lineup_version,
                'ruleset_id' => $report->ruleset_id, 'ruleset_version' => $report->ruleset_version, 'stage' => $report->stage, 'status' => $report->status,
                'evaluated_at' => $report->evaluated_at->toIso8601String(), 'actor_user_id' => $report->actor_user_id,
                'checks' => array_map(fn (array $check): array => [...$check, 'message' => __('team_eligibility.checks.'.$check['code'])], $report->checks)];
        }
        /** @var TeamEligibilityRuleset $rule */
        $rule = $this->resource;

        return ['id' => $rule->id, 'name' => $rule->name, 'game_id' => $rule->game_id, 'status' => $rule->status, 'version' => $rule->version,
            'min_starters' => $rule->min_starters, 'max_starters' => $rule->max_starters, 'max_substitutes' => $rule->max_substitutes,
            'platform_key' => $rule->platform_key, 'allowed_regions' => $rule->allowed_regions, 'allowed_ranks' => $rule->allowed_ranks,
            'min_age' => $rule->min_age, 'max_age' => $rule->max_age, 'observation_max_days' => $rule->observation_max_days,
            'lock_at' => $rule->lock_at?->toIso8601String()];
    }
}
