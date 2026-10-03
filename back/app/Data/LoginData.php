<?php

namespace App\Data;

use App\Http\Requests\Api\V1\LoginRequest;

final readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}

    public static function fromRequest(LoginRequest $request): self
    {
        return new self(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
        );
    }
}
