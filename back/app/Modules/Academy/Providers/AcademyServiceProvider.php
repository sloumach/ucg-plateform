<?php

namespace App\Modules\Academy\Providers;

use App\Modules\Academy\Domain\Contracts\AcademyRepository;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Academy\Infrastructure\Persistence\EloquentAcademyRepository;
use App\Modules\Academy\Presentation\Policies\AcademyPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AcademyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AcademyRepository::class, EloquentAcademyRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Cohort::class, AcademyPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
