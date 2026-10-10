<?php

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Infrastructure\Persistence\EloquentOrganizationRepository;
use App\Modules\Tenancy\Presentation\Console\ProvisionOrganizationCommand;
use App\Modules\Tenancy\Presentation\Console\TransitionOrganizationCommand;
use App\Modules\Tenancy\Presentation\Policies\OrganizationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganizationRepository::class, EloquentOrganizationRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->name('api.v1.')
            ->group(__DIR__.'/../Presentation/Routes/api.php');
        if ($this->app->runningInConsole()) {
            $this->commands([ProvisionOrganizationCommand::class, TransitionOrganizationCommand::class]);
        }
    }
}
