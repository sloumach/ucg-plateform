<?php

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

final class ApiResponseFactory
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array{type: string, message: string}|null  $notification
     */
    public function success(
        Request $request,
        mixed $data = null,
        int $status = 200,
        array $meta = [],
        ?array $notification = null,
    ): JsonResponse {
        $payload = [
            'data' => $data,
            'meta' => $this->meta($request, $meta),
        ];

        if ($notification !== null) {
            $payload['notification'] = $notification;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $meta
     */
    public function error(
        Request $request,
        string $message,
        ApiErrorCode|string $code,
        int $status,
        array $errors = [],
        array $meta = [],
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'code' => $code instanceof ApiErrorCode ? $code->value : $code,
            'errors' => $errors === [] ? new stdClass : $errors,
            'meta' => $this->meta($request, $meta),
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function meta(Request $request, array $meta): array
    {
        return [
            'request_id' => RequestId::for($request),
            ...$meta,
        ];
    }
}
