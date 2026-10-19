<?php

namespace App\Modules\Performance\Providers;

use App\Modules\Performance\Domain\Contracts\MetricCatalogueRepository;
use App\Modules\Performance\Domain\Models\MetricDefinition;
use App\Modules\Performance\Infrastructure\Persistence\EloquentMetricCatalogueRepository;
use App\Modules\Performance\Presentation\Policies\MetricCataloguePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PerformanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MetricCatalogueRepository::class, EloquentMetricCatalogueRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(MetricDefinition::class, MetricCataloguePolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1/metric-catalogue')->name('api.v1.metric-catalogue.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
