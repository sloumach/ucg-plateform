<?php

namespace App\Modules\Teams\Presentation\Http\Requests\Api\V1;

use App\Modules\Teams\Application\Validation\TeamLineupInput as Input;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class TeamLineupRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->isMethod('GET') ? 'viewAny' : 'manage', [TeamLineup::class, $context]) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        $action = $this->input('action');

        return $this->isMethod('GET') ? Input::readRules() : ($this->isMethod('POST') ? Input::createRules() : Input::actionRules(is_string($action) ? $action : ''));
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
