<?php

namespace App\Modules\Competition\Domain\Contracts;

use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Competition\Domain\Models\ExternalCompetitionDocument;
use App\Modules\Competition\Domain\Models\ExternalCompetitionRevision;
use App\Support\Data\SearchPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ExternalCompetitionRepository
{
    public function search(string $org, bool $documents, string $term, ?string $after, int $size): SearchPage;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,ExternalCompetition> */
    public function paginate(string $org, array $filters): LengthAwarePaginator;

    public function find(string $org, string $id, bool $lock = false): ExternalCompetition;

    public function count(string $org): int;

    /** @param array<string,mixed> $attributes */
    public function save(?ExternalCompetition $record, array $attributes): ExternalCompetition;

    public function latestRevision(string $org, string $competition): ?ExternalCompetitionRevision;

    /** @param array<string,mixed> $attributes */
    public function revision(array $attributes): ExternalCompetitionRevision;

    /**
     * @return LengthAwarePaginator<int,ExternalCompetitionRevision> */
    public function history(string $org, string $competition, int $page, int $perPage): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int,ExternalCompetitionDocument> */
    public function documents(string $org, string $competition, int $page, int $perPage): LengthAwarePaginator;

    public function documentCount(string $org, string $competition): int;

    public function document(string $org, string $competition, string $document): ExternalCompetitionDocument;

    public function documentVersion(string $org, string $competition, string $document, string $version): ExternalCompetitionDocument;

    /** @param array<string,mixed> $attributes */
    public function appendDocument(array $attributes): ExternalCompetitionDocument;

    /**
     * @return LengthAwarePaginator<int,ExternalCompetitionDocument> */
    public function documentHistory(string $org, string $competition, string $document, int $page, int $perPage): LengthAwarePaginator;
}
