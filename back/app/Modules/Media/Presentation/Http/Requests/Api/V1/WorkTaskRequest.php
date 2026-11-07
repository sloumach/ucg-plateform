<?php

namespace App\Modules\Media\Presentation\Http\Requests\Api\V1;

use App\Modules\Media\Application\Validation\CommunicationInput;
use App\Modules\Media\Application\Validation\WorkTaskInput;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class WorkTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can(in_array($this->operation(), ['store', 'update', 'people'], true) ? 'manage' : 'viewAny', [WorkTask::class, $context]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return WorkTaskInput::rules($this->operation());
    }

    public function operation(): string
    {
        return $this->route()?->getActionMethod() ?? '';
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
