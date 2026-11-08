<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\Scheduling\Application\Validation\CalendarChangeInput;
use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Contracts\CalendarChangeRepository;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarChange;
use App\Modules\Scheduling\Domain\Models\CalendarChangeRecipient;
use App\Modules\Teams\Application\Contracts\CalendarTeamsDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final readonly class CalendarChangeService
{
    public function __construct(private CalendarChangeRepository $changes, private CalendarRepository $calendar,
        private CalendarPeopleDirectory $people, private CalendarTeamsDirectory $teams, private TenantAuthorization $authorization, private Factory $validator) {}

    /** @param array<string,mixed> $attributes
     * @param  array<string,mixed>  $values
     * @return array{kind:string,previous_version:string,previous_starts_at:CarbonImmutable,previous_ends_at:CarbonImmutable,previous_timezone:string,people:list<string>}|null
     */
    public function plan(?CalendarActivity $record, array $attributes, array $values): ?array
    {
        if ($record === null) {
            return null;
        }
        $cancelled = isset($attributes['cancelled_at']);
        if (! $cancelled && $record->starts_at->equalTo($attributes['starts_at'] ?? $record->starts_at)
            && $record->ends_at->equalTo($attributes['ends_at'] ?? $record->ends_at)
            && $record->origin_timezone === ($attributes['origin_timezone'] ?? $record->origin_timezone)) {
            return null;
        }
        if (! isset($values['change_reason']) || ! is_string($values['change_reason']) || mb_strlen(trim($values['change_reason'])) < 10) {
            throw ValidationException::withMessages(['change_reason' => __('calendar.change_reason')]);
        }

        return ['kind' => $cancelled ? 'cancelled' : 'rescheduled', 'previous_version' => $record->version,
            'previous_starts_at' => $record->starts_at, 'previous_ends_at' => $record->ends_at, 'previous_timezone' => $record->origin_timezone,
            'people' => array_values($record->participants->pluck('person_id')->all())];
    }

    /** @param array{kind:string,previous_version:string,previous_starts_at:CarbonImmutable,previous_ends_at:CarbonImmutable,previous_timezone:string,people:list<string>}|null $plan
     * @param  array<string,mixed>  $values
     * @param  array<string, int>|null  $preparedAccounts
     */
    public function retain(TenantContext $context, ?array $plan, CalendarActivity $activity, array $values, ?array $preparedAccounts = null): void
    {
        if ($plan === null) {
            return;
        }
        $people = array_values(array_unique([...$plan['people'], ...$activity->participants->pluck('person_id')->all()]));
        sort($people);
        $accounts = $preparedAccounts ?? [];
        if ($preparedAccounts === null) {
            foreach (array_chunk($people, 50) as $chunk) {
                $accounts += $this->people->accounts($context, $chunk);
            }
        }
        unset($plan['people']);
        $this->changes->append([...$plan, 'organization_id' => $context->organizationId, 'activity_id' => $activity->id,
            'activity_version' => $activity->version, 'actor_user_id' => $context->actorUserId, 'title' => $activity->title,
            'reason' => trim($values['change_reason']), 'starts_at' => $activity->starts_at, 'ends_at' => $activity->ends_at,
            'origin_timezone' => $activity->origin_timezone, 'created_at' => $activity->cancelled_at ?? now()], $people, $accounts);
    }

    /** Resolve recipients before a cancellation closes the event scope.
     * @param  list<string>  $personIds
     * @return array<string, int>
     */
    public function recipients(TenantContext $context, string $activityId, array $personIds): array
    {
        $accounts = [];
        foreach (array_chunk(array_values(array_unique($personIds)), 50) as $chunk) {
            $accounts += $this->people->accounts($context, $chunk, $activityId);
        }

        return $accounts;
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,CalendarChange>
     */
    public function history(TenantContext $context, string $activityId, array $input): LengthAwarePaginator
    {
        foreach (['calendar.read', 'calendar.write'] as $permission) {
            if (! $this->authorization->allows($context, $permission)) {
                $this->authorization->authorize($context, $permission, 'event', $activityId);
            }
        }
        $filters = CalendarChangeInput::validate($this->validator, $input);
        $activity = $this->calendar->find($context->organizationId, $activityId, new CalendarAudience($context->actorUserId, null, []), true);

        return $this->changes->history($context->organizationId, $activity->id, $filters);
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,CalendarChangeRecipient>
     */
    public function inbox(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'calendar.read');
        $filters = CalendarChangeInput::validate($this->validator, $input);
        $viewer = $this->people->viewer($context);
        $audience = new CalendarAudience($context->actorUserId, $viewer['person_id'], $this->teams->currentIds($context));

        return $this->changes->inbox($context->organizationId, $audience, $filters);
    }
}
