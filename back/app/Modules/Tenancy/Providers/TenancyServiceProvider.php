<?php

namespace App\Modules\Tenancy\Providers;

use App\Http\Api\RequestId;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\ApprovedTenantDomains;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Infrastructure\Persistence\EloquentOrganizationRepository;
use App\Modules\Tenancy\Infrastructure\Resolution\ConfiguredTenantDomains;
use App\Modules\Tenancy\Presentation\Console\ProvisionOrganizationCommand;
use App\Modules\Tenancy\Presentation\Console\TransitionOrganizationCommand;
use App\Modules\Tenancy\Presentation\Policies\OrganizationPolicy;
use App\Modules\Tenancy\Presentation\Policies\TenantContextPolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganizationRepository::class, EloquentOrganizationRepository::class);
        $this->app->bind(ApprovedTenantDomains::class, ConfiguredTenantDomains::class);
        // Resolve from the current request every time; never cache a context in a singleton.
        $this->app->bind(TenantContext::class, function (Application $app): TenantContext {
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
        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->name('api.v1.')
            ->group(__DIR__.'/../Presentation/Routes/api.php');
        if ($this->app->runningInConsole()) {
            $this->commands([ProvisionOrganizationCommand::class, TransitionOrganizationCommand::class]);
        }
    }
}
