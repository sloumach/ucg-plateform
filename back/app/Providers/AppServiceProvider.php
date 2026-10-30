<?php

namespace App\Providers;

use App\Contracts\Operations\RuntimeProbe;
use App\Contracts\Storage\ArtifactStorage;
use App\Support\Operations\LaravelRuntimeProbe;
use App\Support\Storage\LaravelArtifactStorage;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
