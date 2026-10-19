<?php

namespace App\Modules\Performance\Application\Validation;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class StatisticalProfileInput
{
    public const MAX_PERIOD_DAYS = 366;

    /** @return array<string,list<string>> */
    public static function rules(string $action): array
    {
        if ($action === 'people') {
            return ['page' => ['sometimes', 'integer', 'between:1,100000']];
        }

        return ['people' => ['required', 'array', 'min:1', 'max:2'], 'people.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'metric_id' => ['required', 'uuid'], 'metric_version' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source_kind' => ['sometimes', 'string', 'in:official,scrim,training,assessment'],
            'source_label' => ['sometimes', 'string', 'max:500'],
            'season' => ['sometimes', 'string', 'max:80'], 'region' => ['sometimes', 'string', 'max:80'],
            'map' => ['sometimes', 'string', 'max:160'], 'opponent' => ['sometimes', 'string', 'max:160'],
            'mode_id' => ['sometimes', 'uuid'], 'role_id' => ['sometimes', 'uuid'], 'release_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'between:1,100000'], 'timeline_page' => ['sometimes', 'integer', 'between:1,100000']];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function validate(Factory $validator, array $input, string $action): array
    {
        if (array_diff(array_keys($input), array_keys(self::rules($action))) !== []) {
            throw ValidationException::withMessages(['profile' => __('observations.closed')]);
        }
        $values = $validator->make($input, self::rules($action))->validate();
        if ($action !== 'people' && CarbonImmutable::parse($values['from'])->diffInDays(CarbonImmutable::parse($values['to'])) >= self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages(['to' => __('statistical_profiles.period')]);
        }

        return $values;
    }
}
