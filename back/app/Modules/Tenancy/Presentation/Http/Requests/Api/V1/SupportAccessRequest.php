<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Http\Api\RequestId;
use App\Modules\Tenancy\Application\Data\SupportAuditData;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use Illuminate\Foundation\Http\FormRequest;

class SupportAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('operate', OrganizationSupportAccess::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'between:1,100000'],
            'actor_user_id' => ['prohibited'], 'organization_id' => ['prohibited'], 'starts_at' => ['prohibited'], 'expires_at' => ['prohibited'],
            'status' => ['prohibited'], 'role' => ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['*.prohibited' => __('platform.reserved')];
    }

    public function auditData(): SupportAuditData
    {
        return new SupportAuditData((int) $this->user()?->getAuthIdentifier(), RequestId::for($this), $this->ip());
    }
}
