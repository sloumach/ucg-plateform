<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\OrganizationDetailsData;
use App\Modules\Tenancy\Application\Validation\OrganizationInput;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        $context->assertOrganization((string) $this->route('organization'));

        return $user->can('manage', $context);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            ...OrganizationInput::detailsRules(),
            'id' => ['prohibited'], 'slug' => ['prohibited'], 'owner_user_id' => ['prohibited'],
            'status' => ['prohibited'], 'organization_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return OrganizationInput::messages();
    }

    public function toData(): OrganizationDetailsData
    {
        return new OrganizationDetailsData(
            name: $this->string('name')->toString(),
            timezone: $this->string('timezone')->toString(),
            language: $this->string('language')->toString(),
            country: $this->string('country')->toString(),
            weekStartsOn: $this->integer('settings.week_starts_on'),
            dateFormat: $this->string('settings.date_format')->toString(),
        );
    }
}
