<?php

namespace App\Modules\Identity\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Identity\Application\Contracts\PlatformCatalogueGuard;
use App\Modules\Identity\Application\Services\MfaService;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\Request;

/** @mixin User */
class UserResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'can_provision_clients' => app(PlatformCatalogueGuard::class)->allows($this->id),
            'mfa' => app(MfaService::class)->status($this->resource, $request),
        ];
    }
}
