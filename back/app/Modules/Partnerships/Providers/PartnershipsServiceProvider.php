<?php

namespace App\Modules\Partnerships\Providers;

use App\Modules\Partnerships\Domain\Contracts\SponsorRepository;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Infrastructure\Persistence\EloquentSponsorRepository;
use App\Modules\Partnerships\Presentation\Policies\SponsorPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PartnershipsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SponsorRepository::class, EloquentSponsorRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Sponsor::class, SponsorPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
