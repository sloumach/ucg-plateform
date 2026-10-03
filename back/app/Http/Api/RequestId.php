<?php

namespace App\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class RequestId
{
    public const string ATTRIBUTE = 'request_id';

    public const string HEADER = 'X-Request-ID';

    public static function for(Request $request): string
    {
        $requestId = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        $requestId = (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }

    private function __construct() {}
}
