<?php

namespace App\Modules\Competition\Infrastructure\Persistence;

use App\Modules\Competition\Domain\Contracts\ExternalCompetitionRepository;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Competition\Domain\Models\ExternalCompetitionDocument;
use App\Modules\Competition\Domain\Models\ExternalCompetitionRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

final class EloquentExternalCompetitionRepository implements ExternalCompetitionRepository
{
    public function paginate(string $org, array $filters): LengthAwarePaginator
    {
        $q = ExternalCompetition::query()->where('organization_id', $org)->where('status', $filters['status'] ?? 'active');
        foreach (['game_id', 'season', 'region'] as $field) {
            if (isset($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }

        return $q->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $id, bool $lock = false): ExternalCompetition
    {
        return ExternalCompetition::query()->where('organization_id', $org)->when($lock, fn (Builder $q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function count(string $org): int
    {
        return ExternalCompetition::query()->where('organization_id', $org)->count();
    }

    public function save(?ExternalCompetition $record, array $attributes): ExternalCompetition
    {
        $record ??= new ExternalCompetition;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function latestRevision(string $org, string $competition): ?ExternalCompetitionRevision
    {
        return ExternalCompetitionRevision::query()->where('organization_id', $org)->where('competition_id', $competition)->orderByDesc('number')->first();
    }

    public function revision(array $attributes): ExternalCompetitionRevision
    {
        $r = new ExternalCompetitionRevision;
        $r->forceFill($attributes)->save();

        return $r;
    }

    public function history(string $org, string $competition, int $page, int $perPage): LengthAwarePaginator
    {
        return ExternalCompetitionRevision::query()->where('organization_id', $org)->where('competition_id', $competition)->orderByDesc('number')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @return Builder<ExternalCompetitionDocument> */
    private function documentQuery(string $org, string $competition): Builder
    {
        return ExternalCompetitionDocument::query()->where('organization_id', $org)->where('competition_id', $competition);
    }

    public function documents(string $org, string $competition, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->documentQuery($org, $competition)->whereNotExists(fn (Query $newer) => $newer->selectRaw('1')->from('external_competition_documents as newer')
            ->where('newer.organization_id', $org)->where('newer.competition_id', $competition)->whereColumn('newer.document_id', 'external_competition_documents.document_id')->whereColumn('newer.number', '>', 'external_competition_documents.number'))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function documentCount(string $org, string $competition): int
    {
        return $this->documentQuery($org, $competition)->where('number', 1)->count();
    }

    public function document(string $org, string $competition, string $document): ExternalCompetitionDocument
    {
        return $this->documentQuery($org, $competition)->where('document_id', $document)->orderByDesc('number')->firstOrFail();
    }

    public function documentVersion(string $org, string $competition, string $document, string $version): ExternalCompetitionDocument
    {
        return $this->documentQuery($org, $competition)->where('document_id', $document)->findOrFail($version);
    }

    public function appendDocument(array $attributes): ExternalCompetitionDocument
    {
        $r = new ExternalCompetitionDocument;
        $r->forceFill($attributes)->save();

        return $r;
    }

    public function documentHistory(string $org, string $competition, string $document, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->documentQuery($org, $competition)->where('document_id', $document)->orderByDesc('number')->paginate($perPage, ['*'], 'page', $page);
    }
}
