<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\Scheduling\Application\Data\CalendarPage;
use App\Modules\Scheduling\Application\Validation\CalendarInput;
use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\CalendarLink;
use App\Modules\Scheduling\Domain\CalendarTime;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Teams\Application\Contracts\CalendarTeamsDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class CalendarService
{
    public function __construct(private CalendarRepository $records, private CalendarPeopleDirectory $people, private CalendarTeamsDirectory $teams,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit,
        private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string,mixed> $input */
    public function paginate(TenantContext $context, array $input): CalendarPage
    {
        $values = $this->validated($context, $input, 'index');
        $management = $this->management($context, $values);
        if (($values['archived'] ?? false) && ! $management) {
            throw ValidationException::withMessages(['archived' => __('calendar.management_required')]);
        }
        $viewer = $this->people->viewer($context);
        $timezone = $values['timezone'] ?? $viewer['timezone'];
        try {
            [$from,$to] = CalendarTime::window($values['from_date'], $values['to_date'], $timezone);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['to_date' => __('calendar.window')]);
        }
        $page = $this->records->paginate($context->organizationId, $this->audience($context, $viewer['person_id']), $from, $to, $values, $management);
        $this->label($context, array_values($page->items()));

        return new CalendarPage($page, $viewer['timezone'], $timezone, $from->toIso8601String(), $to->toIso8601String());
    }

    /** @param array<string,mixed> $input */
    public function find(TenantContext $context, string $id, array $input): CalendarActivity
    {
        $values = $this->validated($context, $input, 'show');
        $viewer = $this->people->viewer($context);
        $record = $this->records->find($context->organizationId, $id, $this->audience($context, $viewer['person_id']), $this->management($context, $values));
        $this->label($context, [$record]);

        return $record;
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,array{id:string,label:string}>
     */
    public function references(TenantContext $context, string $type, array $input): LengthAwarePaginator
    {
        $this->write($context);
        if (! in_array($type, ['people', 'teams'], true)) {
            throw new InvalidArgumentException('Unknown calendar reference.');
        }
        $values = $this->validated($context, $input, $type);

        return $type === 'people' ? $this->people->choices($context, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10))
            : $this->teams->choices($context, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ip = null): CalendarActivity
    {
        $this->write($context);
        $values = $this->validated($context, $input, $id === null ? 'store' : 'update');
        $saved = $this->database->transaction(function () use ($context, $values, $id, $ip): CalendarActivity {
            $this->guard->lock($context);
            $this->write($context);
            $record = $id === null ? null : $this->records->find($context->organizationId, $id, new CalendarAudience($context->actorUserId, null, []), true, true);
            if ($record !== null && $record->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('calendar.stale'), 'CALENDAR_VERSION_STALE');
            }
            if ($record?->archived_at !== null) {
                throw new DomainConflictException(__('calendar.archived'), 'CALENDAR_ARCHIVED');
            }
            if (isset($values['archive'])) {
                if ($record === null || array_diff(array_keys($values), ['version', 'archive']) !== []) {
                    throw ValidationException::withMessages(['archive' => __('calendar.archive_only')]);
                }
                $attributes = ['archived_at' => now()];
                $personIds = null;
            } else {
                if (array_diff(array_keys($values), ['version', 'confirm_sharing']) === []) {
                    throw ValidationException::withMessages(['record' => __('calendar.empty')]);
                }
                $attributes = $this->attributes($context, $values, $record);
                $personIds = isset($values['participant_ids']) ? array_values(array_map(strtolower(...), $values['participant_ids'])) : ($record === null ? [] : null);
                if ($personIds !== null) {
                    $this->people->assertAssignable($context, $personIds);
                }
            }
            $saved = $this->records->save($record, [...$attributes, 'version' => (string) Str::uuid()], $personIds);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
                $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['calendar_activity_id' => $saved->id,
                    'calendar_action' => isset($values['archive']) ? 'archived' : ($id === null ? 'created' : 'updated')]));

            return $saved;
        });
        $this->label($context, [$saved]);

        return $saved;
    }

    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function attributes(TenantContext $context, array $values, ?CalendarActivity $record): array
    {
        $attributes = array_intersect_key($values, array_flip(['title', 'kind', 'description', 'location', 'url', 'team_id', 'visibility']));
        foreach (['title', 'description', 'location', 'url'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = trim($attributes[$field]);
            }
        }
        if (isset($attributes['team_id'])) {
            $attributes['team_id'] = strtolower($attributes['team_id']);
        }
        $team = array_key_exists('team_id', $attributes) ? $attributes['team_id'] : $record?->team_id;
        if ($team !== null) {
            $this->teams->assertActive($context, $team);
        }
        $visibility = $attributes['visibility'] ?? ($record === null ? 'private' : $record->visibility);
        if ($visibility === 'team' && $team === null) {
            throw ValidationException::withMessages(['team_id' => __('calendar.team_required')]);
        }
        if ($record === null || array_intersect(array_keys($values), ['starts_at', 'ends_at', 'origin_timezone']) !== []) {
            foreach (['starts_at', 'ends_at', 'origin_timezone'] as $field) {
                if (! isset($values[$field])) {
                    throw ValidationException::withMessages([$field => __('calendar.period_required')]);
                }
            }
            foreach (['starts_at', 'ends_at'] as $field) {
                try {
                    $attributes[$field] = CalendarTime::instant($values[$field], $values['origin_timezone']);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages([$field => __('calendar.offset')]);
                }
            }
            $duration = $attributes['starts_at']->diffInSeconds($attributes['ends_at'], false);
            if ($duration <= 0 || $duration > CalendarTime::MAX_DURATION_SECONDS) {
                throw ValidationException::withMessages(['ends_at' => __('calendar.period')]);
            }
            $attributes['origin_timezone'] = $values['origin_timezone'];
        }
        if (array_key_exists('url', $attributes)) {
            try {
                $attributes['url'] = CalendarLink::validate($attributes['url']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['url' => __('calendar.url')]);
            }
        }
        if ($visibility !== 'private') {
            $this->authorization->authorize($context, 'calendar.publish');
            if (! isset($values['confirm_sharing'])) {
                throw ValidationException::withMessages(['confirm_sharing' => __('calendar.confirm')]);
            }
            $attributes['sharing_authorized_by'] = $context->actorUserId;
            $attributes['sharing_authorized_at'] = now();
        } else {
            $attributes['sharing_authorized_by'] = null;
            $attributes['sharing_authorized_at'] = null;
        }

        return [...($record === null ? ['organization_id' => $context->organizationId, 'created_by' => $context->actorUserId, 'visibility' => $visibility] : []), ...$attributes];
    }

    private function audience(TenantContext $context, ?string $personId): CalendarAudience
    {
        return new CalendarAudience($context->actorUserId, $personId, $this->teams->currentIds($context));
    }

    /** @param array<string,mixed> $values */
    private function management(TenantContext $context, array $values): bool
    {
        if ($values['management'] ?? false) {
            $this->write($context);

            return true;
        }

        return false;
    }

    private function write(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'calendar.read');
        $this->authorization->authorize($context, 'calendar.write');
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validated(TenantContext $context, array $input, string $operation): array
    {
        $this->authorization->authorize($context, 'calendar.read');

        return CalendarInput::validate($this->validator, $input, $operation);
    }

    /** @param list<CalendarActivity> $records */
    private function label(TenantContext $context, array $records): void
    {
        $teamIds = [];
        $personIds = [];
        foreach ($records as $record) {
            if ($record->team_id !== null) {
                $teamIds[] = $record->team_id;
            } foreach ($record->participants as $participant) {
                $personIds[] = $participant->person_id;
            }
        }
        $teams = $this->teams->labels($context, array_values(array_unique($teamIds)));
        $people = $this->people->labels($context, array_values(array_unique($personIds)));
        foreach ($records as $record) {
            $record->setAttribute('team_label', $record->team_id === null ? null : ($teams[$record->team_id] ?? null));
            $record->setAttribute('participant_labels', $people);
        }
    }
}
