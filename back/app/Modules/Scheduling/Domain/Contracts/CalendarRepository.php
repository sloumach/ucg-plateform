<?php

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface CalendarRepository
{
    /** @return LengthAwarePaginator<int, CalendarActivity> */
    public function partnershipChoices(string $org, Builder $visible, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<string> $ids
     * @return array<string, array{id: string, label: string, version: string, is_scrim: bool, visibility: string}> */
    public function mediaMetadata(string $org, array $ids, Builder $visible): array;

    public function communicationIds(string $org, CalendarAudience $audience): Builder;

    /** @return LengthAwarePaginator<int, CalendarActivity> */
    public function communicationChoices(string $org, CalendarAudience $audience, int $page, int $perPage): LengthAwarePaginator;

    public function visibleForChange(string $org, string $id, CalendarAudience $audience): bool;

    /** @return list<array{id:string,title:string,starts_at:CarbonImmutable,ends_at:CarbonImmutable,updated_at:CarbonImmutable}> */
    public function feed(string $org, CalendarAudience $audience, CarbonImmutable $from, CarbonImmutable $to): array;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,CalendarActivity>
     */
    public function paginate(string $org, CalendarAudience $audience, CarbonImmutable $from, CarbonImmutable $to, array $filters, bool $management): LengthAwarePaginator;

    public function find(string $org, string $id, CalendarAudience $audience, bool $management, bool $lock = false): CalendarActivity;

    /** @param array<string,mixed> $attributes
     * @param  list<string>|null  $personIds
     * @param  list<string>|null  $resourceIds
     */
    public function save(?CalendarActivity $record, array $attributes, ?array $personIds, ?array $resourceIds = null): CalendarActivity;
}
