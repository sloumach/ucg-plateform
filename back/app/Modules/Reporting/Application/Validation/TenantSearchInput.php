<?php

namespace App\Modules\Reporting\Application\Validation;

use Illuminate\Validation\Rule;

final class TenantSearchInput
{
    public const SOURCES = ['people', 'teams', 'matches', 'competitions', 'contract_documents', 'competition_documents'];

    /** @return array<string,array<mixed>> */
    public static function rules(): array
    {
        return ['q' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[^\p{C}]+$/u'],
            'type' => ['required', Rule::in(self::SOURCES)], 'mode' => ['sometimes', 'required', Rule::in(['search', 'suggest'])],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:20'], 'cursor' => ['sometimes', 'required', 'string', 'max:4096']];
    }
}
