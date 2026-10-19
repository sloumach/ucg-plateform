<?php

namespace Tests\Unit;

use App\Modules\Performance\Domain\Aggregation\StatisticalCalculator;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatisticalCalculatorTest extends TestCase
{
    /** @return array<string,mixed> */
    private function metric(string $operation = 'sum'): array
    {
        return ['id' => 'metric', 'version' => 'v1', 'game_id' => 'game', 'aggregation' => $operation,
            'aggregation_level' => 'period', 'zero_denominator' => 'unavailable', 'formula' => 'system(unsafe)'];
    }

    /** @return array<string,mixed> */
    private function row(string $value, int $day = 1, int $size = 1): array
    {
        return ['id' => 'row'.$day, 'metric_id' => 'metric', 'metric_version' => 'v1', 'game_id' => 'game', 'person_id' => 'person',
            'source_kind' => 'training', 'source_label' => 'Source privée', 'sample_size' => $size,
            'period_start' => '2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT), 'period_end' => '2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT),
            'observed_on' => '2026-10-19', 'created_at' => '2026-10-19T10:00:0'.$day.'Z', 'match_id' => null, 'match_game_id' => null,
            'context' => ['region' => 'Europe', 'season' => '2026', 'map' => null, 'opponent' => null, 'round_number' => null, 'session_label' => null,
                'game_context' => ['mode_id' => null, 'role_id' => null, 'release_id' => null]],
            'status' => 'active', 'availability' => 'available', 'value' => $value];
    }

    /** @return array<string,array{string,string,int}> */
    public static function operations(): array
    {
        return ['sum' => ['sum', '30', 4], 'weighted mean' => ['mean', '17.5', 4], 'minimum' => ['min', '10', 4],
            'maximum' => ['max', '20', 4], 'latest' => ['last', '20', 3]];
    }

    #[DataProvider('operations')]
    public function test_primitive_calculations_use_exact_values_and_samples(string $operation, string $expected, int $samples): void
    {
        $result = (new StatisticalCalculator)->calculate($this->metric($operation), [$this->row('10', 1), $this->row('20', 2, 3)]);
        $this->assertSame($expected, $result['value']);
        $this->assertSame($samples, $result['sample_size']);
        $this->assertFalse($result['incomplete']);
        $this->assertSame('stat-004.v1', $result['engine_version']);
    }

    public function test_decimal_sum_and_division_never_use_binary_floats(): void
    {
        $engine = new StatisticalCalculator;
        $this->assertSame('0.3', $engine->calculate($this->metric(), [$this->row('0.1'), $this->row('0.2', 2)])['value']);
        $this->assertSame('0.166667', $engine->calculate($this->metric('mean'), [$this->row('0.1'), $this->row('0.2', 2, 2)])['value']);
        $this->assertSame('-0.2', $engine->calculate($this->metric(), [$this->row('0.1'), $this->row('-0.3', 2)])['value']);
    }

    /** @return array<string,mixed> */
    private function ratioMetric(string $zero = 'unavailable'): array
    {
        return [...$this->metric('ratio'), 'id' => 'ratio', 'version' => 'ratio1', 'zero_denominator' => $zero,
            'ratio_components' => ['numerator' => [['metric_id' => 'kills', 'version' => 'kills1']], 'denominator' => [['metric_id' => 'deaths', 'version' => 'deaths1']]]];
    }

    /** @return array<string,mixed> */
    private function component(string $metric, string $value, int $day = 1, int $size = 1): array
    {
        return [...$this->row($value, $day, $size), 'id' => $metric.$day, 'metric_id' => $metric, 'metric_version' => $metric.'1'];
    }

    public function test_ratio_is_computed_from_combined_components_and_not_average_of_ratios(): void
    {
        $result = (new StatisticalCalculator)->calculate($this->ratioMetric(), [$this->component('kills', '1'), $this->component('deaths', '1'), $this->component('kills', '9', 2), $this->component('deaths', '99', 2)]);
        $this->assertSame('0.1', $result['value']);
        $this->assertSame('10', $result['numerator']);
        $this->assertSame('100', $result['denominator']);
        $this->assertSame(2, $result['sample_size']);
    }

    /** @return array<string,array{string,?string,string}> */
    public static function zeroPolicies(): array
    {
        return ['undefined' => ['unavailable', null, 'unavailable'], 'explicit zero' => ['zero', '0', 'available']];
    }

    #[DataProvider('zeroPolicies')]
    public function test_zero_denominator_follows_explicit_metric_policy(string $policy, ?string $value, string $availability): void
    {
        $result = (new StatisticalCalculator)->calculate($this->ratioMetric($policy), [$this->component('kills', '5'), $this->component('deaths', '0')]);
        $this->assertSame($value, $result['value']);
        $this->assertSame($availability, $result['availability']);
        $this->assertSame('ZERO_DENOMINATOR', $result['reason']);
    }

    public function test_missing_component_never_becomes_zero_even_under_zero_policy(): void
    {
        $result = (new StatisticalCalculator)->calculate($this->ratioMetric('zero'), [$this->component('kills', '5')]);
        $this->assertNull($result['value']);
        $this->assertSame('unavailable', $result['availability']);
        $this->assertSame('MISSING_COMPONENT', $result['reason']);
        $this->assertTrue($result['incomplete']);
    }

    public function test_voided_and_sample_mismatched_components_make_ratio_unavailable(): void
    {
        $engine = new StatisticalCalculator;
        $result = $engine->calculate($this->ratioMetric(), [$this->component('kills', '5'), [...$this->component('deaths', '1'), 'status' => 'voided']]);
        $this->assertSame('MISSING_COMPONENT', $result['reason']);
        $result = $engine->calculate($this->ratioMetric(), [$this->component('kills', '5', 1, 2), $this->component('deaths', '1')]);
        $this->assertSame('SAMPLE_MISMATCH', $result['reason']);
        $this->assertNull($result['value']);
    }

    public function test_unavailable_values_are_excluded_and_incomplete_is_explicit(): void
    {
        $result = (new StatisticalCalculator)->calculate($this->metric(), [$this->row('2'), [...$this->row('0', 2), 'availability' => 'unavailable', 'value' => null]]);
        $this->assertSame('2', $result['value']);
        $this->assertSame(1, $result['sample_size']);
        $this->assertSame(1, $result['missing_count']);
        $this->assertTrue($result['incomplete']);
    }

    public function test_manual_and_empty_active_samples_remain_unavailable(): void
    {
        $engine = new StatisticalCalculator;
        $this->assertSame('MANUAL_AGGREGATION', $engine->calculate($this->metric('manual'), [$this->row('2')])['reason']);
        $result = $engine->calculate($this->metric(), [[...$this->row('2'), 'status' => 'voided']]);
        $this->assertNull($result['value']);
        $this->assertSame(0, $result['sample_size']);
        $this->assertTrue($result['incomplete']);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function incompatible(): array
    {
        return ['player' => [['person_id' => 'other']], 'game' => [['game_id' => 'other']], 'metric version' => [['metric_version' => 'old']],
            'source' => [['source_kind' => 'official']], 'provider' => [['source_label' => 'Other provider']]];
    }

    /** @param array<string,mixed> $change */
    #[DataProvider('incompatible')]
    public function test_incompatible_contexts_are_refused(array $change): void
    {
        $this->expectException(DomainException::class);
        (new StatisticalCalculator)->calculate($this->metric(), [$this->row('2'), [...$this->row('3', 2), ...$change]]);
    }

    public function test_duplicate_origins_and_overlapping_periods_are_refused(): void
    {
        $this->expectException(DomainException::class);
        (new StatisticalCalculator)->calculate($this->metric(), [$this->row('2'), [...$this->row('3', 2), 'period_start' => '2026-10-01']]);
    }

    public function test_direct_ratio_values_cannot_replace_components(): void
    {
        $this->expectException(DomainException::class);
        (new StatisticalCalculator)->calculate($this->ratioMetric(), [[...$this->row('4'), 'metric_id' => 'ratio', 'metric_version' => 'ratio1']]);
    }
}
