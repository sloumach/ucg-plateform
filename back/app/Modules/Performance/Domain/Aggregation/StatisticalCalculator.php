<?php

namespace App\Modules\Performance\Domain\Aggregation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

/** Closed operations only: documentary formulas are never evaluated. */
final class StatisticalCalculator
{
    public const ENGINE_VERSION = 'stat-004.v1';

    public const MAX_SOURCES = 1000;

    public const SCALE = 6;

    /** @param array<string,mixed> $metric
     * @param  list<array<string,mixed>>  $rows
     */
    public function validate(array $metric, array $rows): void
    {
        if ($rows === [] || count($rows) > self::MAX_SOURCES) {
            throw new DomainException('SOURCES_LIMIT');
        }
        $expected = $this->components($metric);
        $cohort = null;
        $seen = [];
        $periods = [];
        foreach ($rows as $row) {
            if (! isset($expected[$row['metric_version']]) || $expected[$row['metric_version']] !== $row['metric_id'] || $row['game_id'] !== $metric['game_id']) {
                throw new DomainException('METRIC_MISMATCH');
            }
            $context = $row['context'];
            $references = array_intersect_key($context['game_context'], array_flip(['mode_id', 'role_id', 'release_id']));
            foreach ($references as $key => $value) {
                if (($metric[$key] ?? null) !== null && $metric[$key] !== $value) {
                    throw new DomainException('CONTEXT_MISMATCH');
                }
            }
            $identity = [$row['person_id'], $row['source_kind'], $row['source_label'], $references,
                array_intersect_key($context, array_flip(['region', 'season', 'map', 'opponent']))];
            $signature = json_encode($identity, JSON_THROW_ON_ERROR);
            $cohort ??= $signature;
            if ($cohort !== $signature) {
                throw new DomainException('CONTEXT_MISMATCH');
            }
            $key = $row['metric_version'].':'.$this->origin($row);
            if (isset($seen[$key])) {
                throw new DomainException('DUPLICATE_SOURCE');
            }
            $seen[$key] = true;
            if (($metric['aggregation_level'] ?? 'game') === 'period') {
                $periods[$row['metric_version']][] = [$row['period_start'], $row['period_end']];
            }
        }
        foreach ($periods as $ranges) {
            sort($ranges);
            $lastEnd = null;
            foreach ($ranges as [$start, $end]) {
                if ($lastEnd !== null && $start <= $lastEnd) {
                    throw new DomainException('OVERLAPPING_PERIODS');
                }
                $lastEnd = $end;
            }
        }
    }

    /** @param array<string,mixed> $metric
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function calculate(array $metric, array $rows): array
    {
        $this->validate($metric, $rows);
        $active = array_values(array_filter($rows, fn (array $row): bool => $row['status'] === 'active'));
        $available = array_values(array_filter($active, fn (array $row): bool => $row['availability'] === 'available'));
        $missing = count($active) - count($available);
        $base = ['engine_version' => self::ENGINE_VERSION, 'precision' => self::SCALE, 'rounding' => 'half_up',
            'availability' => 'unavailable', 'value' => null, 'sample_size' => 0,
            'source_count' => count($rows), 'included_count' => count($available), 'missing_count' => $missing,
            'voided_count' => count($rows) - count($active), 'incomplete' => $missing > 0 || count($rows) !== count($active),
            'numerator' => null, 'denominator' => null, 'reason' => null];
        if ($metric['aggregation'] === 'manual') {
            return [...$base, 'reason' => 'MANUAL_AGGREGATION'];
        }
        if ($metric['aggregation'] === 'ratio') {
            return $this->ratio($metric, $rows, $base);
        }
        if ($available === []) {
            return [...$base, 'reason' => 'NO_AVAILABLE_VALUES'];
        }
        $sum = BigDecimal::of(0);
        $weighted = BigDecimal::of(0);
        $samples = 0;
        foreach ($available as $row) {
            $sum = $sum->plus($row['value']);
            $weighted = $weighted->plus(BigDecimal::of($row['value'])->multipliedBy($row['sample_size']));
            $samples += $row['sample_size'];
        }
        $value = match ($metric['aggregation']) {
            'sum' => $sum,
            'mean' => $weighted->dividedBy($samples, self::SCALE, RoundingMode::HalfUp),
            'min', 'max' => array_reduce($available, function (BigDecimal $selected, array $row) use ($metric): BigDecimal {
                $value = BigDecimal::of($row['value']);

                return ($metric['aggregation'] === 'min' ? $value->compareTo($selected) < 0 : $value->compareTo($selected) > 0) ? $value : $selected;
            }, BigDecimal::of($available[0]['value'])),
            'last' => $this->last($available),
            default => throw new DomainException('UNSUPPORTED_AGGREGATION'),
        };
        if ($metric['aggregation'] === 'last') {
            usort($available, fn (array $a, array $b): int => $this->timeKey($b) <=> $this->timeKey($a));
            $samples = $available[0]['sample_size'];
        }

        return [...$base, 'availability' => 'available', 'value' => $this->decimal($value), 'sample_size' => $samples];
    }

    /** @param array<string,mixed> $metric
     * @return array<string,string> revision => metric
     */
    private function components(array $metric): array
    {
        if ($metric['aggregation'] !== 'ratio') {
            return [$metric['version'] => $metric['id']];
        }
        $recipe = $metric['ratio_components'] ?? null;
        if (! is_array($recipe) || ($recipe['numerator'] ?? []) === [] || ($recipe['denominator'] ?? []) === []) {
            throw new DomainException('RATIO_COMPONENTS_REQUIRED');
        }
        $components = [];
        foreach ([...$recipe['numerator'], ...$recipe['denominator']] as $component) {
            $components[$component['version']] = $component['metric_id'];
        }

        return $components;
    }

    /** @param array<string,mixed> $metric
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function ratio(array $metric, array $rows, array $base): array
    {
        $expected = $this->components($metric);
        $groups = [];
        foreach ($rows as $row) {
            $groups[$this->origin($row)][$row['metric_version']] = $row;
        }
        $totals = ['numerator' => BigDecimal::of(0), 'denominator' => BigDecimal::of(0)];
        $samples = 0;
        foreach ($groups as $group) {
            if (count($group) !== count($expected)) {
                return [...$base, 'incomplete' => true, 'reason' => 'MISSING_COMPONENT'];
            }
            $size = null;
            foreach ($group as $row) {
                if ($row['status'] !== 'active' || $row['availability'] !== 'available') {
                    return [...$base, 'incomplete' => true, 'reason' => 'MISSING_COMPONENT'];
                }
                $size ??= $row['sample_size'];
                if ($size !== $row['sample_size']) {
                    return [...$base, 'incomplete' => true, 'reason' => 'SAMPLE_MISMATCH'];
                }
            }
            $samples += $size;
            foreach ($totals as $side => $total) {
                foreach ($metric['ratio_components'][$side] as $component) {
                    $total = $total->plus($group[$component['version']]['value']);
                }
                $totals[$side] = $total;
            }
        }
        $base = [...$base, 'sample_size' => $samples, 'numerator' => $this->decimal($totals['numerator']),
            'denominator' => $this->decimal($totals['denominator'])];
        if ($totals['denominator']->isZero()) {
            return [...$base, 'availability' => $metric['zero_denominator'] === 'zero' ? 'available' : 'unavailable',
                'value' => $metric['zero_denominator'] === 'zero' ? '0' : null, 'reason' => 'ZERO_DENOMINATOR'];
        }

        return [...$base, 'availability' => 'available', 'value' => $this->decimal($totals['numerator']->dividedBy($totals['denominator'], self::SCALE, RoundingMode::HalfUp))];
    }

    /** @param array<string,mixed> $row */
    private function origin(array $row): string
    {
        return json_encode([array_intersect_key($row, array_flip(['match_id', 'match_game_id', 'period_start', 'period_end', 'observed_on'])),
            $row['context']['round_number'] ?? null, $row['context']['session_label'] ?? null], JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string,mixed>> $rows */
    private function last(array $rows): BigDecimal
    {
        usort($rows, fn (array $a, array $b): int => $this->timeKey($b) <=> $this->timeKey($a));

        return BigDecimal::of($rows[0]['value']);
    }

    /** @param array<string,mixed> $row */
    private function timeKey(array $row): string
    {
        return $row['observed_on'].':'.$row['created_at'].':'.$row['id'];
    }

    private function decimal(BigDecimal $value): string
    {
        $value = rtrim(rtrim((string) $value->toScale(self::SCALE, RoundingMode::HalfUp), '0'), '.');

        return $value === '-0' ? '0' : $value;
    }
}
