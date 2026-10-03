<?php

namespace App\Exceptions;

use App\Contracts\Exceptions\DomainExceptionContract;
use RuntimeException;

abstract class DomainException extends RuntimeException implements DomainExceptionContract
{
    public function __construct(
        string $message,
        private readonly string $domainErrorCode,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->domainErrorCode;
    }
}
