<?php

namespace App\Modules\Competition\Providers;

use App\Modules\Competition\Application\Contracts\MatchRecalculationQueue;
use App\Modules\Competition\Application\Contracts\MatchScorePublisher;
use App\Modules\Competition\Domain\Contracts\ExternalCompetitionRepository;
use App\Modules\Competition\Domain\Contracts\MatchCorrectionRepository;
use App\Modules\Competition\Domain\Contracts\MatchLineupRepository;
use App\Modules\Competition\Domain\Contracts\MatchNotebookRepository;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Contracts\MatchResultRepository;
use App\Modules\Competition\Domain\Contracts\MatchScoreRepository;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Infrastructure\Broadcasting\LaravelMatchScorePublisher;
use App\Modules\Competition\Infrastructure\Persistence\EloquentExternalCompetitionRepository;
use App\Modules\Competition\Infrastructure\Persistence\EloquentMatchCorrectionRepository;
use App\Modules\Competition\Infrastructure\Persistence\EloquentMatchLineupRepository;
use App\Modules\Competition\Infrastructure\Persistence\EloquentMatchNotebookRepository;
use App\Modules\Competition\Infrastructure\Persistence\EloquentMatchRepository;
use App\Modules\Competition\Infrastructure\Persistence\EloquentMatchResultRepository;
use App\Modules\Competition\Infrastructure\Persistence\EloquentMatchScoreRepository;
use App\Modules\Competition\Infrastructure\Queue\LaravelMatchRecalculationQueue;
use App\Modules\Competition\Presentation\Policies\ExternalCompetitionPolicy;
use App\Modules\Competition\Presentation\Policies\MatchPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CompetitionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExternalCompetitionRepository::class, EloquentExternalCompetitionRepository::class);
        $this->app->bind(MatchScorePublisher::class, LaravelMatchScorePublisher::class);
        $this->app->bind(MatchRecalculationQueue::class, LaravelMatchRecalculationQueue::class);
        $this->app->bind(MatchCorrectionRepository::class, EloquentMatchCorrectionRepository::class);
        $this->app->bind(MatchNotebookRepository::class, EloquentMatchNotebookRepository::class);
        $this->app->bind(MatchResultRepository::class, EloquentMatchResultRepository::class);
        $this->app->bind(MatchScoreRepository::class, EloquentMatchScoreRepository::class);
        $this->app->bind(MatchLineupRepository::class, EloquentMatchLineupRepository::class);
        $this->app->bind(MatchRepository::class, EloquentMatchRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(ExternalCompetition::class, ExternalCompetitionPolicy::class);
        Gate::policy(MatchRecord::class, MatchPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
