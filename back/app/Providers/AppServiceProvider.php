<?php

namespace App\Providers;

use App\Contracts\Operations\BackgroundHeartbeats;
use App\Contracts\Operations\BackgroundWorkloads;
use App\Contracts\Operations\RuntimeProbe;
use App\Contracts\Storage\ArtifactStorage;
use App\Support\Operations\BackgroundHeartbeatRepository;
use App\Support\Operations\BackgroundObserver;
use App\Support\Operations\BackgroundWorkloadRepository;
use App\Support\Operations\LaravelRuntimeProbe;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Dispatcher $events): void
    {
        $events->listen(Looping::class, [BackgroundObserver::class, 'looping']);
    }
}
