<?php

namespace App\Modules\Scheduling\Presentation\Http\Middleware;

use App\Exceptions\DomainException;
use App\Http\Api\ApiExceptionRenderer;
use App\Http\Api\ApiResponseFactory;
use App\Http\Api\RequestId;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final readonly class ProtectCalendarSubscription
{
    public function __construct(private RateLimiter $limiter, private ApiResponseFactory $responses, private ApiExceptionRenderer $exceptions, private LoggerInterface $logger) {}

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $query = $request->query->all();
        $token = $query['token'] ?? null;
        $closed = array_diff(array_keys($query), ['token']) === [];
        // Remove credentials from the request URL before downstream handling and diagnostics.
        $request->query->remove('token');
        $request->server->set('QUERY_STRING', '');
        $request->server->set('REQUEST_URI', $request->getPathInfo());
        $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(),
            $request->cookies->all(), $request->files->all(), $request->server->all(), $request->getContent());
        $request->attributes->set('calendar_subscription_token', $token);
        try {
            if (! $closed || ! is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
                throw new ModelNotFoundException;
            }
            $keys = ['calendar-ics:ip:'.hash('sha256', (string) $request->ip()) => 120,
                'calendar-ics:feed:'.hash('sha256', $request->path().'|'.$token) => 12];
            $response = null;
            foreach ($keys as $key => $maximum) {
                if ($this->limiter->tooManyAttempts($key, $maximum)) {
                    $response = $this->responses->error($request, __('api.errors.rate_limit_exceeded'), 'RATE_LIMIT_EXCEEDED', 429)
                        ->header('Retry-After', (string) $this->limiter->availableIn($key));
                    break;
                }
                $this->limiter->hit($key, 60);
            }
            $response ??= $next($request);
        } catch (ModelNotFoundException|NotFoundHttpException|AuthorizationException|ValidationException) {
            $response = $this->responses->error($request, __('api.errors.resource_not_found'), 'RESOURCE_NOT_FOUND', 404);
        } catch (DomainException $exception) {
            $response = $this->exceptions->render($exception, $request) ?? new Response('', 503);
        } catch (Throwable $exception) {
            $this->logger->error('Calendar subscription unavailable', ['exception_class' => $exception::class, 'request_id' => RequestId::for($request)]);
            $response = $this->responses->error($request, __('calendar.feed_unavailable'), 'CALENDAR_FEED_UNAVAILABLE', 503);
        } finally {
            $request->attributes->remove('calendar_subscription_token');
        }
        // Laravel's routing pipeline can render exceptions before returning to this middleware.
        $unavailableOwner = $response->getStatusCode() === 409
            && (! $response instanceof JsonResponse || ($response->getData(true)['code'] ?? null) !== 'CALENDAR_FEED_LIMIT_EXCEEDED');
        if ($unavailableOwner || in_array($response->getStatusCode(), [401, 403, 404, 422], true)) {
            $response = $this->responses->error($request, __('api.errors.resource_not_found'), 'RESOURCE_NOT_FOUND', 404);
        } elseif ($response->getStatusCode() >= 500) {
            $response = $this->responses->error($request, __('calendar.feed_unavailable'), 'CALENDAR_FEED_UNAVAILABLE', 503);
        }
        $response->headers->add(['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);

        return $response;
    }
}
