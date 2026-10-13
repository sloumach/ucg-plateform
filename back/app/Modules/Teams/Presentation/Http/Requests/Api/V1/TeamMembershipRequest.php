<?php

namespace App\Modules\Teams\Presentation\Http\Requests\Api\V1;

use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Application\Validation\TeamMembershipInput as Input;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class TeamMembershipRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->isMethod('GET') ? 'viewAny' : 'manage', [TeamMembership::class, $context]) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return str_ends_with($this->route()?->getName() ?? '', '.movements') ? GameCatalogueInput::pageRules(false) : Input::readRules();
        }

        return $this->isMethod('POST') ? Input::createRules() : Input::actionRules();
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
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('team_memberships.immutable'));
            }
        }];
    }
}
