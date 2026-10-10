<?php

namespace App\Modules\Tenancy\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Http\Request;

/** @mixin Organization */
final class OrganizationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'slug' => $this->slug,
            'owner_user_id' => $this->owner_user_id, 'status' => $this->status->value,
            'timezone' => $this->timezone, 'language' => $this->language, 'country' => $this->country,
            'settings' => $this->whenLoaded('settings', fn (): array => [
                'week_starts_on' => $this->settings?->week_starts_on,
                'date_format' => $this->settings?->date_format,
            ]),
        ];
    }
}
