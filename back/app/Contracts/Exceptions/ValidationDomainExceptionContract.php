<?php

namespace App\Contracts\Exceptions;

interface ValidationDomainExceptionContract extends DomainExceptionContract
{
    /** @return array<string, list<string>> */
    public function errors(): array;
}
