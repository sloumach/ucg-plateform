<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\LoginData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\AuthenticationService;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly Gate $gate,
    ) {}

    public function login(LoginRequest $request): UserResource
    {
        $user = $this->authentication->login(LoginData::fromRequest($request), $request);

        return UserResource::make($user);
    }

    public function current(Request $request): UserResource
    {
        $user = $this->authenticatedUser($request);
        $this->gate->authorize('view', $user);

        return UserResource::make($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $this->gate->authorize('view', $user);
        $this->authentication->logout($request);

        return response()->json([
            'message' => 'Déconnexion effectuée.',
        ]);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
