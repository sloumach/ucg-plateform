<?php

namespace Tests\Unit\Http\Api;

use App\Http\Api\RequestId;
use Illuminate\Http\Request;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_returns_the_existing_request_identifier(): void
    {
        $request = Request::create('/api/v1/system/status');
        $request->attributes->set(RequestId::ATTRIBUTE, 'existing-request-id');

        $requestId = RequestId::for($request);

        $this->assertSame('existing-request-id', $requestId);
    }

    public function test_stores_a_generated_identifier_when_the_request_has_none(): void
    {
        $request = Request::create('/api/v1/system/status');

        $requestId = RequestId::for($request);

        $this->assertNotSame('', $requestId);
        $this->assertSame($requestId, $request->attributes->get(RequestId::ATTRIBUTE));
    }
}
