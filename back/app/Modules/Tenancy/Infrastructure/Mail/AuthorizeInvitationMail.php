<?php

namespace App\Modules\Tenancy\Infrastructure\Mail;

use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\SendQueuedMailable;

final readonly class AuthorizeInvitationMail
{
    public function __construct(private TenantExecutionScope $scope) {}

    /** @param Closure(SendQueuedMailable): mixed $next */
    public function handle(SendQueuedMailable $job, Closure $next): mixed
    {
        if (! ($this->scope->current()->actorContext?->canAdminister() ?? false)) {
            throw new AuthorizationException;
        }

        return $next($job);
    }
}
