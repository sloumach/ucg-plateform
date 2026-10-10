<?php

namespace App\Modules\Tenancy\Application\Contracts;

use App\Support\Data\DataTransferObject;
use App\Support\Queue\JobContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Server-resolved snapshot, never an authorization proof supplied by the client. */
final readonly class TenantContext extends DataTransferObject
{
    public const string ATTRIBUTE = 'ucg_tenant_context';

    public function __construct(
        public string $organizationId,
        public int $actorUserId,
        public string $slug,
        public string $timezone,
        public string $language,
        public string $requestId,
    ) {
        if (! Str::isUuid($organizationId) || ! Str::isUuid($requestId) || $actorUserId < 1) {
            throw new InvalidArgumentException('A tenant context requires valid organization, actor and request identifiers.');
        }
    }

    /** Resource identifiers must not move an operation outside its resolved tenant. */
    public function assertOrganization(string $organizationId): void
    {
        if (strtolower($organizationId) !== strtolower($this->organizationId)) {
            throw new ModelNotFoundException;
        }
    }

    /** Explicit transport; workers must recheck current authorization/status before business writes. */
    public function toJobContext(): JobContext
    {
        return new JobContext($this->requestId, $this->organizationId);
    }
}
