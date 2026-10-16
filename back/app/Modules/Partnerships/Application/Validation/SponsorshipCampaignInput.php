<?php

namespace App\Modules\Partnerships\Application\Validation;

use Illuminate\Validation\Rule;

final class SponsorshipCampaignInput
{
    public const array KINDS = ['publication', 'video', 'event_presence', 'brand_placement'];

    public const array STATUSES = ['promised', 'delivered', 'validated', 'cancelled'];

    /**
     * @return array<string,array<mixed>> */
    public static function pageRules(string $kind = 'campaigns'): array
    {
        $rules = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];

        return $kind === 'history' || $kind === 'dashboard' ? $rules : [...$rules,
            'status' => ['sometimes', Rule::in($kind === 'campaigns' ? ['active', 'archived'] : self::STATUSES)]];
    }

    /**
     * @return array<string,array<mixed>> */
    public static function campaignRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return ['agreement_version' => ['required', 'uuid'], ...($creating ? [] : ['version' => ['required', 'uuid']]),
            'title' => [$required, 'required', 'string', 'max:160', 'regex:/\S/u'],
            'starts_on' => [$required, 'required', 'date_format:Y-m-d'], 'ends_on' => [$required, 'required', 'date_format:Y-m-d'],
            'responsible_user_id' => [$required, 'required', 'integer', 'min:1'],
            ...($creating ? [] : ['status' => ['sometimes', Rule::in(['archived'])]])];
    }

    /**
     * @return array<string,array<mixed>> */
    public static function versions(): array
    {
        return ['agreement_version' => ['required', 'uuid'], 'campaign_version' => ['required', 'uuid']];
    }

    /**
     * @return array<string,array<mixed>> */
    public static function deliverableRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [...self::versions(), ...($creating ? ['exposure_id' => ['required', 'uuid']] : ['version' => ['required', 'uuid']]),
            'title' => [$required, 'required', 'string', 'max:160', 'regex:/\S/u'], 'kind' => [$required, 'required', Rule::in(self::KINDS)],
            'responsible_user_id' => [$required, 'required', 'integer', 'min:1'],
            'due_at' => [$required, 'required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D']];
    }

    /**
     * @return array<string,array<mixed>> */
    public static function deliveryRules(): array
    {
        return [...self::versions(), 'version' => ['required', 'uuid'],
            'delivered_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', 'before_or_equal:now'],
            'proof_reference' => ['required', 'string', 'max:2000', 'regex:/\S/u']];
    }

    /**
     * @return array<string,array<mixed>> */
    public static function transitionRules(): array
    {
        return [...self::versions(), 'version' => ['required', 'uuid'], 'target_status' => ['required', Rule::in(['validated', 'promised', 'cancelled'])],
            'confirm_evidence' => ['exclude_unless:target_status,validated', 'required', 'accepted']];
    }
}
