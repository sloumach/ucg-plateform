<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Identity\Application\Contracts\PlatformCatalogueGuard;
use App\Modules\Tenancy\Application\Validation\ClientOnboardingInput;
use App\Modules\Tenancy\Application\Validation\OrganizationInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ClientInvitationRequest extends FormRequest
{
    public function authorize(PlatformCatalogueGuard $guard): bool
    {
        return $this->user() !== null && $guard->allows((int) $this->user()->getAuthIdentifier());
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ClientOnboardingInput::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return OrganizationInput::messages();
    }
}
