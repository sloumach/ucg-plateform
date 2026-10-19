<?php

namespace App\Modules\Performance\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class MetricCatalogueInput
{
    public const MAX_METRICS = 10000;

    public const MAX_REVISIONS = 1000;

    public const FIELDS = ['mode_id', 'role_id', 'release_id', 'title', 'definition', 'unit', 'aggregation', 'formula', 'zero_denominator', 'measurement_kind', 'availability', 'availability_note', 'status'];

    /** @return array<string,mixed> */
    public static function rules(string $operation): array
    {
        $pages = ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,10']];
        if (in_array($operation, ['show', 'version'], true)) {
            return [];
        }
        if ($operation === 'history') {
            return $pages;
        }
        $filters = ['game_id' => ['sometimes', 'uuid'], 'code' => ['sometimes', 'string', 'max:64', 'regex:/\A[A-Z][A-Z0-9_]{1,63}\z/'], 'status' => ['sometimes', 'in:active,retired'], 'aggregation' => ['sometimes', 'in:sum,mean,min,max,last,ratio,manual'], 'availability' => ['sometimes', 'in:manual,source_required,unavailable']];
        if ($operation === 'index') {
            return [...$pages, ...$filters, 'mode_id' => ['sometimes', 'uuid'], 'role_id' => ['sometimes', 'uuid'], 'release_id' => ['sometimes', 'uuid']];
        }

        return [...($operation === 'store' ? ['game_id' => ['required', 'uuid'], 'code' => ['required', ...array_slice($filters['code'], 1)]] : ['version' => ['required', 'uuid']]),
            'catalogue_version' => ['required', 'uuid'], 'game_catalogue_version' => ['required', 'uuid'], 'confirm_global' => ['required', 'accepted'],
            'mode_id' => ['present', 'nullable', 'uuid'], 'role_id' => ['present', 'nullable', 'uuid'], 'release_id' => ['present', 'nullable', 'uuid'],
            'title' => ['required', 'string', 'max:160', 'regex:/\S/u'], 'definition' => ['required', 'string', 'max:4000', 'regex:/\S/u'], 'unit' => ['required', 'string', 'max:40', 'regex:/\S/u'],
            'aggregation' => ['required', 'in:sum,mean,min,max,last,ratio,manual'], 'formula' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'zero_denominator' => ['required', 'in:unavailable,zero,not_applicable'],
            'aggregation_level' => ['sometimes', 'in:round,game,match,session,period'],
            'ratio_components' => ['sometimes', 'nullable', 'array:numerator,denominator'],
            'ratio_components.numerator' => ['required_with:ratio_components', 'array', 'min:1', 'max:4'],
            'ratio_components.denominator' => ['required_with:ratio_components', 'array', 'min:1', 'max:4'],
            'ratio_components.numerator.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'ratio_components.denominator.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'measurement_kind' => ['required', 'in:objective,subjective'], 'availability' => ['required', 'in:manual,source_required,unavailable'],
            'availability_note' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'status' => ['required', 'in:active,retired'], 'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u']];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function validate(Factory $factory, array $input, string $operation): array
    {
        $rules = self::rules($operation);
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('metric_catalogue.closed')));
        }

        return $factory->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return ['required' => __('metric_catalogue.required'), 'present' => __('metric_catalogue.required'), 'uuid' => __('metric_catalogue.invalid'), 'in' => __('metric_catalogue.invalid'), 'regex' => __('metric_catalogue.invalid'), 'string' => __('metric_catalogue.invalid'), 'accepted' => __('metric_catalogue.confirm'), 'min' => __('metric_catalogue.reason'), 'max' => __('metric_catalogue.limit'), 'between' => __('metric_catalogue.limit')];
    }
}
