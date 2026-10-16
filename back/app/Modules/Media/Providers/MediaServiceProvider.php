<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Domain\Contracts\MediaRepository;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Media\Infrastructure\Persistence\EloquentMediaRepository;
use App\Modules\Media\Presentation\Policies\MediaPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaRepository::class, EloquentMediaRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(MediaItem::class, MediaPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
