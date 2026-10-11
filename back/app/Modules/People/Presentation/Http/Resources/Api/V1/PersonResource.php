<?php

namespace App\Modules\People\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\People\Application\Contracts\PersonRecord;
use Illuminate\Http\Request;

/** @mixin PersonRecord */
final class PersonResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->fields;
    }
}
