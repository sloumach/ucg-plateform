<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Data\SupportAccessData;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use App\Modules\Tenancy\Domain\SupportAction;
use Illuminate\Validation\Rule;

final class OpenSupportAccessRequest extends SupportAccessRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [...parent::rules(), 'reason' => ['required', 'string', 'min:10', 'max:500'],
            'duration_minutes' => ['required', 'integer', 'between:1,'.OrganizationSupportAccess::MAX_DURATION_MINUTES],
            'actions' => ['required', 'array', 'list', 'min:1', 'max:2'],
            'actions.*' => ['required', 'string', 'distinct', Rule::enum(SupportAction::class)],
            'confirm' => ['required', 'accepted']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'reason.*' => __('platform.reason'), 'duration_minutes.*' => __('platform.duration'),
            'actions.*' => __('platform.actions'), 'actions.*.*' => __('platform.actions'), 'confirm.*' => __('platform.confirm')];
    }

    public function toData(): SupportAccessData
    {
        return new SupportAccessData($this->string('reason')->toString(), $this->integer('duration_minutes'), array_values($this->validated('actions')));
    }
}
