<?php

namespace App\Modules\People\Application\Validation;

use App\Modules\People\Domain\PersonStatus;
use Illuminate\Validation\Rule;

final class PersonListInput
{
    public const array SPORTS_FILTERS = ['q', 'status', 'language', 'game_key', 'platform_key'];

    /** @return array<string, array<mixed>> */
    public static function rules(): array
    {
        return [
            'archive' => ['sometimes', 'required', Rule::in(['exclude', 'only'])],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'view' => ['sometimes', 'required', Rule::in(['general', 'sports', 'administrative'])],
            'sort' => ['sometimes', 'required', Rule::in(['id', 'pseudonym', 'status'])],
            'direction' => ['sometimes', 'required', Rule::in(['asc', 'desc'])],
            'q' => ['sometimes', 'required', 'string', 'min:2', 'max:80', 'regex:/\S/u'],
            'status' => ['sometimes', 'required', Rule::in(array_column(PersonStatus::cases(), 'value'))],
            'language' => ['sometimes', 'required', Rule::in(config('tenancy.languages', ['fr']))],
            'game_key' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/D'],
            'platform_key' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_-]*$/D'],
            'account_linked' => ['sometimes', 'required', Rule::in(['linked', 'unlinked'])],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [...PersonInput::messages(), 'min' => __('people.list_min'), 'max' => __('people.list_max')];
    }
}
