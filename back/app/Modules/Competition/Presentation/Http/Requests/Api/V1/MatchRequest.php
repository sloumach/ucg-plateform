<?php

namespace App\Modules\Competition\Presentation\Http\Requests\Api\V1;

use App\Modules\Competition\Application\Validation\MatchInput;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class MatchRequest extends FormRequest
{
    public function operation(): string
    {
        return $this->route()?->getActionMethod() ?? '';
    }

    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can(in_array($this->operation(), ['store', 'update', 'transition', 'references'], true) ? 'manage' : 'viewAny', [MatchRecord::class, $context]) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return MatchInput::rules($this->operation());
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return MatchInput::messages();
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
