<?php

namespace App\Modules\Performance\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\PlatformCatalogueGuard;
use App\Modules\Performance\Application\Validation\MetricCatalogueInput as Input;
use App\Modules\Performance\Domain\Contracts\MetricCatalogueRepository;
use App\Modules\Performance\Domain\Models\MetricDefinition;
use App\Modules\Performance\Domain\Models\MetricRevision;
use App\Modules\Teams\Application\Contracts\MetricGameDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MetricCatalogueService
{
    public function __construct(private MetricCatalogueRepository $records, private MetricGameDirectory $games, private PlatformCatalogueGuard $guard, private Factory $validator, private ConnectionInterface $database) {}

    /** @return array{catalogue_version:string,game_catalogue_version:string,can_manage:bool} */
    public function metadata(int $actor): array
    {
        return ['catalogue_version' => $this->records->state()['version'], 'game_catalogue_version' => $this->games->version(), 'can_manage' => $this->guard->allows($actor)];
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,MetricDefinition> */
    public function index(array $input): LengthAwarePaginator
    {
        return $this->records->paginate(Input::validate($this->validator, $input, 'index'));
    }

    public function find(string $id): MetricDefinition
    {
        return $this->records->find($id);
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,MetricRevision> */
    public function history(string $id, array $input): LengthAwarePaginator
    {
        $this->find($id);
        $v = Input::validate($this->validator, $input, 'history');

        return $this->records->history($id, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 5));
    }

    public function version(string $id, string $revision): MetricRevision
    {
        $this->find($id);

        return $this->records->revision($id, $revision);
    }

    /** @param array<string,mixed> $input */
    public function save(int $actor, string $requestId, array $input, ?string $id = null): MetricDefinition
    {
        $v = Input::validate($this->validator, $input, $id === null ? 'store' : 'update');
        $this->validator->make(['actor' => $actor, 'request_id' => $requestId], ['actor' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid']])->validate();
        if (mb_strlen(trim($v['reason'])) < 10) {
            throw ValidationException::withMessages(['reason' => __('metric_catalogue.reason')]);
        }
        if (($v['aggregation'] === 'ratio') === ($v['zero_denominator'] === 'not_applicable')) {
            throw ValidationException::withMessages(['zero_denominator' => __('metric_catalogue.ratio')]);
        }
        try {
            return $this->database->transaction(function () use ($actor, $requestId, $v, $id): MetricDefinition {
                $this->guard->lock($actor);
                $this->games->lock($v['game_catalogue_version']);
                $state = $this->records->state(true);
                if ($state['version'] !== strtolower($v['catalogue_version'])) {
                    $this->conflict('stale');
                }
                $metric = $id === null ? null : $this->records->find($id);
                $old = $metric?->current;
                if ($metric !== null && $old?->id !== strtolower($v['version'])) {
                    $this->conflict('stale');
                }
                if (($old->number ?? 0) >= Input::MAX_REVISIONS || ($metric === null && $this->records->count() >= Input::MAX_METRICS)) {
                    $this->conflict('limit');
                }
                $values = array_replace(array_fill_keys(Input::FIELDS, null), array_intersect_key($v, array_flip(Input::FIELDS)));
                foreach ($values as $key => $value) {
                    if (is_string($value)) {
                        $values[$key] = str_ends_with($key, '_id') ? strtolower($value) : trim($value);
                    }
                }
                $game = $metric->game_id ?? strtolower($v['game_id']);
                $scope = $this->games->resolve($game, $values['mode_id'], $values['role_id'], $values['release_id'], $values['status'] === 'active');
                $scope['aggregation_level'] = $v['aggregation_level'] ?? ($old->scope['aggregation_level'] ?? 'game');
                if ($old !== null && array_intersect_key($old->getAttributes(), array_flip(Input::FIELDS)) === $values && $old->scope === $scope) {
                    throw ValidationException::withMessages(['record' => __('metric_catalogue.empty')]);
                }
                $metric ??= $this->records->create(['id' => (string) Str::uuid(), 'game_id' => $game, 'code' => $v['code'], 'created_by' => $actor]);
                $this->records->append(['id' => (string) Str::uuid(), 'metric_id' => $metric->id, 'number' => ($old->number ?? 0) + 1, 'catalogue_number' => $state['number'] + 1, ...$values, 'scope' => $scope, 'reason' => trim($v['reason']), 'actor_user_id' => $actor, 'request_id' => $requestId]);

                return $this->records->find($metric->id);
            });
        } catch (UniqueConstraintViolationException) {
            $this->conflict('duplicate');
        }
    }

    /** @return array<string,mixed> */
    public function project(MetricDefinition $metric, ?MetricRevision $revision = null): array
    {
        $revision ??= $metric->current;

        return ['id' => $metric->id, 'code' => $metric->code, 'game_id' => $metric->game_id, 'version' => $revision?->id, 'number' => $revision?->number, 'scope' => $revision?->scope, 'aggregation_level' => $revision?->scope['aggregation_level'] ?? 'game',
            ...array_intersect_key($revision?->getAttributes() ?? [], array_flip(Input::FIELDS)), 'reason' => $revision?->getAttribute('reason'), 'occurred_at' => $revision?->created_at->toISOString()];
    }

    private function conflict(string $key): never
    {
        throw new DomainConflictException(__('metric_catalogue.'.$key), 'METRIC_CATALOGUE_'.strtoupper($key));
    }
}
