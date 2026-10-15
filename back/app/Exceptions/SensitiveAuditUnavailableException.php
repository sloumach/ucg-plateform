<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class SensitiveAuditUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(30, __('audit.access_unavailable'));
    }
}
