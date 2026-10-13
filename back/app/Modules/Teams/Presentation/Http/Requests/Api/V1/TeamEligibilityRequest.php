<?php

namespace App\Modules\Teams\Presentation\Http\Requests\Api\V1;

use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Application\Validation\TeamEligibilityInput as Input;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class TeamEligibilityRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->isMethod('GET') ? 'viewAny' : 'manage', [TeamEligibilityRuleset::class, $context]) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'index' => Input::readRules(),'store' => Input::createRules(),'archive' => Input::archiveRules(),
            'bind' => Input::bindingRules(),'evaluate' => Input::evaluateRules(),default => GameCatalogueInput::pageRules(false),
        };
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return Input::messages();
    }

    /** @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, __('team_lineups.server_fields'));
            }
        }];
    }
}
