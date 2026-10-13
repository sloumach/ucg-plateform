<?php

namespace App\Modules\Teams\Presentation\Http\Requests\Api\V1;

use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class OrganizationGameReadRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('viewTenant', [GameCatalogueEntry::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return GameCatalogueInput::pageRules(false);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $roots = array_filter(array_keys($this->rules()), fn (string $field): bool => ! str_contains($field, '.'));
            foreach (array_diff(array_keys($this->all()), $roots) as $field) {
                $validator->errors()->add($field, __('game_catalogue.immutable'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return GameCatalogueInput::messages();
    }
}
