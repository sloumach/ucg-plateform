<?php

namespace Tests\Unit;

use App\Modules\Tenancy\Domain\RetentionCatalog;
use App\Modules\Tenancy\Domain\RetentionReviewPolicy;
use App\Modules\Tenancy\Infrastructure\Persistence\DatabasePortabilityRepository;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RetentionReviewPolicyTest extends TestCase
{
    public function test_closed_families_cover_every_portability_table_exactly_once(): void
    {
        $tables = RetentionCatalog::tables();
        $expected = array_keys(DatabasePortabilityRepository::TABLES);
        sort($tables);
        sort($expected);

        $this->assertCount(18, RetentionCatalog::FAMILIES);
        $this->assertCount(116, $tables);
        $this->assertSame($expected, $tables);
        $this->assertSame(count($tables), count(array_unique($tables)));
    }

    public function test_missing_policy_never_supplies_a_duration_or_version(): void
    {
        $policy = RetentionReviewPolicy::fromConfiguration(null, CarbonImmutable::parse('2026-10-27', 'UTC'));

        $this->assertSame('missing', $policy->status);
        $this->assertNull($policy->version);
        $this->assertNull($policy->rule('people'));
    }

    public function test_explicit_zero_day_rule_is_distinct_from_a_missing_duration(): void
    {
        $value = self::policy();
        $value['rules']['people']['active_days'] = 0;
        $policy = RetentionReviewPolicy::fromConfiguration($value, CarbonImmutable::parse('2026-10-27', 'UTC'));

        $this->assertSame('configured_review_only', $policy->status);
        $this->assertSame('fixture-v1', $policy->version);
        $rule = $policy->rule('people');
        $this->assertNotNull($rule);
        $this->assertSame(0, $rule['active_days']);
        $this->assertNull($policy->rule('contracts'));
    }

    public function test_policy_content_is_fingerprinted_even_if_an_operator_reuses_the_same_version(): void
    {
        $value = self::policy();
        $date = CarbonImmutable::parse('2026-10-27', 'UTC');
        $first = RetentionReviewPolicy::fromConfiguration($value, $date);
        $value['rules']['people']['active_days'] = 60;

        $changed = RetentionReviewPolicy::fromConfiguration($value, $date);

        $this->assertSame($first->version, $changed->version);
        $this->assertNotNull($first->fingerprint);
        $this->assertNotSame($first->fingerprint, $changed->fingerprint);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidPolicies(): array
    {
        $cases = [];
        foreach (['version' => '', 'reviewed_on' => '2026-02-30', 'responsible_reference' => '', 'validation_reference' => '', 'reviewed' => false, 'rules' => []] as $key => $value) {
            $policy = self::policy();
            $policy[$key] = $value;
            $cases['invalid '.$key] = [$policy];
        }
        foreach (['event' => 'updated_at', 'active_days' => null, 'archive_days' => -1, 'final_action' => 'delete'] as $key => $value) {
            $policy = self::policy();
            $policy['rules']['people'][$key] = $value;
            $cases['invalid rule '.$key] = [$policy];
        }
        $policy = self::policy();
        $policy['rules']['unknown_family'] = $policy['rules']['people'];
        $cases['unknown family'] = [$policy];
        $policy = self::policy();
        $policy['execute'] = true;
        $cases['extra option'] = [$policy];
        $policy = self::policy();
        $policy['reviewed_on'] = '2026-10-28';
        $cases['future review'] = [$policy];
        $policy = self::policy();
        $policy['rules']['contracts'] = $policy['rules']['people'];
        $cases['unsupported event for another family'] = [$policy];

        return $cases;
    }

    /** @param array<string, mixed> $value */
    #[DataProvider('invalidPolicies')]
    public function test_incomplete_or_unsafe_policy_produces_no_rules(array $value): void
    {
        $policy = RetentionReviewPolicy::fromConfiguration($value, CarbonImmutable::parse('2026-10-27', 'UTC'));

        $this->assertSame('invalid', $policy->status);
        $this->assertNull($policy->version);
        $this->assertNull($policy->rule('people'));
    }

    /** @return array<string, mixed> */
    public static function policy(): array
    {
        return ['version' => 'fixture-v1', 'reviewed_on' => '2026-10-27', 'responsible_reference' => 'fixture-responsible',
            'validation_reference' => 'fixture-validation', 'reviewed' => true,
            'rules' => ['people' => ['event' => 'recorded_departure', 'active_days' => 30, 'archive_days' => 60, 'final_action' => 'review']]];
    }
}
