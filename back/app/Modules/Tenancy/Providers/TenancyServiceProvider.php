<?php

namespace App\Modules\Tenancy\Providers;

use App\Http\Api\RequestId;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Contracts\TenantJobMiddleware;
use App\Modules\Tenancy\Application\Contracts\TenantResourceLimits;
use App\Modules\Tenancy\Application\Contracts\TenantScheduleQueue;
use App\Modules\Tenancy\Application\Services\TenantArtifactService;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Application\Services\TenantTechnicalLimits;
use App\Modules\Tenancy\Domain\Contracts\AccessAuditRepository;
use App\Modules\Tenancy\Domain\Contracts\ApprovedTenantDomains;
use App\Modules\Tenancy\Domain\Contracts\ArtifactLedger;
use App\Modules\Tenancy\Domain\Contracts\InvitationNotifier;
use App\Modules\Tenancy\Domain\Contracts\InvitationRepository;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Contracts\PortabilityArchive;
use App\Modules\Tenancy\Domain\Contracts\PortabilityQueue;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Contracts\TenantWorkRepository;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Infrastructure\Mail\MailInvitationNotifier;
use App\Modules\Tenancy\Infrastructure\Persistence\DatabaseArtifactLedger;
use App\Modules\Tenancy\Infrastructure\Persistence\DatabasePortabilityRepository;
use App\Modules\Tenancy\Infrastructure\Persistence\EloquentAccessAuditRepository;
use App\Modules\Tenancy\Infrastructure\Persistence\EloquentInvitationRepository;
use App\Modules\Tenancy\Infrastructure\Persistence\EloquentMembershipRepository;
use App\Modules\Tenancy\Infrastructure\Persistence\EloquentOrganizationRepository;
use App\Modules\Tenancy\Infrastructure\Persistence\EloquentTenantWorkRepository;
use App\Modules\Tenancy\Infrastructure\Queue\LaravelPortabilityQueue;
use App\Modules\Tenancy\Infrastructure\Queue\LaravelTenantScheduleQueue;
use App\Modules\Tenancy\Infrastructure\Queue\RestoreTenantJobContext;
use App\Modules\Tenancy\Infrastructure\Resolution\ConfiguredTenantDomains;
use App\Modules\Tenancy\Infrastructure\Resources\LaravelTenantCache;
use App\Modules\Tenancy\Infrastructure\Resources\PrivatePortabilityArchive;
use App\Modules\Tenancy\Presentation\Console\CheckTenantSchemaCommand;
use App\Modules\Tenancy\Presentation\Console\DispatchTenantSchedulesCommand;
use App\Modules\Tenancy\Presentation\Console\ExpireOrganizationExportsCommand;
use App\Modules\Tenancy\Presentation\Console\OrganizationPortabilityCommand;
use App\Modules\Tenancy\Presentation\Console\ProvisionOrganizationCommand;
use App\Modules\Tenancy\Presentation\Console\TransitionOrganizationCommand;
use App\Modules\Tenancy\Presentation\Policies\InvitationPolicy;
use App\Modules\Tenancy\Presentation\Policies\MembershipPolicy;
use App\Modules\Tenancy\Presentation\Policies\OrganizationPolicy;
use App\Modules\Tenancy\Presentation\Policies\TenantContextPolicy;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PortabilityRepository::class, DatabasePortabilityRepository::class);
        $this->app->bind(PortabilityArchive::class, PrivatePortabilityArchive::class);
        $this->app->bind(PortabilityQueue::class, LaravelPortabilityQueue::class);
        $this->app->scoped(TenantExecutionScope::class);
        $this->app->bind(TenantJobMiddleware::class, RestoreTenantJobContext::class);
        $this->app->bind(TenantWorkRepository::class, EloquentTenantWorkRepository::class);
        $this->app->bind(TenantScheduleQueue::class, LaravelTenantScheduleQueue::class);
        $this->app->bind(TenantExecutionContext::class, fn (Application $app): TenantExecutionContext => $app->make(TenantExecutionScope::class)->current());
        $this->app->bind(TenantArtifacts::class, TenantArtifactService::class);
        $this->app->bind(TenantResourceLimits::class, TenantTechnicalLimits::class);
        $this->app->bind(TenantCache::class, LaravelTenantCache::class);
        $this->app->bind(ArtifactLedger::class, DatabaseArtifactLedger::class);
        $this->app->bind(OrganizationRepository::class, EloquentOrganizationRepository::class);
        $this->app->bind(MembershipRepository::class, EloquentMembershipRepository::class);
        $this->app->bind(InvitationRepository::class, EloquentInvitationRepository::class);
        $this->app->bind(AccessAuditRepository::class, EloquentAccessAuditRepository::class);
        $this->app->bind(InvitationNotifier::class, MailInvitationNotifier::class);
        $this->app->bind(ApprovedTenantDomains::class, ConfiguredTenantDomains::class);
        // Resolve from the current request every time; never cache a context in a singleton.
        $this->app->bind(TenantContext::class, function (Application $app): TenantContext {
            $scope = $app->make(TenantExecutionScope::class);
            if ($scope->active()) {
                return $scope->current()->actorContext ?? throw new TenantContextRequiredException;
            }
            /** @var Request $request */
            $request = $app->make('request');
            $context = $request->attributes->get(TenantContext::ATTRIBUTE);
            if (! $context instanceof TenantContext
                || $context->actorUserId !== (int) $request->user()?->getAuthIdentifier()
                || $context->requestId !== $request->attributes->get(RequestId::ATTRIBUTE)) {
                throw new TenantContextRequiredException;
            }

            return $context;
        });
    }

    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(TenantContext::class, TenantContextPolicy::class);
        Gate::policy(OrganizationMembership::class, MembershipPolicy::class);
        Gate::policy(OrganizationInvitation::class, InvitationPolicy::class);
        RateLimiter::for('tenant-invitations', fn (Request $request): Limit => Limit::perMinute(10)
            ->by(TenantResourceNamespace::key((string) $request->route('tenant'), 'rate',
                'invitations:'.(string) $request->user()?->getAuthIdentifier())));
        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->name('api.v1.')
            ->group(__DIR__.'/../Presentation/Routes/api.php');
        if ($this->app->runningInConsole()) {
            $this->commands([ProvisionOrganizationCommand::class, TransitionOrganizationCommand::class,
                CheckTenantSchemaCommand::class, DispatchTenantSchedulesCommand::class]);
            $this->commands([OrganizationPortabilityCommand::class, ExpireOrganizationExportsCommand::class]);
        }
    }
}
