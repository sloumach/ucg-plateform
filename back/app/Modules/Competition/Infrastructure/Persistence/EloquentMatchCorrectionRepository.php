<?php

namespace App\Modules\Competition\Infrastructure\Persistence;

use App\Modules\Competition\Domain\Contracts\MatchCorrectionRepository;
use App\Modules\Competition\Domain\Models\MatchScoreCorrection;
use App\Modules\Competition\Domain\Models\MatchScoreRecalculation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentMatchCorrectionRepository implements MatchCorrectionRepository
{
    /** @return Builder<MatchScoreCorrection> */
    private function query(string $org, string $match): Builder
    {
        return MatchScoreCorrection::query()->where('organization_id', $org)->where('match_id', $match);
    }

    public function latest(string $org, string $match): ?MatchScoreCorrection
    {
        return $this->query($org, $match)->with('recalculation')->orderByDesc('number')->first();
    }

    public function find(string $org, string $match, string $id): MatchScoreCorrection
    {
        return $this->query($org, $match)->with('recalculation')->findOrFail($id);
    }

    public function byKey(string $org, string $match, string $key): ?MatchScoreCorrection
    {
        return $this->query($org, $match)->with('recalculation')->where('idempotency_key', $key)->first();
    }

    public function append(array $attributes): MatchScoreCorrection
    {
        $record = new MatchScoreCorrection;
        $record->forceFill($attributes)->save();

        return $record->load('recalculation');
    }

    public function complete(array $attributes): void
    {
        $record = new MatchScoreRecalculation;
        $record->forceFill($attributes)->save();
    }

    public function history(string $org, string $match, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($org, $match)->with('recalculation')->orderByDesc('number')->paginate($perPage, ['*'], 'page', $page);
    }

    public function contributors(string $org, string $match): array
    {
        return array_values($this->query($org, $match)->distinct()->limit(100)->pluck('actor_user_id')->map(fn ($id): int => (int) $id)->all());
    }
}
