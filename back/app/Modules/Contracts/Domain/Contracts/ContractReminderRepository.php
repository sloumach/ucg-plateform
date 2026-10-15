<?php

namespace App\Modules\Contracts\Domain\Contracts;

use App\Modules\Contracts\Domain\Models\ContractReminderDelivery;
use App\Modules\Contracts\Domain\Models\ContractReminderPlan;
use Illuminate\Pagination\LengthAwarePaginator;

interface ContractReminderRepository
{
    public function plan(string $organizationId, string $contractId, bool $lock = false): ?ContractReminderPlan;

    /** @param array<string,mixed> $attributes */
    public function savePlan(?ContractReminderPlan $plan, array $attributes): ContractReminderPlan;

    /** @return list<ContractReminderPlan> */
    public function sweep(string $organizationId, int $limit): array;

    /** @param array<string,mixed> $attributes */
    public function record(array $attributes): ContractReminderDelivery;

    public function delivery(string $organizationId, string $id, bool $lock = false): ContractReminderDelivery;

    /** @param array<string,mixed> $attributes */
    public function updateDelivery(ContractReminderDelivery $delivery, array $attributes): ContractReminderDelivery;

    /** @return list<ContractReminderDelivery> */
    public function pendingEmails(string $organizationId, int $limit): array;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,ContractReminderDelivery>
     */
    public function history(string $organizationId, string $contractId, array $filters): LengthAwarePaginator;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,ContractReminderDelivery>
     */
    public function inbox(string $organizationId, int $accountId, ?string $personId, bool $financial, array $filters): LengthAwarePaginator;
}
