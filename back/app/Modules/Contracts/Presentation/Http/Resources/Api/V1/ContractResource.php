<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\ContractLifecycle;
use App\Modules\Contracts\Domain\Models\ContractObligation;
use App\Modules\Contracts\Domain\Models\ContractParty;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class ContractResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractRecord $contract */
        $contract = $this->resource;
        $data = ['id' => $contract->id, 'reference' => $contract->reference, 'type' => $contract->type, 'title' => $contract->title,
            'responsible_person_id' => $contract->responsible_person_id, 'responsible_name' => $contract->responsible_name,
            'starts_on' => $contract->starts_on?->format('Y-m-d'), 'ends_on' => $contract->ends_on?->format('Y-m-d'),
            'status' => $contract->status, 'version' => $contract->version, 'prepared_by' => $contract->prepared_by,
            'approved_by' => $contract->approved_by, 'approved_at' => $contract->approved_at?->toIso8601String(),
            'signed_on' => $contract->signed_on?->format('Y-m-d'), 'signature_reference' => $contract->signature_reference,
            'signature_recorded_at' => $contract->signature_recorded_at?->toIso8601String(), 'terminated_on' => $contract->terminated_on?->format('Y-m-d'),
            'signature_evidence_status' => $contract->signed_on === null ? 'not_declared' : 'declared_external',
            'in_force' => ContractLifecycle::inForce($contract->status, $contract->starts_on?->format('Y-m-d'), $contract->ends_on?->format('Y-m-d'), CarbonImmutable::now()),
            'sporting_eligibility' => 'not_assessed', 'publication_status' => 'private',
            'parties_count' => $contract->parties_count, 'obligations_count' => $contract->obligations_count,
            'pending_obligations_count' => $contract->pending_obligations_count, 'available_transitions' => ContractLifecycle::TRANSITIONS[$contract->status]];
        if ($contract->relationLoaded('parties')) {
            $data['parties'] = $contract->parties->map(fn (ContractParty $party): array => ['id' => $party->id, 'kind' => $party->kind,
                'person_id' => $party->person_id, 'name' => $party->name, 'position' => $party->position])->all();
        }
        if ($contract->relationLoaded('obligations')) {
            $data['obligations'] = $contract->obligations->map(fn (ContractObligation $item): array => ['id' => $item->id,
                'title' => $item->title, 'due_on' => $item->due_on?->format('Y-m-d'), 'position' => $item->position,
                'status' => $item->status, 'version' => $item->version])->all();
        }

        return $data;
    }
}
