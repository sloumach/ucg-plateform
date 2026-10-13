<?php

namespace Tests\Unit;

use App\Modules\Teams\Domain\Eligibility\EligibilityEvaluator;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamLineupEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EligibilityEvaluatorTest extends TestCase
{
    /** @return array<string,array{array<string,mixed>,string,string}> */
    public static function profiles(): array
    {
        return [
            'missing' => [['profiles' => []], 'incomplete', 'profile_missing'],
            'ambiguous' => [['duplicate' => true], 'incomplete', 'profile_ambiguous'],
            'undated' => [['observed_at' => null], 'incomplete', 'observation_missing'],
            'future' => [['observed_at' => '2026-10-14T00:00:00Z'], 'incomplete', 'observation_future'],
            'stale' => [['observed_at' => '2026-09-12T23:59:59Z'], 'incomplete', 'observation_stale'],
            'fresh boundary' => [['observed_at' => '2026-09-13T00:00:00Z'], 'eligible', 'region'],
            'wrong region' => [['region_key' => 'na'], 'ineligible', 'region'],
            'wrong rank' => [['rank' => 'Bronze'], 'ineligible', 'rank'],
            'no rank' => [['rank' => null], 'incomplete', 'rank_missing'],
            'case and trim' => [['rank' => ' DIAMOND '], 'eligible', 'rank'],
        ];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('profiles')]
    public function test_profile_boundaries_and_unknown_data(array $changes, string $status, string $code): void
    {
        $rule = (new TeamEligibilityRuleset)->forceFill(['game_id' => 'game', 'status' => 'active', 'min_starters' => 1, 'max_starters' => 1, 'max_substitutes' => 0,
            'platform_key' => 'pc', 'allowed_regions' => ['eu'], 'allowed_ranks' => ['diamond'], 'min_age' => null, 'max_age' => null, 'observation_max_days' => 30, 'lock_at' => null]);
        $lineup = (new TeamLineup)->forceFill(['game_id' => 'game', 'status' => 'draft', 'effective_at' => '2026-10-13 00:00:00']);
        $lineup->setRelation('entries', new Collection([(new TeamLineupEntry)->forceFill(['person_id' => 'person', 'slot_key' => 'starter'])]));
        $profile = ['id' => 'profile', 'version' => 'v', 'region_key' => 'eu', 'rank' => 'Diamond', 'observed_at' => '2026-10-12T00:00:00Z'];
        $profiles = isset($changes['profiles']) ? $changes['profiles'] : [[...$profile, ...array_diff_key($changes, ['duplicate' => true, 'profiles' => true])]];
        if (isset($changes['duplicate'])) {
            $profiles[] = $profile;
        }
        $result = (new EligibilityEvaluator)->evaluate($rule, $lineup, ['person' => ['age_status' => 'not_requested', 'profiles' => $profiles]], ['person' => true], CarbonImmutable::parse('2026-10-13T12:00:00Z'));
        $this->assertSame($status, $result['status']);
        $this->assertContains($code, array_column($result['checks'], 'code'));
    }

    public function test_deadline_is_exclusive_and_count_refusal_dominates_missing_age(): void
    {
        $rule = (new TeamEligibilityRuleset)->forceFill(['game_id' => 'g', 'status' => 'active', 'min_starters' => 2, 'max_starters' => 5, 'max_substitutes' => 0,
            'min_age' => 18, 'max_age' => null, 'platform_key' => null, 'lock_at' => '2026-10-13 12:00:00']);
        $lineup = (new TeamLineup)->forceFill(['game_id' => 'g', 'status' => 'draft', 'effective_at' => '2026-10-13 11:00:00']);
        $lineup->setRelation('entries', new Collection([(new TeamLineupEntry)->forceFill(['person_id' => 'p', 'slot_key' => 'starter'])]));
        $result = (new EligibilityEvaluator)->evaluate($rule, $lineup, [], ['p' => true], CarbonImmutable::parse('2026-10-13T12:00:00Z'));
        $this->assertSame('ineligible', $result['status']);
        $by = array_column($result['checks'], 'status', 'code');
        $this->assertSame('failed', $by['deadline']);
        $this->assertSame('failed', $by['starters']);
        $this->assertSame('unknown', $by['age_missing']);
    }
}
