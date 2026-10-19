<?php

namespace App\Modules\Performance\Providers;

use App\Modules\Performance\Application\Contracts\StatisticalCalculationQueue;
use App\Modules\Performance\Application\Contracts\StatisticalImportQueue;
use App\Modules\Performance\Domain\Contracts\MetricCatalogueRepository;
use App\Modules\Performance\Domain\Contracts\ObservationRepository;
use App\Modules\Performance\Domain\Contracts\StatisticalCalculationRepository;
use App\Modules\Performance\Domain\Contracts\StatisticalImportRepository;
use App\Modules\Performance\Domain\Contracts\StatisticalProfileRepository;
use App\Modules\Performance\Domain\Models\MetricDefinition;
use App\Modules\Performance\Domain\Models\StatisticalCalculation;
use App\Modules\Performance\Domain\Models\StatisticalImport;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use App\Modules\Performance\Infrastructure\Persistence\EloquentMetricCatalogueRepository;
use App\Modules\Performance\Infrastructure\Persistence\EloquentObservationRepository;
use App\Modules\Performance\Infrastructure\Persistence\EloquentStatisticalCalculationRepository;
use App\Modules\Performance\Infrastructure\Persistence\EloquentStatisticalImportRepository;
use App\Modules\Performance\Infrastructure\Persistence\EloquentStatisticalProfileRepository;
use App\Modules\Performance\Infrastructure\Queue\LaravelStatisticalCalculationQueue;
use App\Modules\Performance\Infrastructure\Queue\LaravelStatisticalImportQueue;
use App\Modules\Performance\Presentation\Policies\MetricCataloguePolicy;
use App\Modules\Performance\Presentation\Policies\ObservationPolicy;
use App\Modules\Performance\Presentation\Policies\StatisticalImportPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PerformanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StatisticalProfileRepository::class, EloquentStatisticalProfileRepository::class);
        $this->app->bind(StatisticalCalculationRepository::class, EloquentStatisticalCalculationRepository::class);
        $this->app->bind(StatisticalCalculationQueue::class, LaravelStatisticalCalculationQueue::class);
        $this->app->bind(StatisticalImportQueue::class,
            LaravelStatisticalImportQueue::class);
        $this->app->bind(StatisticalImportRepository::class, EloquentStatisticalImportRepository::class);
        $this->app->bind(ObservationRepository::class, EloquentObservationRepository::class);
        $this->app->bind(MetricCatalogueRepository::class, EloquentMetricCatalogueRepository::class);
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/profiles.php');
        Gate::policy(StatisticalCalculation::class, ObservationPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/calculations.php');
        Gate::policy(StatisticalImport::class, StatisticalImportPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/imports.php');
        Gate::policy(StatisticalObservation::class, ObservationPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/observations.php');
        Gate::policy(MetricDefinition::class, MetricCataloguePolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1/metric-catalogue')->name('api.v1.metric-catalogue.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
