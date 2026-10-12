<?php

namespace App\Modules\People\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

final class PersonLifecycleResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        if (! is_array($this->resource)) {
            throw new \LogicException('Lifecycle resources require a projected record.');
        }

        return $this->resource;
    }
}
