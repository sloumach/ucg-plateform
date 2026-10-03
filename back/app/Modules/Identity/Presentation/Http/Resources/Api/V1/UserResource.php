<?php

namespace App\Modules\Identity\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
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
        ];
    }
}
