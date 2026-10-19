<?php

namespace App\Modules\Competition\Domain\Contracts;

use App\Modules\Competition\Domain\Models\MatchGameInstance;
use App\Modules\Competition\Domain\Models\MatchScoreRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MatchScoreRepository
{
    public function findGame(string $org, string $match, string $id): MatchGameInstance;

    public function rules(string $org, string $match): ?MatchScoreRules;

    /** @param array<string,mixed> $attributes */
    public function configure(array $attributes): MatchScoreRules;

    /** @param array<string,mixed> $attributes */
    public function append(array $attributes): MatchGameInstance;

    /** @return list<MatchGameInstance> */
    public function games(string $org, string $match): array;

    /** @return LengthAwarePaginator<int,MatchGameInstance> */
    public function paginate(string $org, string $match, int $page, int $perPage): LengthAwarePaginator;
}
