<?php

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use App\Modules\Scheduling\Domain\Models\CalendarInvitationDelivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CalendarInvitationRepository
{
    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,CalendarInvitation>
     */
    public function paginate(string $org, array $filters, ?string $activityId, ?string $personId, int $actorId): LengthAwarePaginator;

    public function find(string $org, string $id, bool $lock = false): CalendarInvitation;

    public function exists(string $org, string $activityId, string $activityVersion): bool;

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): CalendarInvitation;

    /** @param array<string,mixed> $attributes */
    public function update(CalendarInvitation $record, array $attributes): void;

    /** @return list<CalendarInvitation> */
    public function sweep(string $org, int $limit): array;

    public function deliver(CalendarInvitation $record, string $kind, int $offset, string $runId): void;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,CalendarInvitationDelivery>
     */
    public function history(CalendarInvitation $record, array $filters): LengthAwarePaginator;
}
