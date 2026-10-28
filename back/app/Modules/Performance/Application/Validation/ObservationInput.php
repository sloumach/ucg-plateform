<?php

namespace App\Modules\Performance\Application\Validation;

use App\Modules\Performance\Domain\ObservationValueRules;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class ObservationInput
{
    public const MAX_REVISIONS = 1000;

    public const REVISION_FIELDS = ObservationValueRules::FIELDS;

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
        if ($operation === 'index') {
            return [...$pages, 'person_id' => ['sometimes', 'uuid'], 'metric_id' => ['sometimes', 'uuid'], 'source_kind' => ['sometimes', 'in:official,scrim,training,assessment'], 'status' => ['sometimes', 'in:active,voided'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']];
        }
        if ($operation === 'choices') {
            return [...$pages, 'kind' => ['required', 'in:people,matches,parts'], 'game_id' => ['required_if:kind,matches', 'uuid'], 'match_id' => ['required_if:kind,parts', 'uuid']];
        }
        $revision = ['availability' => ['required', 'in:available,unavailable'], 'value' => ['present', 'nullable', 'string', 'regex:/\A-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/'], 'missing_reason' => ['present', 'nullable', 'string', 'max:1000', 'regex:/\S/u'], 'notes' => ['present', 'nullable', 'string', 'max:4000', 'regex:/\S/u'], 'status' => ['required', 'in:active,voided'], 'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u']];
        if ($operation === 'update') {
            return ['version' => ['required', 'uuid'], ...$revision];
        }

        return [...$revision, 'metric_id' => ['required', 'uuid'], 'metric_version' => ['required', 'uuid'], 'person_id' => ['required', 'uuid'],
            'mode_id' => ['present', 'nullable', 'uuid'], 'role_id' => ['present', 'nullable', 'uuid'], 'release_id' => ['present', 'nullable', 'uuid'],
            'match_id' => ['present', 'nullable', 'uuid'], 'match_game_id' => ['present', 'nullable', 'uuid'], 'source_kind' => ['required', 'in:official,scrim,training,assessment'],
            'source_label' => ['required', 'string', 'max:500', 'regex:/\S/u'], 'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'observed_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_end'], 'sample_size' => ['required', 'integer', 'between:1,1000000'],
            'region' => ['present', 'nullable', 'string', 'max:80', 'regex:/\S/u'], 'season' => ['present', 'nullable', 'string', 'max:80', 'regex:/\S/u'], 'map' => ['present', 'nullable', 'string', 'max:160', 'regex:/\S/u'],
            'session_label' => ['present', 'nullable', 'string', 'max:160', 'regex:/\S/u'], 'opponent' => ['present', 'nullable', 'string', 'max:160', 'regex:/\S/u'], 'round_number' => ['present', 'nullable', 'integer', 'between:1,10000']];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function validate(Factory $factory, array $input, string $operation): array
    {
        $rules = self::rules($operation);
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('observations.closed')));
        }

        return $factory->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return ['required' => __('observations.required'), 'required_if' => __('observations.required'), 'present' => __('observations.required'), 'uuid' => __('observations.invalid'), 'in' => __('observations.invalid'), 'integer' => __('observations.invalid'), 'string' => __('observations.invalid'), 'regex' => __('observations.invalid'), 'max' => __('observations.limit'), 'min' => __('observations.reason'), 'between' => __('observations.limit'), 'date_format' => __('observations.period'), 'after_or_equal' => __('observations.period')];
    }
}
