<?php

namespace App\Http\Middleware;

use App\Http\Api\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = RequestId::for($request);
        Context::add(RequestId::ATTRIBUTE, $requestId);
        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $requestId);

        return $response;
    }
}
