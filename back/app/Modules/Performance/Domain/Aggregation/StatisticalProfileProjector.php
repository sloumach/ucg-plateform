<?php

namespace App\Modules\Performance\Domain\Aggregation;

use Brick\Math\BigDecimal;
use DomainException;

final readonly class StatisticalProfileProjector
{
    public function __construct(private StatisticalCalculator $calculator) {}

    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    public function context(array $row): array
    {
        $context = ['source_kind' => $row['source_kind'], 'source_label' => $row['source_label']];
        foreach (['mode_id', 'role_id', 'release_id'] as $field) {
            $context[$field] = $row['context']['game_context'][$field] ?? null;
        }
        foreach (['region', 'season', 'map', 'opponent'] as $field) {
            $context[$field] = $row['context'][$field] ?? null;
        }

        return $context;
    }

    /** @param array<string,mixed> $row
     * @return array<string,?string> */
    public function contextLabels(array $row): array
    {
        $labels = [];
        foreach (['mode', 'role', 'release'] as $kind) {
            $labels[$kind.'_id'] = $row['context']['game_context'][$kind.'_name'] ?? null;
        }

        return $labels;
    }

    /** @param array<string,mixed> $metric
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>> */
    public function groups(array $metric, array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $context = $this->context($row);
            $key = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
            $groups[$key] ??= ['key' => $key, 'context' => $context, 'rows' => []];
            $groups[$key]['rows'][] = $row;
        }
        ksort($groups);
        $result = [];
        foreach ($groups as $group) {
            $rows = $group['rows'];
            $issue = null;
            try {
                $calculated = $this->calculator->calculate($metric, $rows);
            } catch (DomainException $exception) {
                $calculated = null;
                $issue = $exception->getMessage() === 'RATIO_COMPONENTS_REQUIRED' ? 'RATIO_COMPONENTS_REQUIRED' : 'INCOMPATIBLE_SOURCES';
            }
            $periods = [];
            foreach ($rows as $row) {
                $periods[$row['period_start'].':'.$row['period_end']] = [$row['period_start'], $row['period_end']];
            }
            ksort($periods);
            $result[] = ['key' => $group['key'], 'context' => $group['context'], 'context_labels' => $this->contextLabels($rows[0]), 'result' => $calculated, 'issue' => $issue,
                'source_count' => count($rows), 'coverage_version' => hash('sha256', json_encode(array_values($periods), JSON_THROW_ON_ERROR)), 'period_count' => count($periods),
                'period_start' => $this->boundary($rows, 'period_start', false), 'period_end' => $this->boundary($rows, 'period_end'),
                'latest_observed_on' => $this->boundary($rows, 'observed_on'), 'last_updated_at' => $this->boundary($rows, 'occurred_at')];
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $left
     * @param list<array<string,mixed>> $right
     * @return list<array<string,mixed>> */
    public function compare(array $left, array $right): array
    {
        $a = array_column($left, null, 'key');
        $b = array_column($right, null, 'key');
        $keys = array_unique([...array_keys($a), ...array_keys($b)]);
        sort($keys);
        $pairs = [];
        foreach ($keys as $key) {
            $left = $a[$key] ?? null;
            $right = $b[$key] ?? null;
            $reason = match (true) {
                $left === null || $right === null => 'MISSING_CONTEXT',
                $left['issue'] !== null || $right['issue'] !== null => 'INCOMPATIBLE_SOURCES',
                $left['result']['incomplete'] || $right['result']['incomplete'] => 'INCOMPLETE_SAMPLE',
                $left['result']['availability'] !== 'available' || $right['result']['availability'] !== 'available' => 'UNAVAILABLE_RESULT',
                $left['coverage_version'] !== $right['coverage_version'] => 'PERIOD_MISMATCH',
                default => null,
            };
            $pairs[] = ['key' => $key, 'context' => $left['context'] ?? $right['context'] ?? [], 'context_labels' => $left['context_labels'] ?? $right['context_labels'] ?? [], 'comparable' => $reason === null, 'reason' => $reason,
                'left' => $left, 'right' => $right,
                'difference' => $reason === null ? (string) BigDecimal::of($left['result']['value'])->minus($right['result']['value']) : null,
                'unequal_samples' => $left !== null && $right !== null && ($left['result']['sample_size'] ?? null) !== ($right['result']['sample_size'] ?? null)];
        }

        return $pairs;
    }

    /** @param list<array<string,mixed>> $rows
     * @return array{data:list<array<string,mixed>>,meta:array<string,int>} */
    public function page(array $rows, int $page): array
    {
        $lastPage = max(1, (int) ceil(count($rows) / 10));
        $page = min($page, $lastPage);

        return ['data' => array_slice($rows, ($page - 1) * 10, 10), 'meta' => ['current_page' => $page,
            'last_page' => $lastPage, 'per_page' => 10, 'total' => count($rows)]];
    }

    /** @param list<array<string,mixed>> $rows */
    public function boundary(array $rows, string $field, bool $latest = true): ?string
    {
        $values = array_column($rows, $field);
        if ($latest) {
            rsort($values);
        } else {
            sort($values);
        }

        return $values[0] ?? null;
    }
}
