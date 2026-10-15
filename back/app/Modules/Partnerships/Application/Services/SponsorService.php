<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Partnerships\Application\Validation\PartnershipInput;
use App\Modules\Partnerships\Domain\Contracts\SponsorRepository;
use App\Modules\Partnerships\Domain\Models\Sponsor;
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

final readonly class SponsorService
{
    public function __construct(private SponsorRepository $sponsors, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private Factory $validator,
        private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Sponsor>
     */
    public function paginate(TenantContext $context, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'partnerships.read');

        return $this->sponsors->paginate($context->organizationId, PartnershipInput::validate($this->validator, $filters, PartnershipInput::pageRules()));
    }

    public function find(TenantContext $context, string $id): Sponsor
    {
        $this->authorization->authorize($context, 'partnerships.read');

        return $this->sponsors->find($context->organizationId, $id);
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ipAddress = null): Sponsor
    {
        $this->authorizeWrite($context);
        $values = PartnershipInput::validate($this->validator, $input, PartnershipInput::saveRules('sponsors', $id === null));
        $this->assertChanges($values, $id);

        return $this->database->transaction(function () use ($context, $values, $id, $ipAddress): Sponsor {
            $this->guard->lock($context);
            $this->authorizeWrite($context);
            $record = $id === null ? null : $this->sponsors->find($context->organizationId, $id, true);
            $this->assertEditable($record?->status, $record?->version, $values['version'] ?? null);
            $attributes = $this->attributes($values);
            $saved = $this->sponsors->save($record, [...($record === null ? ['organization_id' => $context->organizationId,
                'industry' => null, 'status' => 'prospect'] : []), ...$attributes]);
            $this->audit($context, 'sponsor', $saved->id, $saved->id, $saved->status, $ipAddress);

            return $saved;
        });
    }

    public function authorizeWrite(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $this->authorization->authorize($context, 'partnerships.write');
    }

    public function assertEditable(?string $status, ?string $version, mixed $expectedVersion): void
    {
        if ($version !== null && $version !== strtolower((string) $expectedVersion)) {
            throw new DomainConflictException(__('partnerships.stale'), 'PARTNERSHIP_VERSION_STALE');
        }
        if ($status === 'archived') {
            throw new DomainConflictException(__('partnerships.archived'), 'PARTNERSHIP_ARCHIVED');
        }
    }

    /** @param array<string, mixed> $values */
    public function assertChanges(array $values, ?string $id): void
    {
        if ($id !== null && array_diff(array_keys($values), ['version']) === []) {
            throw ValidationException::withMessages(['record' => __('partnerships.empty')]);
        }
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function attributes(array $values): array
    {
        unset($values['version']);
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = trim($value);
            }
        }

        return [...$values, 'version' => (string) Str::uuid()];
    }

    /** Audit metadata deliberately excludes names, contact details and exchange contents. */
    public function audit(TenantContext $context, string $kind, string $sponsorId, string $id, ?string $status, ?string $ipAddress): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
            AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress,
            [], ['partnership_kind' => $kind, 'sponsor_id' => $sponsorId, 'partnership_record_id' => $id, 'partnership_status' => $status]));
    }
}
