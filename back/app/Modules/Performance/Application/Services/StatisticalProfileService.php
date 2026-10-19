<?php

namespace App\Modules\Performance\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\ObservationMatchDirectory;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Performance\Application\Validation\StatisticalProfileInput;
use App\Modules\Performance\Domain\Aggregation\StatisticalCalculator;
use App\Modules\Performance\Domain\Aggregation\StatisticalProfileProjector;
use App\Modules\Performance\Domain\Contracts\MetricCatalogueRepository;
use App\Modules\Performance\Domain\Contracts\StatisticalProfileRepository;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class StatisticalProfileService
{
    public function __construct(private StatisticalProfileRepository $records, private ObservationService $observations,
        private MetricCatalogueRepository $metrics, private MetricCatalogueService $dictionary,
        private ObservationMatchDirectory $matches, private SportingPeopleDirectory $people,
        private StatisticalProfileProjector $projector, private Factory $validator) {}

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function people(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->observations->authorize($context);
        $values = StatisticalProfileInput::validate($this->validator, $input, 'people');
        $list = $this->records->people($context->organizationId, $this->matches->visibleIds($context), (int) ($values['page'] ?? 1));
        $labels = $this->people->pseudonyms($context, array_values($list->items()));

        return $list->through(fn (string $id): array => ['id' => $id, 'label' => $labels[$id] ?? $id]);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function show(TenantContext $context, array $input): array
    {
        $this->observations->authorize($context);
        $values = StatisticalProfileInput::validate($this->validator, $input, 'show');
        $ids = array_values(array_map('strtolower', $values['people']));
        $labels = $this->people->pseudonyms($context, $ids);
        if (count($labels) !== count($ids)) {
            throw new ModelNotFoundException;
        }
        $metric = $this->metrics->find(strtolower($values['metric_id']));
        $revision = $this->metrics->revision($metric->id, strtolower($values['metric_version']));
        $definition = $this->dictionary->project($metric, $revision);
        $components = $definition['aggregation'] === 'ratio' ? ($definition['ratio_components'] ?? null) : null;
        $versions = $components === null ? [$revision->id] : array_column([...$components['numerator'], ...$components['denominator']], 'version');
        $candidates = $this->records->candidates($context->organizationId, $this->matches->visibleIds($context), $ids, $versions, $values);
        $counts = array_count_values(array_map(fn ($row): string => $row->person_id, $candidates));
        if ($counts !== [] && max($counts) > StatisticalCalculator::MAX_SOURCES) {
            throw new DomainConflictException(__('statistical_profiles.limit'), 'STAT_PROFILE_LIMIT');
        }
        $rows = [];
        foreach ($candidates as $candidate) {
            if ($candidate->current === null) {
                throw new DomainConflictException(__('statistical_profiles.unavailable'), 'STAT_PROFILE_UNAVAILABLE');
            }
            $row = [...$this->observations->project($candidate, []), 'created_at' => $candidate->created_at->toISOString()];
            $scope = $this->projector->context($row);
            foreach ($scope as $key => $value) {
                $expected = $values[$key] ?? null;
                if ($expected !== null && $value !== (str_ends_with($key, '_id') ? strtolower($expected) : $expected)) {
                    continue 2;
                }
            }
            $rows[$candidate->person_id][] = $row;
        }
        $page = (int) ($values['page'] ?? 1);
        $profiles = [];
        $groups = [];
        foreach ($ids as $id) {
            $sources = $rows[$id] ?? [];
            $groups[$id] = $this->projector->groups($definition, $sources);
            usort($sources, fn (array $a, array $b): int => [$b['observed_on'], $b['period_end'], $b['id']] <=> [$a['observed_on'], $a['period_end'], $a['id']]);
            $timeline = array_map(fn (array $row): array => ['id' => $row['id'], 'metric_id' => $row['metric_id'], 'metric_version' => $row['metric_version'],
                'metric_code' => $row['context']['metric']['code'], 'metric_number' => $row['context']['metric']['number'], 'metric_unit' => $row['context']['metric']['unit'], 'period_start' => $row['period_start'], 'period_end' => $row['period_end'],
                'observed_on' => $row['observed_on'], 'sample_size' => $row['sample_size'], 'status' => $row['status'],
                'availability' => $row['availability'], 'value' => $row['value'], 'version' => $row['version'],
                'last_updated_at' => $row['occurred_at'], 'context' => $this->projector->context($row), 'context_labels' => $this->projector->contextLabels($row)], $sources);
            $profiles[] = ['person_id' => $id, 'person_label' => $labels[$id], 'source_count' => count($sources),
                'latest_observed_on' => $this->projector->boundary($sources, 'observed_on'),
                'last_updated_at' => $this->projector->boundary($sources, 'occurred_at'),
                'groups' => $this->projector->page($groups[$id], $page),
                'timeline' => $this->projector->page($timeline, (int) ($values['timeline_page'] ?? 1))];
        }
        $pairs = count($ids) === 2 ? $this->projector->compare($groups[$ids[0]], $groups[$ids[1]]) : [];

        return ['metric' => $definition, 'filters' => $values, 'generated_at' => CarbonImmutable::now()->toISOString(),
            'engine_version' => StatisticalCalculator::ENGINE_VERSION, 'live' => true, 'profiles' => $profiles,
            'comparison' => count($ids) === 2 ? $this->projector->page($pairs, $page) : null];
    }
}
