<?php

namespace App\Modules\Competition\Presentation\Http\Requests\Api\V1;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class MatchRealtimeRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('viewAny', [MatchRecord::class, $context]) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }

        return ['socket_id' => ['required', 'string', 'max:100', 'regex:/\A[0-9]+\.[0-9]+\z/'],
            'channel_name' => ['required', 'string', Rule::in(['private-tenants.'.$this->route('tenant').'.matches.'.$this->route('match')])]];
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
