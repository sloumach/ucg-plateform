<?php

namespace App\Modules\Competition\Providers;

use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Infrastructure\Persistence\EloquentMatchRepository;
use App\Modules\Competition\Presentation\Policies\MatchPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CompetitionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MatchRepository::class, EloquentMatchRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(MatchRecord::class, MatchPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
