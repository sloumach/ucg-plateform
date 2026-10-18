<?php

namespace App\Modules\Competition\Presentation\Http\Requests\Api\V1;

use App\Modules\Competition\Application\Validation\MatchLineupInput;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class MatchLineupRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->operation() === 'index' ? 'viewAny' : 'manage', [MatchRecord::class, $context]) ?? false;
    }

    public function operation(): string
    {
        return $this->route()?->getActionMethod() ?? '';
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return MatchLineupInput::rules($this->operation());
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return MatchLineupInput::messages();
    }

    /** @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('matches.closed'));
            }
        }];
    }
}
