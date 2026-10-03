<?php

namespace App\Contracts\Exceptions;

interface DomainExceptionContract
{
    public function errorCode(): string;
}
