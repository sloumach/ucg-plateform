<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\Request;

final class MfaSession
{
    public const PROOF_KEY = 'identity.mfa.proof';

    public const ENROLLMENT_KEY = 'identity.mfa.enrollment';

    public const ACCOUNT_KEY = 'identity.account';

    public function bind(User $user, Request $request): void
    {
        $request->session()->put(self::ACCOUNT_KEY, [
            'user_id' => (int) $user->getKey(), 'version' => $user->mfa_version,
            'password' => $this->fingerprint($user),
        ]);
    }

    public function current(User $user, Request $request): bool
    {
        if (! $request->hasSession()) {
            return true;
        }
        $account = $request->session()->get(self::ACCOUNT_KEY);
        if ($account === null) {
            return $user->mfa_version === null;
        }

        return is_array($account) && ($account['user_id'] ?? null) === (int) $user->getKey()
            && ($account['version'] ?? null) === $user->mfa_version
            && ($account['password'] ?? null) === $this->fingerprint($user);
    }

    public function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->password, (string) config('app.key'));
    }

    public function verified(User $user, Request $request): bool
    {
        if (! $user->hasMfa() || ! $request->hasSession()) {
            return false;
        }
        $proof = $request->session()->get(self::PROOF_KEY);

        return is_array($proof) && ($proof['user_id'] ?? null) === (int) $user->getKey()
            && ($proof['version'] ?? null) === $user->mfa_version
            && is_string($proof['password'] ?? null)
            && hash_equals($this->fingerprint($user), $proof['password']);
    }

    public function verify(User $user, Request $request): void
    {
        $request->session()->regenerate(true);
        $this->bind($user, $request);
        $request->session()->put(self::PROOF_KEY, [
            'user_id' => (int) $user->getKey(), 'version' => $user->mfa_version,
            'password' => $this->fingerprint($user),
        ]);
        $request->session()->forget(self::ENROLLMENT_KEY);
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget([self::PROOF_KEY, self::ENROLLMENT_KEY, self::ACCOUNT_KEY]);
        }
    }
}
