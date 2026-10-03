<?php

namespace App\Modules\Identity\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class LoginData extends DataTransferObject
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}
}
