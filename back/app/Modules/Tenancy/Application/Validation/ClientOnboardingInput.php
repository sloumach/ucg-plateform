<?php

namespace App\Modules\Tenancy\Application\Validation;

final class ClientOnboardingInput
{
    /** @return array<string, array<mixed>> */
    public static function rules(): array
    {
        return [
            ...OrganizationInput::detailsRules(),
            'client_name' => ['required', 'string', 'max:255', 'regex:/\\S/u'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'slug' => ['required', 'string', 'max:80', 'regex:/\\A[a-z0-9]+(?:-[a-z0-9]+)*\\z/'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirm' => ['required', 'accepted'],
            'owner_user_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'organization_id' => ['prohibited'], 'actor_user_id' => ['prohibited'],
            'roles' => ['prohibited'], 'role' => ['prohibited'], 'status' => ['prohibited'],
            'email_verified_at' => ['prohibited'], 'password' => ['prohibited'],
        ];
    }
}
