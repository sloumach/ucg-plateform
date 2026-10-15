<?php

namespace App\Modules\Contracts\Domain;

use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\Models\ContractReminderPlan;
use Carbon\CarbonImmutable;

final class ContractReminderSchedule
{
    public const array STATUSES = ['approved', 'sent', 'signed', 'active'];

    /** One catch-up per date: select the most urgent reached threshold, never a burst of missed alerts.
     * @return array<string, array{due_on: string, offset_days: int}>
     */
    public static function occurrences(ContractReminderPlan $plan, ContractRecord $contract, string $timezone): array
    {
        if (! $plan->enabled || ! in_array($contract->status, self::STATUSES, true)) {
            return [];
        }
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $result = [];
        foreach (['deadline' => $contract->ends_on, 'notice' => $plan->notice_on, 'renewal' => $plan->renewal_on] as $kind => $date) {
            if ($date === null) {
                continue;
            }
            $due = CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), $timezone);
            $days = (int) $today->diffInDays($due, false);
            $reached = array_filter($plan->offsets, fn (int $offset): bool => $days >= 0 && $days <= $offset);
            if ($reached !== []) {
                $result[$kind] = ['due_on' => $date->format('Y-m-d'), 'offset_days' => min($reached)];
            }
        }

        return $result;
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return ['min' => __('managed_contracts.reminder_min'), 'boolean' => __('managed_contracts.reminder_boolean'),
            'distinct' => __('managed_contracts.reminder_distinct'), 'accepted' => __('managed_contracts.reminder_retry_confirm'),
            'confirm_possible_duplicate.required' => __('managed_contracts.reminder_retry_confirm')];
    }

    /** @return array<string, array<mixed>> */
    public static function rules(): array
    {
        return ['contract_version' => ['required', 'uuid'], 'expected_version' => ['present', 'nullable', 'uuid'],
            'enabled' => ['required', 'boolean'], 'notice_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'renewal_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'offsets' => ['required', 'array', 'list', 'min:1', 'max:10'], 'offsets.*' => ['required', 'integer:strict', 'between:0,365', 'distinct:strict'],
            'channels' => ['required', 'array', 'list', 'min:1', 'max:2'], 'channels.*' => ['required', 'string', 'in:in_app,email', 'distinct:strict']];
    }
}
