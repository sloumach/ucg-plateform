<?php

namespace App\Modules\Competition\Infrastructure\Persistence;

use App\Modules\Competition\Domain\Contracts\CompetitionOutcomeRepository;
use App\Modules\Competition\Domain\Models\CompetitionOutcomeMatch;
use App\Modules\Competition\Domain\Models\CompetitionOutcomeRevision as Revision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class EloquentCompetitionOutcomeRepository implements CompetitionOutcomeRepository
{
    public function latest(string $org, string $competition, string $stream): ?Revision
    {
        return Revision::query()->where('organization_id', $org)->where('competition_id', $competition)->where('stream', $stream)->with(['links' => fn ($links) => $links->where('organization_id', $org)])->orderByDesc('number')->first();
    }

    public function append(array $attributes, array $links): Revision
    {
        $row = new Revision;
        $row->forceFill($attributes)->save();
        foreach ($links as $link) {
            $entry = new CompetitionOutcomeMatch;
            $entry->forceFill(['id' => (string) Str::uuid(), 'organization_id' => $row->organization_id, 'competition_id' => $row->competition_id, 'revision_id' => $row->id, ...$link])->save();
        }

        return $row->load(['links' => fn ($links) => $links->where('organization_id', $row->organization_id)]);
    }

    public function history(string $org, string $competition, string $stream, int $page, int $size): LengthAwarePaginator
    {
        return Revision::query()->where('organization_id', $org)->where('competition_id', $competition)->where('stream', $stream)->with(['links' => fn ($links) => $links->where('organization_id', $org)])->orderByDesc('number')->paginate($size, ['*'], 'page', $page);
    }
}
