<?php

namespace App\Modules\Teams\Application\Validation;

use Closure;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExternalOpponentInput
{
    public const array KINDS = ['organization', 'team', 'player'];

    public const int MAX_REFERENCES = 1000;

    public const int MAX_SNAPSHOTS = 1000;

    /** @return array<string, array<mixed>> */
    public static function pageRules(): array
    {
        return [...GameCatalogueInput::pageRules(false), 'q' => ['sometimes', 'string', 'max:80', 'regex:/\\S/u'],
            'kind' => ['sometimes', Rule::in(self::KINDS)], 'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'game_id' => ['sometimes', 'uuid'], 'sort' => ['sometimes', Rule::in(['name', 'newest'])]];
    }

    /** @return array<string, array<mixed>> */
    public static function saveRules(bool $creating): array
    {
        return [...($creating ? ['key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'kind' => ['required', Rule::in(self::KINDS)], 'game_id' => ['sometimes', 'nullable', 'uuid']] : ['version' => ['required', 'uuid']]),
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', 'regex:/\\S/u'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:24', 'regex:/\\S/u'],
            'organization_name' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/\\S/u'],
            'source_label' => [$creating ? 'required' : 'sometimes', 'string', 'max:160', 'regex:/\\S/u'],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:1000', 'url:https', self::publicUrl()],
            'observed_at' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d\\TH:i:sP', 'before_or_equal:now'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])], 'confirm_public_data' => ['required', 'accepted']];
    }

    /** @return array<string, array<mixed>> */
    public static function captureRules(): array
    {
        return ['version' => ['required', 'uuid'], 'context_label' => ['required', 'string', 'max:120', 'regex:/\\S/u'],
            'confirm_public_data' => ['required', 'accepted']];
    }

    /** @param array<string, mixed> $input
     * @param  array<string, array<mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function validate(Factory $validator, array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('external_opponents.immutable')));
        }

        return $validator->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [...GameCatalogueInput::messages(), 'confirm_public_data.accepted' => __('external_opponents.public_data'),
            'url' => __('external_opponents.url'), 'date_format' => __('external_opponents.date'),
            'before_or_equal' => __('external_opponents.future')];
    }

    /** Citations are never fetched; reject credentials and obvious private destinations. */
    private static function publicUrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $parts = is_string($value) ? parse_url($value) : false;
            $host = is_array($parts) ? strtolower(trim($parts['host'] ?? '', '[]')) : '';
            $publicIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || $host === ''
                || ! str_contains($host, '.') || $host === 'localhost' || str_ends_with($host, '.localhost')
                || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
                || (filter_var($host, FILTER_VALIDATE_IP) && $publicIp === false)) {
                $fail(__('external_opponents.url'));
            }
        };
    }
}
