<?php

namespace App\Modules\Contracts\Infrastructure\Persistence;

use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractObligation;
use App\Modules\Contracts\Domain\Models\ContractParty;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\Models\ContractTransition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class EloquentContractRepository implements ContractRepository
{
    public function expiring(string $organizationId, string $from, string $to, int $page): LengthAwarePaginator
    {
        return ContractRecord::query()->where('organization_id', $organizationId)->whereIn('status', ['signed', 'active'])
            ->where('ends_on', '>=', $from)->where('ends_on', '<', $to)->orderBy('ends_on')->orderBy('id')
            ->paginate(5, ['id', 'reference', 'status', 'ends_on'], 'page', $page);
    }

    /** @return Builder<ContractRecord> */
    private function query(string $org): Builder
    {
        return ContractRecord::query()->where('organization_id', strtolower($org))->withExists([
            'financialTerms as financial_terms_present' => fn (Builder $query) => $query->where('organization_id', strtolower($org))->whereNull('document_id'),
            'financialTerms as financial_documents_restricted' => fn (Builder $query) => $query->where('organization_id', strtolower($org)),
        ])->withCount([
            'parties' => fn (Builder $query) => $query->where('contract_parties.organization_id', strtolower($org)),
            'obligations' => fn (Builder $query) => $query->where('contract_obligations.organization_id', strtolower($org)),
            'transitions' => fn (Builder $query) => $query->where('contract_transitions.organization_id', strtolower($org)),
            'obligations as pending_obligations_count' => fn (Builder $query) => $query->where('contract_obligations.organization_id', strtolower($org))->where('status', 'pending')]);
    }

    public function paginate(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = $this->query($organizationId);
        foreach (['type', 'status', 'responsible_person_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['q'])) {
            $query->where('reference', 'like', $filters['q'].'%');
        }

        return $query->orderBy('reference')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $organizationId, string $id, bool $lock = false): ContractRecord
    {
        $query = $this->query($organizationId)->with([
            'parties' => fn ($query) => $query->where('contract_parties.organization_id', strtolower($organizationId)),
            'obligations' => fn ($query) => $query->where('contract_obligations.organization_id', strtolower($organizationId)),
        ]);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function count(string $organizationId): int
    {
        return ContractRecord::query()->where('organization_id', strtolower($organizationId))->count();
    }

    public function save(?ContractRecord $contract, array $attributes): ContractRecord
    {
        $contract ??= new ContractRecord;
        $contract->forceFill($attributes)->save();

        return $contract;
    }

    public function replaceParties(ContractRecord $contract, array $parties): void
    {
        ContractParty::query()->where('organization_id', $contract->organization_id)->where('contract_id', $contract->id)->delete();
        foreach ($parties as $position => $attributes) {
            (new ContractParty)->forceFill([...$attributes, 'organization_id' => $contract->organization_id,
                'contract_id' => $contract->id, 'position' => $position + 1])->save();
        }
    }

    public function replaceObligations(ContractRecord $contract, array $obligations): void
    {
        ContractObligation::query()->where('organization_id', $contract->organization_id)->where('contract_id', $contract->id)->delete();
        foreach ($obligations as $position => $attributes) {
            (new ContractObligation)->forceFill([...$attributes, 'organization_id' => $contract->organization_id, 'contract_id' => $contract->id,
                'position' => $position + 1, 'status' => 'pending', 'version' => (string) Str::uuid()])->save();
        }
    }

    public function obligation(string $organizationId, string $contractId, string $id): ContractObligation
    {
        return ContractObligation::query()->where('organization_id', strtolower($organizationId))->where('contract_id', strtolower($contractId))
            ->lockForUpdate()->findOrFail(strtolower($id));
    }

    public function saveObligation(ContractObligation $obligation, string $status, string $version): void
    {
        $obligation->forceFill(['status' => $status, 'version' => $version])->save();
    }

    public function recordTransition(array $attributes): void
    {
        (new ContractTransition)->forceFill($attributes)->save();
    }

    public function transitions(string $organizationId, string $contractId, array $filters): LengthAwarePaginator
    {
        return ContractTransition::query()->where('organization_id', strtolower($organizationId))->where('contract_id', strtolower($contractId))
            ->orderByDesc('occurred_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
}
