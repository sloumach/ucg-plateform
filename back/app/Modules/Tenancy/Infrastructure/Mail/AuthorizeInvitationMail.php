<?php

namespace App\Modules\Tenancy\Infrastructure\Mail;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\SendQueuedMailable;

final readonly class AuthorizeInvitationMail
{
    public function __construct(private TenantExecutionScope $scope, private TenantAuthorization $authorization) {}

    /** @param Closure(SendQueuedMailable): mixed $next */
    public function handle(SendQueuedMailable $job, Closure $next): mixed
    {
        $context = $this->scope->current()->actorContext;
        if ($context === null) {
            throw new AuthorizationException;
        }
        $this->authorization->authorize($context, 'invitations.manage');

        return $next($job);
    }
}
