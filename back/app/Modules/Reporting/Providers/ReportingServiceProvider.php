<?php

namespace App\Modules\Reporting\Providers;

use App\Modules\Reporting\Application\Contracts\DashboardIndicatorQueue;
use App\Modules\Reporting\Application\Contracts\ReportExportQueue;
use App\Modules\Reporting\Domain\Contracts\DashboardIndicatorRepository;
use App\Modules\Reporting\Domain\Contracts\DataSourceBundle;
use App\Modules\Reporting\Domain\Contracts\ReportExportRepository;
use App\Modules\Reporting\Infrastructure\Files\LocalDataSourceBundle;
use App\Modules\Reporting\Infrastructure\Persistence\EloquentDashboardIndicatorRepository;
use App\Modules\Reporting\Infrastructure\Persistence\EloquentReportExportRepository;
use App\Modules\Reporting\Infrastructure\Queue\LaravelDashboardIndicatorQueue;
use App\Modules\Reporting\Infrastructure\Queue\LaravelReportExportQueue;
use App\Modules\Reporting\Presentation\Console\AuditDataPreparationCommand;
use App\Modules\Reporting\Presentation\Console\AuditDataReferencesCommand;
use App\Modules\Reporting\Presentation\Console\ReviewDataPreparationCommand;
use App\Modules\Reporting\Presentation\Policies\DashboardPolicy;
use App\Modules\Reporting\Presentation\Policies\ReportExportPolicy;
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
        $this->app->bind(DataSourceBundle::class, LocalDataSourceBundle::class);
        $this->app->bind(ReportExportRepository::class, EloquentReportExportRepository::class);
        $this->app->bind(ReportExportQueue::class, LaravelReportExportQueue::class);
        $this->app->bind(DashboardIndicatorRepository::class, EloquentDashboardIndicatorRepository::class);
        $this->app->bind(DashboardIndicatorQueue::class, LaravelDashboardIndicatorQueue::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AuditDataPreparationCommand::class, AuditDataReferencesCommand::class, ReviewDataPreparationCommand::class]);
        }
        Gate::define('reporting.exports', [ReportExportPolicy::class, 'manage']);
        RateLimiter::for('report-exports', fn (Request $request) => Limit::perMinute(12)->by('report-exports:'.strtolower((string) $request->route('tenant')).':'.(string) $request->user()?->getAuthIdentifier()));
        Gate::define('search.viewAny', [TenantSearchPolicy::class, 'viewAny']);
        RateLimiter::for('tenant-search', fn (Request $request) => Limit::perMinute(60)->by('tenant-search:'.strtolower((string) $request->route('tenant')).':'.(string) $request->user()?->getAuthIdentifier()));
        Gate::define('reporting.viewAny', [DashboardPolicy::class, 'viewAny']);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1')->name('api.v1.')
            ->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
