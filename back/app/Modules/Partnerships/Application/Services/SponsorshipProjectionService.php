<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Tenancy\Application\Contracts\FieldVisibility;
use App\Modules\Tenancy\Application\Contracts\PartnershipMemberDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDataRecord;
use App\Modules\Tenancy\Application\Contracts\TenantDataVisibility;

final readonly class SponsorshipProjectionService
{
    public function __construct(private TenantDataVisibility $visibility, private PartnershipMemberDirectory $members) {}

    /** @return array<string, mixed> */
    public function project(TenantContext $context, SponsorshipAgreement $record): array
    {
        return $this->visibility->project($context, new TenantDataRecord($record->organization_id, $this->values($record, $this->members->labels($context, [$record->responsible_user_id]))), $this->fields());
    }

    /** @param list<SponsorshipAgreement> $records
     * @return list<array<string, mixed>> */
    public function many(TenantContext $context, array $records): array
    {
        $labels = $this->members->labels($context, array_values(array_unique(array_map(fn (SponsorshipAgreement $r): int => $r->responsible_user_id, $records))));

        return $this->visibility->projectMany($context, array_map(fn (SponsorshipAgreement $r): TenantDataRecord => new TenantDataRecord($r->organization_id, $this->values($r, $labels)), $records), $this->fields());
    }

    /** @param array<int, string> $labels
     * @return array<string, mixed> */
    private function values(SponsorshipAgreement $r, array $labels): array
    {
        return ['id' => $r->id, 'sponsor_id' => $r->sponsor_id, 'opportunity_id' => $r->opportunity_id, 'reference' => $r->reference,
            'title' => $r->title, 'starts_on' => $r->starts_on->format('Y-m-d'), 'ends_on' => $r->ends_on->format('Y-m-d'),
            'responsible_user_id' => $r->responsible_user_id, 'responsible_label' => $labels[$r->responsible_user_id] ?? null, 'contract_id' => $r->contract_id, 'contract_version' => $r->contract_version,
            'status' => $r->status, 'version' => $r->version, 'prepared_by' => $r->prepared_by, 'approved_by' => $r->approved_by,
            'approved_at' => $r->approved_at?->toIso8601String(), 'publication_status' => 'private'];
    }

    /** @return array<string, FieldVisibility> */
    private function fields(): array
    {
        $fields = [];
        foreach (['id', 'sponsor_id', 'opportunity_id', 'reference', 'title', 'starts_on', 'ends_on', 'responsible_user_id',
            'responsible_label', 'status', 'version', 'prepared_by', 'approved_by', 'approved_at', 'publication_status'] as $field) {
            $fields[$field] = new FieldVisibility('partnerships.read');
        }
        $fields['contract_id'] = new FieldVisibility('administrative.read');
        $fields['contract_version'] = new FieldVisibility('administrative.read');

        return $fields;
    }
}
