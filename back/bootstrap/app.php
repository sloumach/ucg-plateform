<?php

use App\Http\Api\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Modules\Tenancy\Presentation\Http\Middleware\RequireTenantConfirmation;
use App\Modules\Tenancy\Presentation\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->statefulApi();
        $middleware->alias(['tenant' => ResolveTenantContext::class, 'tenant.confirmed' => RequireTenantConfirmation::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantContext::class);
        $middleware->appendToPriorityList(ResolveTenantContext::class, RequireTenantConfirmation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn (Throwable $exception, Request $request): ?JsonResponse => app(ApiExceptionRenderer::class)
                ->render($exception, $request),
        );

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
