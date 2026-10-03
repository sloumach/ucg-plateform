<?php

namespace App\Modules\Identity\Domain\Exceptions;

use Exception;

class InvalidCredentialsException extends Exception
{
    public function __construct()
    {
        parent::__construct(__('auth.failed'));
    }
}
