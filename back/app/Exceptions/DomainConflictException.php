<?php

namespace App\Exceptions;

class DomainConflictException extends DomainException
{
    public function __construct(string $message, string $errorCode = 'RESOURCE_CONFLICT')
    {
        parent::__construct($message, $errorCode);
    }
}
