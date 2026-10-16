<?php

namespace App\Modules\Media\Presentation\Http\Requests\Api\V1;

use App\Modules\Media\Application\Validation\CommunicationInput;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CommunicationRequest extends FormRequest
{
    public function operation(): string
    {
        return $this->route()?->getActionMethod() ?? '';
    }

    public function authorize(TenantContext $context): bool
    {
        $ability = match ($this->operation()) {
            'store', 'update', 'targets' => 'manage', 'comment' => 'comment', default => 'viewAny'
        };

        return $this->user()?->can($ability, [Announcement::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return CommunicationInput::rules($this->operation());
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('communication.closed'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return CommunicationInput::messages();
    }
}
