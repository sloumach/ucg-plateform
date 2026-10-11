<?php

namespace App\Modules\Identity\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Contracts\MfaRepository;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\SecurityEventAction;
use App\Modules\Identity\Domain\Totp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MfaService
{
    private const ENROLLMENT_LIFETIME_SECONDS = 600;

    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private MfaRepository $accounts,
        private MfaRequirementService $requirements,
        private MfaSession $sessions,
        private Totp $totp,
        private Encrypter $encryption,
        private ConnectionInterface $database,
        private SecurityAuditService $audit,
    ) {}

    /** @return array{required: bool, enabled: bool, verified: bool} */
    public function status(User $user, Request $request): array
    {
        return [
            'required' => $this->requirements->required($user),
            'enabled' => $user->hasMfa(),
            'verified' => $this->sessions->verified($user, $request),
        ];
    }

    /** @return array{secret: string, provisioning_uri: string, expires_in: int} */
    public function begin(User $user, string $password, Request $request): array
    {
        $account = $this->accounts->find((int) $user->getKey());
        $this->requirePassword($account, $password);
        if (! $account->hasVerifiedEmail()) {
            throw new DomainConflictException(__('identity.mfa.verify_email'), 'EMAIL_VERIFICATION_REQUIRED');
        }
        if ($account->hasMfa() && ! $this->sessions->verified($account, $request)) {
            throw new AuthorizationException;
        }
        $secret = $this->totp->generateSecret();
        $request->session()->put(MfaSession::ENROLLMENT_KEY, [
            'user_id' => (int) $account->getKey(),
            'secret' => $this->encryption->encryptString($secret),
            'password' => $this->sessions->fingerprint($account),
            'version' => $account->mfa_version,
            'expires_at' => now()->getTimestamp() + self::ENROLLMENT_LIFETIME_SECONDS,
        ]);
        $this->audit->record(SecurityEventAction::MfaEnrollmentStarted, $request, $account);
        $issuer = (string) config('identity.mfa_issuer', 'UCG Platform');

        return [
            'secret' => $secret,
            'provisioning_uri' => 'otpauth://totp/'.rawurlencode($issuer.':'.$account->email).'?'.http_build_query([
                'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => Totp::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986),
            'expires_in' => self::ENROLLMENT_LIFETIME_SECONDS,
        ];
    }

    /** @return list<string> */
    public function confirm(User $user, string $code, Request $request): array
    {
        $pending = $request->session()->get(MfaSession::ENROLLMENT_KEY);
        $result = $this->database->transaction(function () use ($user, $code, $request, $pending): array {
            $account = $this->accounts->lock((int) $user->getKey());
            if (! is_array($pending) || ($pending['user_id'] ?? null) !== (int) $account->getKey()
                || ! is_int($pending['expires_at'] ?? null) || $pending['expires_at'] <= now()->getTimestamp()
                || ($pending['password'] ?? null) !== $this->sessions->fingerprint($account)
                || ($pending['version'] ?? null) !== $account->mfa_version
                || ! is_string($pending['secret'] ?? null) || ! $account->hasVerifiedEmail()) {
                throw new DomainConflictException(__('identity.mfa.enrollment_expired'), 'MFA_ENROLLMENT_EXPIRED');
            }
            if ($account->hasMfa() && ! $this->sessions->verified($account, $request)) {
                throw new AuthorizationException;
            }
            try {
                $secret = $this->encryption->decryptString($pending['secret']);
            } catch (DecryptException) {
                throw new DomainConflictException(__('identity.mfa.enrollment_expired'), 'MFA_ENROLLMENT_EXPIRED');
            }
            $step = $this->totp->matchingStep($secret, $code, now()->getTimestamp(), null);
            if ($step === null) {
                return [null, []];
            }
            $codes = $this->recoveryCodes();
            $account->forceFill([
                'mfa_secret' => $secret, 'mfa_confirmed_at' => now(),
                'mfa_recovery_codes' => $this->hashCodes($codes), 'mfa_version' => (string) Str::uuid(),
                'mfa_last_used_step' => $step, 'remember_token' => Str::random(60),
            ]);
            $this->accounts->save($account);
            $this->accounts->revokeOtherSessions((int) $account->getKey(), $request->session()->getId());
            $this->audit->record(SecurityEventAction::MfaEnabled, $request, $account);

            return [$account, $codes];
        });
        [$account, $codes] = $result;
        if (! $account instanceof User) {
            $this->invalidCode($user, $request);
        }
        $this->sessions->verify($account, $request);

        return $codes;
    }

    public function challenge(User $user, ?string $code, ?string $recoveryCode, Request $request): void
    {
        $account = $this->database->transaction(function () use ($user, $code, $recoveryCode, $request): ?User {
            $account = $this->accounts->lock((int) $user->getKey());
            if (! $this->sessions->current($account, $request)) {
                throw new AuthenticationException;
            }
            if (! $account->hasMfa()) {
                throw new DomainConflictException(__('identity.mfa.enrollment_required'), 'MFA_ENROLLMENT_REQUIRED');
            }
            if ($recoveryCode !== null) {
                $codes = $account->mfa_recovery_codes ?? [];
                $wanted = hash('sha256', strtolower(trim($recoveryCode)));
                $index = null;
                foreach ($codes as $position => $stored) {
                    if (hash_equals($stored, $wanted)) {
                        $index = $position;
                    }
                }
                if ($index === null) {
                    return null;
                }
                unset($codes[$index]);
                $account->forceFill([
                    'mfa_recovery_codes' => array_values($codes),
                    'mfa_version' => (string) Str::uuid(), 'remember_token' => Str::random(60),
                ]);
                $this->accounts->revokeOtherSessions((int) $account->getKey(), $request->session()->getId());
                $action = SecurityEventAction::MfaRecoveryUsed;
            } else {
                $step = $this->totp->matchingStep((string) $account->mfa_secret, $code ?? '', now()->getTimestamp(), $account->mfa_last_used_step);
                if ($step === null) {
                    return null;
                }
                $account->mfa_last_used_step = $step;
                $action = SecurityEventAction::MfaChallengeSucceeded;
            }
            $this->accounts->save($account);
            $this->audit->record($action, $request, $account);

            return $account;
        });
        if ($account === null) {
            $this->invalidCode($user, $request);
        }
        $this->sessions->verify($account, $request);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user, string $password, Request $request): array
    {
        [$account, $codes] = $this->database->transaction(function () use ($user, $password, $request): array {
            $account = $this->accounts->lock((int) $user->getKey());
            $this->requireManagement($account, $password, $request);
            $codes = $this->recoveryCodes();
            $account->forceFill([
                'mfa_recovery_codes' => $this->hashCodes($codes),
                'mfa_version' => (string) Str::uuid(), 'remember_token' => Str::random(60),
            ]);
            $this->accounts->save($account);
            $this->accounts->revokeOtherSessions((int) $account->getKey(), $request->session()->getId());
            $this->audit->record(SecurityEventAction::MfaRecoveryRegenerated, $request, $account);

            return [$account, $codes];
        });
        $this->sessions->verify($account, $request);

        return $codes;
    }

    public function disable(User $user, string $password, Request $request): void
    {
        $this->database->transaction(function () use ($user, $password, $request): void {
            $account = $this->accounts->lock((int) $user->getKey());
            $this->requireManagement($account, $password, $request);
            if ($this->requirements->required($account)) {
                throw new DomainConflictException(__('identity.mfa.cannot_disable'), 'MFA_REQUIRED_FOR_PRIVILEGED_ACCOUNT');
            }
            $account->forceFill([
                'mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_recovery_codes' => null,
                'mfa_version' => (string) Str::uuid(), 'mfa_last_used_step' => null,
                'remember_token' => Str::random(60),
            ]);
            $this->accounts->save($account);
            $this->accounts->revokeOtherSessions((int) $account->getKey(), $request->session()->getId());
            $this->audit->record(SecurityEventAction::MfaDisabled, $request, $account);
        });
        $this->sessions->forget($request);
        $this->sessions->bind($this->accounts->find((int) $user->getKey()), $request);
        $request->session()->regenerate();
    }

    private function requireManagement(User $account, string $password, Request $request): void
    {
        $this->requirePassword($account, $password);
        if (! $this->sessions->verified($account, $request)) {
            throw new AuthorizationException;
        }
    }

    private function requirePassword(User $account, string $password): void
    {
        if (! Hash::check($password, $account->password)) {
            throw ValidationException::withMessages(['password' => [__('identity.sessions.password_invalid')]]);
        }
    }

    private function invalidCode(User $user, Request $request): never
    {
        $this->audit->record(SecurityEventAction::MfaChallengeFailed, $request, $user);
        throw ValidationException::withMessages(['code' => [__('identity.mfa.invalid_code')]]);
    }

    /** @return list<string> */
    private function recoveryCodes(): array
    {
        $codes = [];
        for ($index = 0; $index < self::RECOVERY_CODE_COUNT; $index++) {
            $codes[] = implode('-', str_split(bin2hex(random_bytes(16)), 8));
        }

        return $codes;
    }

    /** @param list<string> $codes
     * @return list<string>
     */
    private function hashCodes(array $codes): array
    {
        return array_map(static fn (string $code): string => hash('sha256', $code), $codes);
    }
}
