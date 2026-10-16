<?php

namespace App\Modules\Partnerships\Providers;

use App\Modules\Partnerships\Domain\Contracts\SponsorRepository;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipAgreementRepository;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Infrastructure\Persistence\EloquentSponsorRepository;
use App\Modules\Partnerships\Infrastructure\Persistence\EloquentSponsorshipAgreementRepository;
use App\Modules\Partnerships\Presentation\Policies\SponsorPolicy;
use App\Modules\Partnerships\Presentation\Policies\SponsorshipAgreementPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PartnershipsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SponsorshipAgreementRepository::class, EloquentSponsorshipAgreementRepository::class);
        $this->app->bind(SponsorRepository::class, EloquentSponsorRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(SponsorshipAgreement::class, SponsorshipAgreementPolicy::class);
        Gate::policy(Sponsor::class, SponsorPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
