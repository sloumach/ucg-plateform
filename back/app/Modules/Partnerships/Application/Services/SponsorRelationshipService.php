<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Modules\Partnerships\Application\Validation\PartnershipInput;
use App\Modules\Partnerships\Domain\Contracts\SponsorRepository;
use App\Modules\Partnerships\Domain\Models\SponsorContact;
use App\Modules\Partnerships\Domain\Models\SponsorshipInteraction;
use App\Modules\Partnerships\Domain\Models\SponsorshipOpportunity;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;

final readonly class SponsorRelationshipService
{
    public function __construct(private SponsorRepository $repository, private SponsorService $sponsors,
        private TenantBusinessGuard $guard, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, SponsorContact>|LengthAwarePaginator<int, SponsorshipOpportunity>|LengthAwarePaginator<int, SponsorshipInteraction>
     */
    public function paginate(TenantContext $context, string $sponsorId, string $kind, array $filters): LengthAwarePaginator
    {
        $sponsor = $this->sponsors->find($context, $sponsorId);
        $values = PartnershipInput::validate($this->validator, $filters, PartnershipInput::pageRules($kind));

        return $this->repository->related($context->organizationId, $sponsor->id, $kind, $values);
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, string $sponsorId, string $kind, array $input, ?string $id = null,
        ?string $ipAddress = null): SponsorContact|SponsorshipOpportunity|SponsorshipInteraction
    {
        $this->sponsors->authorizeWrite($context);
        $values = PartnershipInput::validate($this->validator, $input, PartnershipInput::saveRules($kind, $id === null));
        $this->sponsors->assertChanges($values, $id);

        return $this->database->transaction(function () use ($context, $sponsorId, $kind, $values, $id, $ipAddress): SponsorContact|SponsorshipOpportunity|SponsorshipInteraction {
            $this->guard->lock($context);
            $this->sponsors->authorizeWrite($context);
            $sponsor = $this->repository->find($context->organizationId, $sponsorId, true);
            $this->sponsors->assertEditable($sponsor->status, null, null);
            $record = $id === null ? null : $this->repository->findRelated($context->organizationId, $sponsor->id, $kind, $id);
            $this->sponsors->assertEditable($record?->status, $record?->version, $values['version'] ?? null);
            $attributes = $this->sponsors->attributes($values);
            if ($kind === 'interactions') {
                unset($attributes['version']);
                // Links must belong to this sponsor as well as this tenant; no People/User lookup is performed.
                foreach (['contact_id' => 'contacts', 'opportunity_id' => 'opportunities'] as $field => $relatedKind) {
                    if (isset($values[$field])) {
                        $related = $this->repository->findRelated($context->organizationId, $sponsor->id, $relatedKind, $values[$field]);
                        $this->sponsors->assertEditable($related->status, null, null);
                        $attributes[$field] = $related->id;
                    }
                }
                $attributes = ['contact_id' => null, 'opportunity_id' => null, ...$attributes,
                    'occurred_at' => CarbonImmutable::parse($values['occurred_at'])->utc(),
                    'created_by' => $context->actorUserId, 'created_at' => now()];
            } elseif ($record === null) {
                $attributes = [...match ($kind) {
                    'contacts' => ['position' => null, 'email' => null, 'phone' => null, 'status' => 'active'],
                    default => ['description' => null, 'status' => 'open'],
                }, ...$attributes];
            }
            $saved = $this->repository->saveRelated($kind, $record, [
                ...($record === null ? ['organization_id' => $context->organizationId, 'sponsor_id' => $sponsor->id] : []), ...$attributes]);
            $this->sponsors->audit($context, $kind, $sponsor->id, $saved->id, $saved instanceof SponsorshipInteraction ? null : $saved->status, $ipAddress);

            return $saved;
        });
    }
}
