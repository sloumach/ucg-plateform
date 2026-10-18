<?php

namespace App\Modules\Competition\Domain\Contracts;

use App\Modules\Competition\Domain\Models\MatchScoreCorrection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MatchCorrectionRepository
{
    public function latest(string $org, string $match): ?MatchScoreCorrection;

    public function find(string $org, string $match, string $id): MatchScoreCorrection;

    public function byKey(string $org, string $match, string $key): ?MatchScoreCorrection;

    /** @param array<string,mixed> $attributes */
    public function append(array $attributes): MatchScoreCorrection;

    /** @param array<string,mixed> $attributes */
    public function complete(array $attributes): void;

    /** @return LengthAwarePaginator<int,MatchScoreCorrection> */
    public function history(string $org, string $match, int $page, int $perPage): LengthAwarePaginator;

    /** @return list<int> */
    public function contributors(string $org, string $match): array;
}
