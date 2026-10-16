<?php

namespace App\Modules\Contracts\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Contracts\PartnershipContractDirectory;
use App\Modules\Contracts\Domain\ContractLifecycle;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class PartnershipContractService implements PartnershipContractDirectory
{
    public function __construct(private ContractRepository $contracts, private TenantAuthorization $authorization) {}

    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $this->authorization->authorize($context, 'administrative.read');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('Contract references must be paginated.');
        }

        $result = $this->contracts->paginate($context->organizationId, ['type' => 'sponsor', 'page' => $page, 'per_page' => $perPage]);

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn (ContractRecord $record): array => ['id' => $record->id, 'label' => $record->reference], $result->items()),
            $result->total(), $result->perPage(), $result->currentPage());
    }

    public function bind(TenantContext $context, string $id): string
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $this->authorization->authorize($context, 'administrative.read');
        $record = $this->contracts->find($context->organizationId, $id, true);
        if ($record->type !== 'sponsor') {
            throw (new ModelNotFoundException)->setModel(ContractRecord::class, [$id]);
        }
        if (in_array($record->status, ['ended', 'terminated', 'archived'], true)) {
            throw new DomainConflictException(__('sponsorships.contract_unavailable'), 'SPONSORSHIP_CONTRACT_UNAVAILABLE');
        }

        return $record->version;
    }

    public function isCurrent(TenantContext $context, string $id, string $version): bool
    {
        $this->authorization->authorize($context, 'partnerships.read');
        try {
            $record = $this->contracts->find($context->organizationId, $id);
        } catch (ModelNotFoundException) {
            return false;
        }

        return $record->type === 'sponsor' && $record->version === $version && ContractLifecycle::inForce($record->status,
            $record->starts_on?->format('Y-m-d'), $record->ends_on?->format('Y-m-d'), CarbonImmutable::now());
    }
}
