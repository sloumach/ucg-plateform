<?php

namespace App\Modules\Identity\Domain\Exceptions;

use App\Contracts\Exceptions\ValidationDomainExceptionContract;
use App\Exceptions\DomainException;

final class InvalidCredentialsException extends DomainException implements ValidationDomainExceptionContract
{
    public function __construct()
    {
        parent::__construct(__('auth.failed'), 'INVALID_CREDENTIALS');
    }

    public function errors(): array
    {
        return [
            'email' => [$this->getMessage()],
        ];
    }
}
