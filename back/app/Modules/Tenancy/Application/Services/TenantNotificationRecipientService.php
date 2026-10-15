<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class TenantNotificationRecipientService implements TenantNotificationRecipientDirectory
{
    public function __construct(private TenantResolver $resolver, private AccountDirectory $accounts, private TenantAuthorization $authorization) {}

    public function email(string $organizationId, int $accountId, string $requestId, array $permissions): ?string
    {
        try {
            $context = $this->resolver->resolve($organizationId, '', $accountId, $requestId);
            foreach ($permissions as $permission) {
                $this->authorization->authorize($context, $permission);
            }

            return $this->accounts->verifiedEmail($accountId);
        } catch (AuthorizationException|ModelNotFoundException) {
            return null;
        } catch (DomainConflictException $exception) {
            if ($exception->errorCode() !== 'ORGANIZATION_INACTIVE') {
                throw $exception;
            }

            return null;
        }
    }
}
