<?php

namespace App\Http\Api;

use App\Contracts\Exceptions\DomainExceptionContract;
use App\Contracts\Exceptions\ValidationDomainExceptionContract;
use App\Exceptions\DomainConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final readonly class ApiExceptionRenderer
{
    public function __construct(private ApiResponseFactory $responses) {}

    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException => $this->responses->error(
                request: $request,
                message: 'Les données transmises sont invalides.',
                code: ApiErrorCode::ValidationFailed,
                status: 422,
                errors: $exception->errors(),
            ),
            $exception instanceof AuthenticationException => $this->responses->error(
                request: $request,
                message: 'Authentification requise.',
                code: ApiErrorCode::AuthenticationRequired,
                status: 401,
            ),
            $exception instanceof AuthorizationException => $this->responses->error(
                request: $request,
                message: 'Vous n’êtes pas autorisé à effectuer cette action.',
                code: ApiErrorCode::AuthorizationDenied,
                status: 403,
            ),
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => $this->responses->error(
                request: $request,
                message: 'La ressource demandée est introuvable.',
                code: ApiErrorCode::ResourceNotFound,
                status: 404,
            ),
            $exception instanceof DomainConflictException => $this->responses->error(
                request: $request,
                message: $exception->getMessage(),
                code: $exception->errorCode(),
                status: 409,
            ),
            $exception instanceof ValidationDomainExceptionContract => $this->responses->error(
                request: $request,
                message: $exception->getMessage(),
                code: $exception->errorCode(),
                status: 422,
                errors: $exception->errors(),
            ),
            $exception instanceof DomainExceptionContract => $this->responses->error(
                request: $request,
                message: $exception->getMessage(),
                code: $exception->errorCode(),
                status: 422,
            ),
            $exception instanceof ThrottleRequestsException => $this->responses->error(
                request: $request,
                message: 'Trop de tentatives. Veuillez réessayer plus tard.',
                code: ApiErrorCode::RateLimitExceeded,
                status: 429,
            ),
            $exception instanceof TokenMismatchException => $this->responses->error(
                request: $request,
                message: 'La session a expiré. Veuillez réessayer.',
                code: ApiErrorCode::CsrfTokenMismatch,
                status: 419,
            ),
            $exception instanceof HttpExceptionInterface => $this->renderHttpException($exception, $request),
            default => $this->responses->error(
                request: $request,
                message: 'Une erreur interne est survenue.',
                code: ApiErrorCode::InternalError,
                status: 500,
            ),
        };
    }

    private function renderHttpException(HttpExceptionInterface $exception, Request $request): JsonResponse
    {
        $status = $exception->getStatusCode();

        return match ($status) {
            401 => $this->responses->error(
                $request,
                'Authentification requise.',
                ApiErrorCode::AuthenticationRequired,
                401,
            ),
            403 => $this->responses->error(
                $request,
                'Vous n’êtes pas autorisé à effectuer cette action.',
                ApiErrorCode::AuthorizationDenied,
                403,
            ),
            404 => $this->responses->error(
                $request,
                'La ressource demandée est introuvable.',
                ApiErrorCode::ResourceNotFound,
                404,
            ),
            405 => $this->responses->error(
                $request,
                'Cette méthode HTTP n’est pas autorisée.',
                ApiErrorCode::MethodNotAllowed,
                405,
            ),
            419 => $this->responses->error(
                $request,
                'La session a expiré. Veuillez réessayer.',
                ApiErrorCode::CsrfTokenMismatch,
                419,
            ),
            429 => $this->responses->error(
                $request,
                'Trop de tentatives. Veuillez réessayer plus tard.',
                ApiErrorCode::RateLimitExceeded,
                429,
            ),
            default => $this->responses->error(
                $request,
                'La requête ne peut pas être traitée.',
                ApiErrorCode::HttpError,
                $status,
            ),
        };
    }
}
