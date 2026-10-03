<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SystemStatusResource;

class SystemStatusController extends Controller
{
    public function __invoke(): SystemStatusResource
    {
        return new SystemStatusResource([
            'api_version' => 'v1',
            'name' => config('app.name'),
            'status' => 'operational',
        ]);
    }
}
