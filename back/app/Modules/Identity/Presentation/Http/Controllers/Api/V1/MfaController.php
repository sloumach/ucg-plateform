<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\MfaService;
use App\Modules\Identity\Domain\Contracts\MfaRepository;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\Api\V1\MfaCodeRequest;
use App\Modules\Identity\Presentation\Http\Requests\Api\V1\MfaEnrollmentRequest;
use App\Modules\Identity\Presentation\Http\Requests\Api\V1\MfaManagementRequest;
use App\Modules\Identity\Presentation\Http\Resources\Api\V1\MfaResource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;

final class MfaController extends Controller
{
    public function __construct(private readonly MfaService $mfa, private readonly MfaRepository $accounts, private readonly Gate $gate) {}

    public function show(Request $request): MfaResource
    {
        return MfaResource::make($this->mfa->status($this->account($request), $request));
    }

    public function begin(MfaEnrollmentRequest $request): MfaResource
    {
        return $this->result($this->mfa->begin($this->account($request), $request->string('password')->toString(), $request), 'enrollment_started');
    }

    public function confirm(MfaCodeRequest $request): MfaResource
    {
        return $this->result(['recovery_codes' => $this->mfa->confirm($this->account($request), $request->string('code')->toString(), $request)], 'enabled');
    }

    public function challenge(MfaCodeRequest $request): MfaResource
    {
        $account = $this->account($request);
        $this->mfa->challenge($account, $request->has('code') ? $request->string('code')->toString() : null,
            $request->has('recovery_code') ? $request->string('recovery_code')->toString() : null, $request);

        return $this->result($this->mfa->status($this->accounts->find((int) $account->getKey()), $request), 'verified');
    }

    public function regenerate(MfaManagementRequest $request): MfaResource
    {
        return $this->result(['recovery_codes' => $this->mfa->regenerateRecoveryCodes($this->account($request), $request->string('password')->toString(), $request)], 'recovery_regenerated');
    }

    public function disable(MfaManagementRequest $request): MfaResource
    {
        $account = $this->account($request);
        $this->mfa->disable($account, $request->string('password')->toString(), $request);

        return $this->result($this->mfa->status($this->accounts->find((int) $account->getKey()), $request), 'disabled');
    }

    private function account(Request $request): User
    {
        abort_unless($request->hasSession(), 401);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->gate->authorize('view', $user);

        return $user;
    }

    /** @param array<string, mixed> $data */
    private function result(array $data, string $message): MfaResource
    {
        return MfaResource::make($data)->additional(['notification' => [
            'type' => 'success', 'message' => __('identity.mfa.notifications.'.$message),
        ]]);
    }
}
