<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Scheduling\Application\Data\CalendarConflictReport;
use App\Modules\Scheduling\Domain\Contracts\CalendarConflictRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Validation\ValidationException;

final readonly class CalendarConflictService
{
    public const int MAX_CONFLICTS = 100;

    public function __construct(private CalendarConflictRepository $records, private TenantAuthorization $authorization, private CalendarConflictReview $reviews) {}

    /** @param array<string,mixed> $attributes
     * @param  list<string>  $people
     * @param  list<string>  $resources
     */
    public function inspect(TenantContext $context, ?CalendarActivity $record, array $attributes, array $people, array $resources): CalendarConflictReport
    {
        $this->authorization->authorize($context, 'calendar.read');
        $this->authorization->authorize($context, 'calendar.write');
        sort($people);
        sort($resources);
        $from = $attributes['starts_at'] ?? $record?->starts_at;
        $to = $attributes['ends_at'] ?? $record?->ends_at;
        $team = array_key_exists('team_id', $attributes) ? $attributes['team_id'] : $record?->team_id;
        $available = $this->authorization->allows($context, 'availability.read');
        $rows = $this->records->detect($context->organizationId, $record?->id, $from, $to, $team, $people, $resources, $available, self::MAX_CONFLICTS + 1);
        $report = new CalendarConflictReport(array_slice($rows, 0, self::MAX_CONFLICTS), count($rows) > self::MAX_CONFLICTS, $available, [
            'organization_id' => $context->organizationId, 'actor_id' => $context->actorUserId, 'activity_id' => $record?->id, 'version' => $record?->version,
            'starts_at' => $from->utc()->toIso8601String(), 'ends_at' => $to->utc()->toIso8601String(), 'team_id' => $team, 'people' => $people, 'resources' => $resources]);

        return new CalendarConflictReport($report->conflicts, $report->truncated, $available, $report->booking, $this->reviews->issue($report), $this->authorization->allows($context, 'calendar.conflicts.override'));
    }

    /** @param array<string,mixed> $values */
    public function enforce(TenantContext $context, CalendarConflictReport $report, array $values): void
    {
        if ($report->truncated) {
            throw new DomainConflictException(__('calendar.conflicts_limit'), 'CALENDAR_CONFLICT_LIMIT');
        }
        if (isset($values['conflict_review'])) {
            $this->reviews->verify($values['conflict_review'], $report);
        } elseif ($report->conflicts !== []) {
            throw new DomainConflictException(__('calendar.conflicts_review_required'), 'CALENDAR_CONFLICT_REVIEW_REQUIRED');
        }
        if ($report->conflicts === []) {
            if (isset($values['conflict_reason']) || isset($values['confirm_conflicts'])) {
                throw ValidationException::withMessages(['conflict_reason' => __('calendar.conflicts_none')]);
            }

            return;
        }
        $this->authorization->authorize($context, 'calendar.conflicts.override');
        if (! isset($values['confirm_conflicts']) || ! isset($values['conflict_reason']) || mb_strlen(trim($values['conflict_reason'])) < 10) {
            throw ValidationException::withMessages(['conflict_reason' => __('calendar.conflicts_reason_required')]);
        }
    }

    /** @param array<string,mixed> $values */
    public function retain(CalendarActivity $saved, CalendarConflictReport $report, array $values, int $actor): void
    {
        if ($report->conflicts !== []) {
            $this->records->recordOverride($saved, $actor, trim($values['conflict_reason']), ['booking' => $report->booking, 'conflicts' => $report->conflicts, 'availability_checked' => $report->availabilityChecked], count($report->conflicts));
        }
    }
}
