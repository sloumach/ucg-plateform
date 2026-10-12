<?php

namespace App\Modules\People\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class PersonImportResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        if (! is_array($this->resource)) {
            throw new LogicException('Import resources require an authorized projection.');
        }

        return $this->resource;
    }
}
