<?php

namespace App\Modules\Competition\Infrastructure\Persistence;

use App\Modules\Competition\Domain\Contracts\CompetitionRegistrationRepository;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationReminder as Reminder;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationRevision as Revision;
use App\Modules\Competition\Domain\Models\ExternalCompetitionDocument;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

final class EloquentCompetitionRegistrationRepository implements CompetitionRegistrationRepository
{
    public function latest(string $org, string $competition): ?Revision
    {
        return Revision::query()->where('organization_id', $org)->where('competition_id', $competition)->orderByDesc('number')->first();
    }

    public function append(array $attributes): Revision
    {
        $row = new Revision;
        $row->forceFill($attributes)->save();

        return $row;
    }

    public function history(string $org, string $competition, int $page, int $size): LengthAwarePaginator
    {
        return Revision::query()->where('organization_id', $org)->where('competition_id', $competition)->orderByDesc('number')->paginate($size, ['*'], 'page', $page);
    }

    public function sweep(string $org, CarbonImmutable $until): array
    {
        return array_values(Revision::query()->where('organization_id', $org)->where('next_due_at', '<=', $until)
            ->whereExists(fn (Builder $p) => $p->selectRaw('1')->from('external_competitions as p')->where('p.organization_id', $org)->whereColumn('p.id', 'competition_registration_revisions.competition_id')->where('p.status', 'active'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('competition_registration_revisions as newer')->where('newer.organization_id', $org)->whereColumn('newer.competition_id', 'competition_registration_revisions.competition_id')->whereColumn('newer.number', '>', 'competition_registration_revisions.number'))
            ->orderByRaw('CASE WHEN checked_at IS NULL THEN 0 ELSE 1 END')->orderBy('checked_at')->orderBy('id')->limit(25)->get()->all());
    }

    public function checked(Revision $revision): void
    {
        $revision->forceFill(['checked_at' => CarbonImmutable::now()])->save();
    }

    public function remind(array $attributes): void
    {
        if (! Reminder::query()->where('organization_id', $attributes['organization_id'])->where('competition_id', $attributes['competition_id'])->where('occurrence', $attributes['occurrence'])->exists()) {
            $row = new Reminder;
            $row->forceFill($attributes)->save();
        }
    }

    public function reminders(string $org, string $competition, int $actor, int $page, int $size): LengthAwarePaginator
    {
        return Reminder::query()->where('organization_id', $org)->where('competition_id', $competition)->where('recipient_id', $actor)->orderByDesc('created_at')->orderByDesc('id')->paginate($size, ['*'], 'page', $page);
    }

    public function documentVersions(string $org, string $competition, array $versions): array
    {
        return array_values(ExternalCompetitionDocument::query()->where('organization_id', $org)->where('competition_id', $competition)->whereIn('id', $versions)->get(['id'])->map(fn (ExternalCompetitionDocument $document): string => $document->id)->all());
    }
}
