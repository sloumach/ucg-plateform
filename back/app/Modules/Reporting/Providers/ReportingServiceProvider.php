<?php

namespace App\Modules\Reporting\Providers;

use App\Modules\Reporting\Presentation\Policies\DashboardPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('reporting.viewAny', [DashboardPolicy::class, 'viewAny']);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1')->name('api.v1.')
            ->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
