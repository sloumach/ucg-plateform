<?php

namespace App\Modules\Competition\Domain\Contracts;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Domain\Models\MatchStatusEvent;
use App\Support\Data\SearchPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface MatchRepository
{
    /** @param array{person_id:string|null,team_ids:list<string>} $viewer */
    public function search(string $org, int $actor, array $viewer, string $term, ?string $after, int $size): SearchPage;

    /** @param array{person_id:string|null,team_ids:list<string>} $viewer
     * @return LengthAwarePaginator<int,MatchRecord> */
    public function dashboard(string $org, int $actor, array $viewer, string $from, string $to, int $page): LengthAwarePaginator;

    /** @param array<string,mixed> $filters
     * @param  array{person_id:string|null,team_ids:list<string>}  $viewer
     * @return LengthAwarePaginator<int,MatchRecord>
     */
    public function paginate(string $org, int $actor, array $viewer, array $filters, bool $management): LengthAwarePaginator;

    /** @param array{person_id:string|null,team_ids:list<string>} $viewer */
    public function find(string $org, string $id, int $actor, array $viewer, bool $management, bool $lock = false): MatchRecord;

    /** @param array{person_id:string|null,team_ids:list<string>} $viewer
     * @param list<string> $ids
     * @return array<string,MatchRecord> */
    public function visibleByIds(string $org, int $actor, array $viewer, array $ids): array;

    /** @param array<string,mixed> $attributes
     * @param  list<array<string,mixed>>|null  $participants
     */
    public function save(?MatchRecord $record, array $attributes, ?array $participants): MatchRecord;

    /** @param array{person_id:string|null,team_ids:list<string>} $viewer */
    public function visibleIdsQuery(string $org, int $actor, array $viewer): Builder;

    /** @param array<string,mixed> $attributes */
    public function event(array $attributes): void;

    /** @return LengthAwarePaginator<int,MatchStatusEvent> */
    public function history(string $org, string $id, int $page, int $perPage): LengthAwarePaginator;
}
