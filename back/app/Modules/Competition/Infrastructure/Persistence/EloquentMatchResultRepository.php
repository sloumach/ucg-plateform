<?php

namespace App\Modules\Competition\Infrastructure\Persistence;

use App\Modules\Competition\Domain\Contracts\MatchResultRepository;
use App\Modules\Competition\Domain\Models\MatchResultEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentMatchResultRepository implements MatchResultRepository
{
    /** @return Builder<MatchResultEvent> */
    private function query(string $org, string $match): Builder
    {
        return MatchResultEvent::query()->where('organization_id', $org)->where('match_id', $match);
    }

    public function latest(string $org, string $match): ?MatchResultEvent
    {
        return $this->query($org, $match)->orderByDesc('number')->first();
    }

    public function submission(MatchResultEvent $event): MatchResultEvent
    {
        return $event->action === 'submit' ? $event : $this->query($event->organization_id, $event->match_id)->where('action', 'submit')->findOrFail($event->submission_id);
    }

    public function append(array $attributes): MatchResultEvent
    {
        $event = new MatchResultEvent;
        $event->forceFill($attributes)->save();

        return $event;
    }

    public function history(string $org, string $match, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($org, $match)->orderByDesc('number')->paginate($perPage, ['*'], 'page', $page);
    }
}
