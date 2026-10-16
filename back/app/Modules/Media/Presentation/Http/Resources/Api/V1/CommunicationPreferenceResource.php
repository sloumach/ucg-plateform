<?php

namespace App\Modules\Media\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

final class CommunicationPreferenceResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['announcements_enabled' => (bool) $this->resource['announcements_enabled'],
            'comments_enabled' => (bool) $this->resource['comments_enabled'], 'version' => $this->resource['version']];
    }
}
