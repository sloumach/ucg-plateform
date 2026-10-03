<?php

namespace App\Http\Api;

enum ApiErrorCode: string
{
    case AuthenticationRequired = 'AUTHENTICATION_REQUIRED';
    case AuthorizationDenied = 'AUTHORIZATION_DENIED';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case ResourceConflict = 'RESOURCE_CONFLICT';
    case ValidationFailed = 'VALIDATION_FAILED';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case CsrfTokenMismatch = 'CSRF_TOKEN_MISMATCH';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case HttpError = 'HTTP_ERROR';
    case InternalError = 'INTERNAL_ERROR';
}
