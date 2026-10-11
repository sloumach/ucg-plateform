<?php

namespace Tests\Fixtures;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

trait AuthenticatesWithMfa
{
    /** Establishes the completed authentication prerequisite of existing business tests. */
    protected function actingAsWithMfa(User $user, string $guard = 'web'): static
    {
        Auth::forgetGuards();
        if (! $user->hasMfa()) {
            $user->forceFill([
                'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'mfa_confirmed_at' => now(),
                'mfa_version' => (string) Str::uuid(), 'mfa_recovery_codes' => [],
            ])->save();
            $user->refresh();
        }
        $proof = [
            'user_id' => (int) $user->getKey(), 'version' => $user->mfa_version,
            'password' => app(MfaSession::class)->fingerprint($user),
        ];

        return $this->withHeaders([
            'Origin' => $this->defaultHeaders['Origin'] ?? 'http://localhost:5173',
            'Referer' => $this->defaultHeaders['Referer'] ?? 'http://localhost:5173/',
        ])->actingAs($user, $guard)->withSession([
            MfaSession::PROOF_KEY => $proof, MfaSession::ACCOUNT_KEY => $proof,
        ]);
    }
}
