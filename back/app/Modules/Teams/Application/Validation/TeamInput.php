<?php

namespace App\Modules\Teams\Application\Validation;

use Illuminate\Validation\Rule;

final class TeamInput
{
    public const array KINDS = ['first', 'academy', 'reserve', 'event', 'custom'];

    public const array STATUSES = ['draft', 'active', 'archived'];

    public const int MAX_TEAMS = 1000;

    /** @return array<string, array<mixed>> */
    public static function pageRules(): array
    {
        return [...GameCatalogueInput::pageRules(false), 'q' => ['sometimes', 'string', 'max:80', 'regex:/\\S/u'],
            'game_id' => ['sometimes', 'uuid'], 'kind' => ['sometimes', Rule::in(self::KINDS)], 'status' => ['sometimes', Rule::in(self::STATUSES)],
            'season' => ['sometimes', 'string', 'max:64', 'regex:/\\S/u'], 'sort' => ['sometimes', Rule::in(['name', 'newest'])]];
    }

    /** @return array<string, array<mixed>> */
    public static function saveRules(bool $creating): array
    {
        return [...($creating ? ['game_id' => ['required', 'uuid'], 'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'season' => ['required', 'string', 'max:64', 'regex:/\\S/u']] : ['version' => ['required', 'uuid']]),
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', 'regex:/\\S/u'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in(self::KINDS)],
            'custom_category' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/\\S/u'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/\\S/u'],
            'public_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'status' => ['sometimes', Rule::in(self::STATUSES)]];
    }
}
