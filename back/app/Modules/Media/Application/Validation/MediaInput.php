<?php

namespace App\Modules\Media\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MediaInput
{
    public const array KINDS = ['stream', 'vod', 'clip'];

    public const array STATUSES = ['scheduled', 'live', 'ended', 'unavailable'];

    public const array VISIBILITIES = ['private', 'organization', 'public'];

    /** @return array<string, array<mixed>> */
    public static function pageRules(bool $references = false): array
    {
        $page = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];

        return $references ? $page : [...$page, 'q' => ['sometimes', 'string', 'max:80', 'regex:/\S/u'],
            'kind' => ['sometimes', Rule::in(self::KINDS)], 'status' => ['sometimes', Rule::in(self::STATUSES)],
            'team_id' => ['sometimes', 'uuid'], 'archived' => ['sometimes', 'boolean']];
    }

    /** @return array<string, array<mixed>> */
    public static function saveRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [...($creating ? [] : ['version' => ['required', 'uuid']]),
            'title' => [$required, 'required', 'string', 'max:160', 'regex:/\S/u'],
            'team_id' => [$required, 'required', 'uuid'], 'kind' => [$required, 'required', Rule::in(self::KINDS)],
            'provider' => [$required, 'required', Rule::in(['twitch', 'youtube'])],
            'url' => [$required, 'required', 'string', 'max:500'],
            'status' => ['sometimes', 'required', Rule::in(self::STATUSES)],
            'status_observed_at' => ['sometimes', 'required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'is_scrim' => ['sometimes', 'boolean'], 'visibility' => ['sometimes', 'required', Rule::in(self::VISIBILITIES)],
            'publish_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            'confirm_sharing' => ['sometimes', 'accepted'], 'archive' => ['sometimes', 'accepted']];
    }

    /** @param array<string, mixed> $input
     * @param  array<string, array<mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function validate(Factory $validator, array $input, array $rules): array
    {
        if (($unknown = array_diff(array_keys($input), array_keys($rules))) !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('media.closed')));
        }

        return $validator->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['required' => __('media.required'), 'string' => __('media.string'), 'max' => __('media.max'),
            'in' => __('media.choice'), 'regex' => __('media.format'), 'uuid' => __('media.uuid'),
            'integer' => __('media.integer'), 'min' => __('media.min'), 'boolean' => __('media.boolean'),
            'accepted' => __('media.confirm'), 'date_format' => __('media.date'), 'before_or_equal' => __('media.future')];
    }
}
