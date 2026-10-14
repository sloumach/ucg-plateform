<?php

namespace App\Modules\Contracts\Application\Validation;

final class ContractGovernanceInput
{
    public const array FIELDS = ['currency', 'remuneration', 'remuneration_period', 'bonus', 'prize_share_percent', 'payment_terms'];

    /** Values stay decimal strings from HTTP to storage; no floating-point conversion.
     * @return array<string, array<mixed>>
     */
    public static function financialRules(): array
    {
        return ['contract_version' => ['required', 'uuid'], 'expected_version' => ['present', 'nullable', 'uuid'],
            'terms' => ['required', 'array:'.implode(',', self::FIELDS)],
            'terms.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'terms.remuneration' => ['present', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})[.][0-9]{2}$/D'],
            'terms.remuneration_period' => ['required_with:terms.remuneration', 'nullable', 'string', 'in:once,monthly,yearly'],
            'terms.bonus' => ['present', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})[.][0-9]{2}$/D'],
            'terms.prize_share_percent' => ['present', 'nullable', 'string', 'regex:/^(?:(?:0|[1-9][0-9]?)[.][0-9]{2}|100[.]00)$/D'],
            'terms.payment_terms' => ['present', 'nullable', 'string', 'max:2000', 'regex:/\\S/u']];
    }

    /** @return array<string, array<mixed>> */
    public static function approvalRules(): array
    {
        return ['contract_version' => ['required', 'uuid'], 'document_version_id' => ['required', 'uuid'],
            'confirm_approval' => ['required', 'accepted']];
    }
}
