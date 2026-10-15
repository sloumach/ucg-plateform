<?php

namespace App\Modules\Partnerships\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PartnershipInput
{
    public const array SPONSOR_STATUSES = ['prospect', 'partner', 'archived'];

    public const array OPPORTUNITY_STATUSES = ['open', 'contacted', 'negotiating', 'won', 'lost', 'archived'];

    public const array CHANNELS = ['note', 'email', 'phone', 'meeting', 'other'];

    /** @return array<string, array<mixed>> */
    public static function pageRules(string $kind = 'sponsors'): array
    {
        $rules = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'string', 'max:80', 'regex:/\S/u']];
        if ($kind === 'interactions') {
            return [...$rules, 'channel' => ['sometimes', Rule::in(self::CHANNELS)],
                'contact_id' => ['sometimes', 'uuid'], 'opportunity_id' => ['sometimes', 'uuid']];
        }

        return [...$rules, 'status' => ['sometimes', Rule::in(match ($kind) {
            'sponsors' => self::SPONSOR_STATUSES, 'opportunities' => self::OPPORTUNITY_STATUSES, default => ['active', 'archived'],
        })]];
    }

    /** @return array<string, array<mixed>> */
    public static function saveRules(string $kind, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $version = $creating ? [] : ['version' => ['required', 'uuid']];
        $name = [$required, 'required', 'string', 'max:160', 'regex:/\S/u'];

        return match ($kind) {
            'sponsors' => [...$version, 'name' => $name, 'industry' => ['sometimes', 'nullable', 'string', 'max:120'],
                'status' => ['sometimes', 'required', Rule::in(self::SPONSOR_STATUSES)]],
            'contacts' => [...$version, 'name' => $name, 'position' => ['sometimes', 'nullable', 'string', 'max:120'],
                'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{3,32}$/D'],
                'status' => ['sometimes', 'required', Rule::in(['active', 'archived'])]],
            'opportunities' => [...$version, 'title' => $name, 'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
                'status' => ['sometimes', 'required', Rule::in(self::OPPORTUNITY_STATUSES)]],
            'interactions' => ['channel' => ['required', Rule::in(self::CHANNELS)],
                'summary' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
                'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
                'contact_id' => ['sometimes', 'nullable', 'uuid'], 'opportunity_id' => ['sometimes', 'nullable', 'uuid']],
            default => throw new InvalidArgumentException('Unknown partnership record type.'),
        };
    }

    /** @param array<string, mixed> $input
     * @param  array<string, array<mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function validate(Factory $validator, array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('partnerships.immutable')));
        }

        return $validator->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['required' => __('partnerships.required'), 'string' => __('partnerships.string'),
            'max' => __('partnerships.max'), 'in' => __('partnerships.choice'), 'regex' => __('partnerships.format'),
            'email' => __('partnerships.email'), 'uuid' => __('partnerships.uuid'), 'integer' => __('partnerships.integer'),
            'min' => __('partnerships.min'), 'date_format' => __('partnerships.date'), 'before_or_equal' => __('partnerships.future')];
    }
}
