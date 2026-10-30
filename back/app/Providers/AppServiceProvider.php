<?php

namespace App\Providers;

use App\Contracts\Operations\AlertLedger;
use App\Contracts\Operations\ApiMetrics;
use App\Contracts\Operations\BackgroundHeartbeats;
use App\Contracts\Operations\BackgroundWorkloads;
use App\Contracts\Operations\BackupEvidence;
use App\Contracts\Operations\DependencyTransport;
use App\Contracts\Operations\RuntimeProbe;
use App\Contracts\Storage\ArtifactStorage;
use App\Support\Operations\BackgroundHeartbeatRepository;
use App\Support\Operations\BackgroundObserver;
use App\Support\Operations\BackgroundWorkloadRepository;
use App\Support\Operations\CacheApiMetrics;
use App\Support\Operations\DatabaseAlertLedger;
use App\Support\Operations\FileBackupEvidence;
use App\Support\Operations\LaravelRuntimeProbe;
use App\Support\Operations\SocketDependencyTransport;
use App\Support\Storage\LaravelArtifactStorage;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ArtifactStorage::class, LaravelArtifactStorage::class);
        $this->app->bind(RuntimeProbe::class, LaravelRuntimeProbe::class);
        $this->app->singleton(BackgroundObserver::class);
        $this->app->bind(BackgroundHeartbeats::class, BackgroundHeartbeatRepository::class);
        $this->app->bind(BackgroundWorkloads::class, BackgroundWorkloadRepository::class);
        $this->app->bind(ApiMetrics::class, CacheApiMetrics::class);
        $this->app->bind(AlertLedger::class, DatabaseAlertLedger::class);
        $this->app->bind(DependencyTransport::class, SocketDependencyTransport::class);
        $this->app->bind(BackupEvidence::class, FileBackupEvidence::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Dispatcher $events): void
    {
        $events->listen(Looping::class, [BackgroundObserver::class, 'looping']);
    }
}
