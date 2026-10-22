<?php

namespace App\Modules\Academy\Providers;

use App\Modules\Academy\Domain\Contracts\AcademyCoachingRepository;
use App\Modules\Academy\Domain\Contracts\AcademyRepository;
use App\Modules\Academy\Domain\Contracts\AcademySessionRepository;
use App\Modules\Academy\Domain\Models\AcademyObjective;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Academy\Infrastructure\Persistence\EloquentAcademyCoachingRepository;
use App\Modules\Academy\Infrastructure\Persistence\EloquentAcademyRepository;
use App\Modules\Academy\Infrastructure\Persistence\EloquentAcademySessionRepository;
use App\Modules\Academy\Presentation\Policies\AcademyCoachingPolicy;
use App\Modules\Academy\Presentation\Policies\AcademyPolicy;
use App\Modules\Academy\Presentation\Policies\AcademySessionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AcademyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AcademyCoachingRepository::class, EloquentAcademyCoachingRepository::class);
        $this->app->bind(AcademySessionRepository::class, EloquentAcademySessionRepository::class);
        $this->app->bind(AcademyRepository::class, EloquentAcademyRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(AcademyObjective::class, AcademyCoachingPolicy::class);
        Gate::policy(AcademySession::class, AcademySessionPolicy::class);
        Gate::policy(Cohort::class, AcademyPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
