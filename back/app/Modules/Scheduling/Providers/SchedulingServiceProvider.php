<?php

namespace App\Modules\Scheduling\Providers;

use App\Modules\Scheduling\Domain\Contracts\AvailabilityRepository;
use App\Modules\Scheduling\Domain\Contracts\CalendarConflictRepository;
use App\Modules\Scheduling\Domain\Contracts\CalendarInvitationRepository;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Scheduling\Domain\Contracts\CalendarResourceRepository;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentAvailabilityRepository;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentCalendarConflictRepository;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentCalendarInvitationRepository;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentCalendarRepository;
use App\Modules\Scheduling\Infrastructure\Persistence\EloquentCalendarResourceRepository;
use App\Modules\Scheduling\Presentation\Policies\AvailabilityPolicy;
use App\Modules\Scheduling\Presentation\Policies\CalendarPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SchedulingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CalendarInvitationRepository::class, EloquentCalendarInvitationRepository::class);
        $this->app->bind(CalendarResourceRepository::class, EloquentCalendarResourceRepository::class);
        $this->app->bind(CalendarConflictRepository::class, EloquentCalendarConflictRepository::class);
        $this->app->bind(AvailabilityRepository::class, EloquentAvailabilityRepository::class);
        $this->app->bind(CalendarRepository::class, EloquentCalendarRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Availability::class, AvailabilityPolicy::class);
        Gate::policy(CalendarActivity::class, CalendarPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
