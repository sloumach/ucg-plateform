<?php

namespace App\Modules\Teams\Domain\Eligibility;

use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Teams\Domain\Models\TeamLineup;
use Carbon\CarbonImmutable;

final class EligibilityEvaluator
{
    /** @param array<string,array{age_status:string,profiles:list<array{id:string,version:string,region_key:string,rank:string|null,observed_at:string|null}>}> $facts
     * @param  array<string,bool>  $roster
     * @return array{status:string,checks:list<array{code:string,status:string,person_id:string|null}>}
     */
    public function evaluate(TeamEligibilityRuleset $rules, TeamLineup $lineup, array $facts, array $roster, CarbonImmutable $now): array
    {
        $checks = [];
        $check = function (string $code, string $status, ?string $person = null) use (&$checks): void {
            $checks[] = ['code' => $code, 'status' => $status, 'person_id' => $person];
        };
        $check('rules_active', $rules->status === 'active' ? 'passed' : 'failed');
        $check('game', $rules->game_id === $lineup->game_id ? 'passed' : 'failed');
        $starters = $lineup->entries->where('slot_key', 'starter')->count();
        $substitutes = $lineup->entries->where('slot_key', 'substitute')->count();
        $check('starters', $starters >= $rules->min_starters && $starters <= $rules->max_starters ? 'passed' : 'failed');
        $check('substitutes', $substitutes <= $rules->max_substitutes ? 'passed' : 'failed');
        $onTime = $rules->lock_at === null || $now->lessThan($rules->lock_at)
            || ($lineup->status === 'finalized' && $lineup->finalized_at !== null && $lineup->finalized_at->lessThan($rules->lock_at));
        $check('deadline', $onTime ? 'passed' : 'failed');
        foreach ($lineup->entries as $entry) {
            $id = $entry->person_id;
            $check('roster', ($roster[$id] ?? false) ? 'passed' : 'failed', $id);
            $subject = $facts[$id] ?? ['age_status' => 'unknown', 'profiles' => []];
            if ($rules->min_age !== null || $rules->max_age !== null) {
                $age = $subject['age_status'];
                $check($age === 'unknown' ? 'age_missing' : 'age', $age === 'unknown' ? 'unknown' : $age, $id);
            }
            if ($rules->platform_key === null) {
                continue;
            }
            $profiles = $subject['profiles'];
            if (count($profiles) !== 1) {
                $check($profiles === [] ? 'profile_missing' : 'profile_ambiguous', 'unknown', $id);

                continue;
            }
            $profile = $profiles[0];
            if ($profile['observed_at'] === null) {
                $check('observation_missing', 'unknown', $id);

                continue;
            }
            $observed = CarbonImmutable::parse($profile['observed_at'])->utc();
            if ($observed->greaterThan($lineup->effective_at) || $observed->greaterThan($now)) {
                $check('observation_future', 'unknown', $id);

                continue;
            }
            if ($observed->lessThan($lineup->effective_at->subDays($rules->observation_max_days))) {
                $check('observation_stale', 'unknown', $id);

                continue;
            }
            if ($rules->allowed_regions !== []) {
                $check('region', in_array($profile['region_key'], $rules->allowed_regions, true) ? 'passed' : 'failed', $id);
            }
            if ($rules->allowed_ranks !== []) {
                $rank = $profile['rank'];
                if ($rank === null || trim($rank) === '') {
                    $check('rank_missing', 'unknown', $id);
                } else {
                    $check('rank', in_array(mb_strtolower(trim($rank)), array_map(fn (string $value): string => mb_strtolower(trim($value)), $rules->allowed_ranks), true) ? 'passed' : 'failed', $id);
                }
            }
        }
        $statuses = array_column($checks, 'status');

        return ['status' => in_array('failed', $statuses, true) ? 'ineligible' : (in_array('unknown', $statuses, true) ? 'incomplete' : 'eligible'), 'checks' => $checks];
    }
}
