<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Identity\Application\Contracts\ClientAccountProvisioner;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformCatalogueGuard;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\OrganizationDetailsData;
use App\Modules\Tenancy\Application\Validation\ClientOnboardingInput;
use App\Modules\Tenancy\Application\Validation\OrganizationInput;
use App\Modules\Tenancy\Domain\Contracts\ClientActivationDelivery;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class ClientOnboardingService
{
    public function __construct(
        private ClientAccountProvisioner $accounts,
        private PlatformCatalogueGuard $guard,
        private PlatformAccountDirectory $platform,
        private OrganizationRepository $organizations,
        private OrganizationService $service,
        private ClientActivationDelivery $delivery,
        private ConnectionInterface $database,
        private Factory $validation,
    ) {}

    /** @param array<string, mixed> $input */
    public function invite(int $actorId, array $input): ?string
    {
        $token = $this->database->transaction(function () use ($actorId, $input): string {
            $this->guard->lock($actorId);
            $data = $this->validation->make($input, ClientOnboardingInput::rules(), OrganizationInput::messages())->validate();
            $this->requireAvailable((string) $data['email'], (string) $data['slug']);

            return Crypt::encryptString(json_encode([
                'purpose' => 'client-onboarding', 'actor_id' => $actorId,
                'actor_version' => $this->platform->version($actorId),
                'expires_at' => now()->addHour()->getTimestamp(), 'data' => $data,
            ], JSON_THROW_ON_ERROR));
        });
        $url = rtrim((string) config('identity.frontend_url'), '/').'/#client_setup='.rawurlencode($token);

        return $this->delivery->send((string) $input['email'], (string) $input['client_name'], (string) $input['name'], $url);
    }

    public function activate(string $token, string $password, string $requestId): Organization
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['token' => __('platform.client_link_invalid')]);
        }
        if (! is_array($payload) || ($payload['purpose'] ?? null) !== 'client-onboarding'
            || ! is_int($payload['actor_id'] ?? null) || ! is_string($payload['actor_version'] ?? null)
            || ! is_int($payload['expires_at'] ?? null) || $payload['expires_at'] <= now()->getTimestamp()
            || ! is_array($payload['data'] ?? null)) {
            throw ValidationException::withMessages(['token' => __('platform.client_link_invalid')]);
        }

        return $this->database->transaction(function () use ($payload, $password, $requestId): Organization {
            $actorId = $payload['actor_id'];
            $this->guard->lock($actorId);
            if ($payload['actor_version'] !== $this->platform->version($actorId) || $payload['expires_at'] <= now()->getTimestamp()) {
                throw ValidationException::withMessages(['token' => __('platform.client_link_invalid')]);
            }
            $data = $this->validation->make($payload['data'], ClientOnboardingInput::rules(), OrganizationInput::messages())->validate();
            $this->requireAvailable((string) $data['email'], (string) $data['slug']);
            $userId = $this->accounts->createVerified((string) $data['client_name'], (string) $data['email'], $password, $requestId);

            return $this->service->create((string) $data['slug'], $userId,
                new OrganizationDetailsData((string) $data['name'], (string) $data['timezone'], (string) $data['language'],
                    (string) $data['country'], (int) $data['settings']['week_starts_on'], (string) $data['settings']['date_format']),
                new OrganizationAuditData($actorId, (string) $data['reason'], $requestId));
        });
    }

    private function requireAvailable(string $email, string $slug): void
    {
        if ($this->accounts->emailExists($email)) {
            throw ValidationException::withMessages(['email' => __('platform.client_email_taken')]);
        }
        if ($this->organizations->findBySlug($slug) !== null) {
            throw ValidationException::withMessages(['slug' => __('tenancy.errors.slug_taken')]);
        }
    }
}
