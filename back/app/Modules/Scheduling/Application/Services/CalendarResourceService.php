<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Scheduling\Application\Validation\CalendarResourceInput;
use App\Modules\Scheduling\Domain\Contracts\CalendarResourceRepository;
use App\Modules\Scheduling\Domain\Models\CalendarResource;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CalendarResourceService
{
    public function __construct(private CalendarResourceRepository $records, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private ConnectionInterface $database, private Factory $validator) {}

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,CalendarResource>
     */
    public function paginate(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->authorize($context, false);
        $values = CalendarResourceInput::validate($this->validator, $input, 'index');

        return $this->records->paginate($context->organizationId, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10), (bool) ($values['archived'] ?? false));
    }

    /** @param array<string,mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ip = null): CalendarResource
    {
        $this->authorize($context, true);
        $values = CalendarResourceInput::validate($this->validator, $input, $id === null ? 'store' : 'update');
        try {
            return $this->database->transaction(function () use ($context, $values, $id, $ip): CalendarResource {
                $this->guard->lock($context);
                $this->authorize($context, true);
                $record = $id === null ? null : $this->records->find($context->organizationId, $id, true);
                if ($record !== null && $record->version !== strtolower($values['version'])) {
                    throw new DomainConflictException(__('calendar.stale'), 'CALENDAR_RESOURCE_STALE');
                }
                if ($record?->status === 'archived') {
                    throw new DomainConflictException(__('calendar.resource_archived'), 'CALENDAR_RESOURCE_ARCHIVED');
                }
                if ($record !== null && array_diff(array_keys($values), ['version']) === []) {
                    throw ValidationException::withMessages(['record' => __('calendar.empty')]);
                }
                if (isset($values['archive']) && isset($values['label'])) {
                    throw ValidationException::withMessages(['archive' => __('calendar.archive_only')]);
                }
                $attributes = $record === null ? ['organization_id' => $context->organizationId, 'created_by' => $context->actorUserId, 'key' => $values['key'], 'kind' => $values['kind'], 'status' => 'active'] : [];
                if (isset($values['label'])) {
                    $attributes['label'] = trim($values['label']);
                }
                if (isset($values['archive'])) {
                    $attributes['status'] = 'archived';
                }
                $saved = $this->records->save($record, [...$attributes, 'version' => (string) Str::uuid()]);
                $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId,
                    AuditReason::Domain, $context->requestId, $ip, [], ['calendar_resource_id' => $saved->id, 'calendar_action' => isset($values['archive']) ? 'archived' : ($id === null ? 'created' : 'updated')]));

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('calendar.resource_key_taken'), 'CALENDAR_RESOURCE_KEY_TAKEN');
        }
    }

    private function authorize(TenantContext $context, bool $write): void
    {
        $this->authorization->authorize($context, 'calendar.read');
        $this->authorization->authorize($context, 'calendar.write');
        if ($write) {
            $this->authorization->authorize($context, 'calendar.resources.manage');
        }
    }
}
