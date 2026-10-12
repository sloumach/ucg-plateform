<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\People\Domain\Models\PersonPeriod;
use App\Modules\Tenancy\Application\Contracts\FieldVisibility;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDataRecord;
use App\Modules\Tenancy\Application\Contracts\TenantDataVisibility;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class PersonLifecycleProjectionService
{
    public function __construct(private TenantDataVisibility $visibility, private TenantAuthorization $authorization, private TenantAuditJournal $journal) {}

    public function authorizeRead(TenantContext $context): void
    {
        if (! $this->authorization->allows($context, 'sports.read') && ! $this->authorization->allows($context, 'administrative.read')) {
            throw new AuthorizationException;
        }
    }

    /** @param list<PersonPeriod> $periods
     * @return list<array<string,mixed>>
     */
    public function periods(TenantContext $context, array $periods): array
    {
        $at = CarbonImmutable::now();
        $records = [];
        foreach ($periods as $period) {
            $records[] = new TenantDataRecord($period->organization_id, ['id' => $period->id, 'version' => $period->version, 'person_id' => $period->person_id,
                'kind' => $period->kind, 'role_key' => $period->role_key, 'status' => $period->status, 'starts_at' => $period->starts_at->toIso8601String(), 'ends_at' => $period->ends_at?->toIso8601String(),
                'effective' => $period->status === 'active' && $period->starts_at->lessThanOrEqualTo($at) && ($period->ends_at === null || $period->ends_at->greaterThan($at))]);
        }

        return $this->visibility->projectMany($context, $records, $this->fields(['person_id', 'kind', 'role_key', 'status', 'starts_at', 'ends_at', 'effective'], 'sports.read'));
    }

    /** @param list<PersonDepartureItem> $items
     * @return list<array<string,mixed>>
     */
    public function items(TenantContext $context, array $items): array
    {
        $records = [];
        foreach ($items as $item) {
            $records[] = new TenantDataRecord($item->organization_id, ['id' => $item->id, 'version' => $item->version, 'person_id' => $item->person_id,
                'kind' => $item->kind, 'title' => $item->title, 'notes' => $item->notes, 'status' => $item->status, 'due_at' => $item->due_at?->toIso8601String(), 'completed_at' => $item->completed_at?->toIso8601String()]);
        }

        return $this->visibility->projectMany($context, $records, $this->fields(['person_id', 'kind', 'title', 'notes', 'status', 'due_at', 'completed_at'], 'administrative.read'));
    }

    /** @return array<string,mixed> */
    public function departure(TenantContext $context, ?PersonDeparture $departure, string $version): array
    {
        $fields = ['departed' => new FieldVisibility('organization.view'), 'person_version' => new FieldVisibility('organization.view')];
        $data = ['departed' => $departure !== null, 'person_version' => $version];
        if ($departure !== null) {
            $data = [...$data, 'id' => $departure->id, 'person_id' => $departure->person_id, 'departed_at' => $departure->departed_at->toIso8601String()];
            foreach (['id', 'person_id', 'departed_at'] as $name) {
                $fields[$name] = new FieldVisibility('organization.view');
            }
            foreach (['reason_key', 'pending_items_count'] as $name) {
                $fields[$name] = new FieldVisibility('administrative.read');
                $data[$name] = $departure->getAttribute($name);
            }
        }

        return $this->visibility->project($context, new TenantDataRecord($context->organizationId, $data), $fields);
    }

    /** @param list<string> $names
     * @return array<string,FieldVisibility>
     */
    private function fields(array $names, string $permission): array
    {
        $fields = ['id' => new FieldVisibility('organization.view'), 'version' => new FieldVisibility('organization.view')];
        foreach ($names as $name) {
            $fields[$name] = new FieldVisibility($permission);
        }

        return $fields;
    }

    /** @return array<string,mixed> */
    public function auditPeriod(PersonPeriod $period): array
    {
        return ['person_id' => $period->person_id, 'lifecycle_id' => $period->id, 'lifecycle_kind' => $period->kind, 'lifecycle_status' => $period->status,
            'starts_at' => $period->starts_at->toIso8601String(), 'ends_at' => $period->ends_at?->toIso8601String(), 'version' => $period->version];
    }

    /** @return array<string,mixed> */
    public function auditItem(PersonDepartureItem $item): array
    {
        return ['person_id' => $item->person_id, 'lifecycle_id' => $item->id, 'lifecycle_kind' => $item->kind, 'lifecycle_status' => $item->status, 'version' => $item->version];
    }

    /** @param array<string,mixed> $before
     * @param  array<string,mixed>  $after
     */
    public function audit(TenantContext $context, array $before, array $after, ?string $ip): void
    {
        $this->journal->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
            $context->organizationId, AuditReason::Domain, $context->requestId, $ip, $before, $after));
    }
}
