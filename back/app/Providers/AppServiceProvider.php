<?php

namespace App\Providers;

use App\Contracts\Storage\ArtifactStorage;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
