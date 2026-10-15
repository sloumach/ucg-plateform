<?php

namespace App\Modules\Contracts\Infrastructure\Persistence;

use App\Modules\Contracts\Domain\Contracts\ContractReminderRepository;
use App\Modules\Contracts\Domain\Models\ContractReminderDelivery;
use App\Modules\Contracts\Domain\Models\ContractReminderPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class EloquentContractReminderRepository implements ContractReminderRepository
{
    public function plan(string $organizationId, string $contractId, bool $lock = false): ?ContractReminderPlan
    {
        return ContractReminderPlan::query()->where('organization_id', $organizationId)->where('contract_id', strtolower($contractId))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function savePlan(?ContractReminderPlan $plan, array $attributes): ContractReminderPlan
    {
        $plan ??= new ContractReminderPlan;
        $plan->forceFill($attributes)->save();

        return $plan;
    }

    public function sweep(string $organizationId, int $limit): array
    {
        return array_values(ContractReminderPlan::query()->where('organization_id', $organizationId)->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subMinutes(15)))
            ->orderByRaw('CASE WHEN checked_at IS NULL THEN 0 ELSE 1 END')->orderBy('checked_at')->orderBy('id')->limit($limit)->get()->all());
    }

    public function record(array $attributes): ContractReminderDelivery
    {
        $existing = $this->query($attributes['organization_id'])->where('occurrence', $attributes['occurrence'])->first();
        if ($existing !== null) {
            return $existing;
        }
        $delivery = new ContractReminderDelivery;
        $delivery->forceFill($attributes)->save();

        return $delivery;
    }

    public function delivery(string $organizationId, string $id, bool $lock = false): ContractReminderDelivery
    {
        return $this->query($organizationId)->whereKey(strtolower($id))->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public function updateDelivery(ContractReminderDelivery $delivery, array $attributes): ContractReminderDelivery
    {
        $delivery->forceFill([...$attributes, 'version' => (string) Str::uuid()])->save();

        return $delivery;
    }

    public function pendingEmails(string $organizationId, int $limit): array
    {
        return array_values($this->query($organizationId)->where(fn ($query) => $query->where('email_status', 'pending')
            ->orWhere(fn ($query) => $query->where('email_status', 'sending')->where('email_started_at', '<=', now()->subMinutes(5))))
            ->orderBy('created_at')->orderBy('id')->limit($limit)->get()->all());
    }

    public function history(string $organizationId, string $contractId, array $filters): LengthAwarePaginator
    {
        return $this->query($organizationId)->where('contract_id', strtolower($contractId))->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function inbox(string $organizationId, int $accountId, ?string $personId, bool $financial, array $filters): LengthAwarePaginator
    {
        $query = $this->query($organizationId)->where('recipient_id', $accountId)->where('in_app_status', 'sent')
            ->where('person_id', $personId)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('contracts as c')->whereColumn('c.organization_id', 'contract_reminder_deliveries.organization_id')
                ->whereColumn('c.id', 'contract_reminder_deliveries.contract_id')->whereColumn('c.responsible_person_id', 'contract_reminder_deliveries.person_id'));
        if (! $financial) {
            $query->whereNotExists(fn ($query) => $query->selectRaw('1')->from('contract_financial_terms as f')
                ->whereColumn('f.organization_id', 'contract_reminder_deliveries.organization_id')->whereColumn('f.contract_id', 'contract_reminder_deliveries.contract_id'));
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    /** @return Builder<ContractReminderDelivery> */
    private function query(string $organizationId): Builder
    {
        return ContractReminderDelivery::query()->where('organization_id', $organizationId);
    }
}
