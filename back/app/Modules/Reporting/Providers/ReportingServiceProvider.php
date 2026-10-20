<?php

namespace App\Modules\Reporting\Providers;

use App\Modules\Reporting\Application\Contracts\DashboardIndicatorQueue;
use App\Modules\Reporting\Domain\Contracts\DashboardIndicatorRepository;
use App\Modules\Reporting\Infrastructure\Persistence\EloquentDashboardIndicatorRepository;
use App\Modules\Reporting\Infrastructure\Queue\LaravelDashboardIndicatorQueue;
use App\Modules\Reporting\Presentation\Policies\DashboardPolicy;
use App\Modules\Reporting\Presentation\Policies\TenantSearchPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DashboardIndicatorRepository::class, EloquentDashboardIndicatorRepository::class);
        $this->app->bind(DashboardIndicatorQueue::class, LaravelDashboardIndicatorQueue::class);
    }

    public function boot(): void
    {
        Gate::define('search.viewAny', [TenantSearchPolicy::class, 'viewAny']);
        RateLimiter::for('tenant-search', fn (Request $request) => Limit::perMinute(60)->by('tenant-search:'.strtolower((string) $request->route('tenant')).':'.(string) $request->user()?->getAuthIdentifier()));
        Gate::define('reporting.viewAny', [DashboardPolicy::class, 'viewAny']);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1')->name('api.v1.')
            ->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
