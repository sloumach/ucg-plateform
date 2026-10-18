<?php

namespace App\Modules\Competition\Infrastructure\Persistence;

use App\Modules\Competition\Domain\Contracts\MatchScoreRepository;
use App\Modules\Competition\Domain\Models\MatchGameInstance;
use App\Modules\Competition\Domain\Models\MatchScoreRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentMatchScoreRepository implements MatchScoreRepository
{
    public function rules(string $org, string $match): ?MatchScoreRules
    {
        return MatchScoreRules::query()->where('organization_id', $org)->where('match_id', $match)->first();
    }

    public function configure(array $attributes): MatchScoreRules
    {
        $rules = new MatchScoreRules;
        $rules->forceFill($attributes)->save();

        return $rules;
    }

    public function append(array $attributes): MatchGameInstance
    {
        $game = new MatchGameInstance;
        $game->forceFill($attributes)->save();

        return $game;
    }

    /** @return Builder<MatchGameInstance> */
    private function query(string $org, string $match): Builder
    {
        return MatchGameInstance::query()->where('organization_id', $org)->where('match_id', $match);
    }

    public function games(string $org, string $match): array
    {
        return array_values($this->query($org, $match)->orderBy('number')->limit(100)->get()->all());
    }

    public function paginate(string $org, string $match, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($org, $match)->orderByDesc('number')->paginate($perPage, ['*'], 'page', $page);
    }
}
