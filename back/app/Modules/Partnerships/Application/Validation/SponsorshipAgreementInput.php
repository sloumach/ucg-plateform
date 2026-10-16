<?php

namespace App\Modules\Partnerships\Application\Validation;

use Illuminate\Validation\Rule;

final class SponsorshipAgreementInput
{
    public const int MAX_EXPOSURES = 100;

    public const array STATUSES = ['draft', 'approved', 'ended', 'archived'];

    public const array CHANNELS = ['jersey', 'social', 'stream', 'event', 'website', 'other'];

    /** @return array<string, array<mixed>> */
    public static function pageRules(string $kind = 'agreements'): array
    {
        $rules = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];

        return $kind === 'agreements' ? [...$rules, 'q' => ['sometimes', 'string', 'max:80', 'regex:/\S/u'], 'status' => ['sometimes', Rule::in(self::STATUSES)]]
            : ($kind === 'exposures' ? [...$rules, 'status' => ['sometimes', Rule::in(['active', 'archived'])]] : $rules);
    }

    /** @return array<string, array<mixed>> */
    public static function saveRules(bool $creating): array
    {
        return [...($creating ? ['reference' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'opportunity_id' => ['sometimes', 'nullable', 'uuid']] : ['version' => ['required', 'uuid']]),
            'title' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:160', 'regex:/\S/u'],
            'starts_on' => [$creating ? 'required' : 'sometimes', 'required', 'date_format:Y-m-d'],
            'ends_on' => [$creating ? 'required' : 'sometimes', 'required', 'date_format:Y-m-d'],
            'responsible_user_id' => [$creating ? 'required' : 'sometimes', 'required', 'integer', 'min:1'],
            'contract_id' => ['sometimes', 'nullable', 'uuid']];
    }

    /** @return array<string, array<mixed>> */
    public static function exposureRules(bool $creating): array
    {
        return ['agreement_version' => ['required', 'uuid'], ...($creating ? [
            'target_type' => ['required', Rule::in(['organization', 'team', 'player', 'event', 'content'])],
            'target_id' => ['required', 'uuid']] : ['version' => ['required', 'uuid']]),
            'channel' => [$creating ? 'required' : 'sometimes', 'required', Rule::in(self::CHANNELS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'authorization_reference' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:160', 'regex:/\S/u'],
            'exclusivity_key' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            ...($creating ? [] : ['status' => ['sometimes', Rule::in(['active', 'archived'])]])];
    }

    /** Decimal strings only; the in-kind description is financially restricted too.
     * @return array<string, array<mixed>> */
    public static function financialRules(): array
    {
        return ['agreement_version' => ['required', 'uuid'], 'expected_version' => ['present', 'nullable', 'uuid'],
            'terms' => ['required', 'array:kind,currency,amount,in_kind,payment_terms'],
            'terms.kind' => ['required', Rule::in(['cash', 'in_kind', 'mixed'])],
            'terms.currency' => ['present', 'nullable', 'string', 'regex:/^[A-Z]{3}$/D'],
            'terms.amount' => ['present', 'nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D'],
            'terms.in_kind' => ['present', 'nullable', 'string', 'max:2000', 'regex:/\S/u'],
            'terms.payment_terms' => ['present', 'nullable', 'string', 'max:2000']];
    }

    /** @return array<string, array<mixed>> */
    public static function transitionRules(): array
    {
        return ['version' => ['required', 'uuid'], 'target_status' => ['required', Rule::in(['approved', 'ended', 'archived'])],
            'confirm_authorizations' => ['exclude_unless:target_status,approved', 'required', 'accepted']];
    }
}
