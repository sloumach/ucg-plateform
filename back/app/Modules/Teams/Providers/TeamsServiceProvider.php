<?php

namespace App\Modules\Teams\Providers;

use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use App\Modules\Teams\Application\Contracts\HistoricalLineups;
use App\Modules\Teams\Application\Contracts\OrganizationGameCatalogue;
use App\Modules\Teams\Application\Services\EffectiveGameCatalogue;
use App\Modules\Teams\Application\Services\GameProfileCatalogueBridge;
use App\Modules\Teams\Application\Services\TeamDepartureParticipant;
use App\Modules\Teams\Application\Services\TeamLineupService;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Contracts\TeamLineupRepository;
use App\Modules\Teams\Domain\Contracts\TeamMembershipRepository;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Teams\Infrastructure\Persistence\EloquentGameCatalogueRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentTeamLineupRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentTeamMembershipRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentTeamRepository;
use App\Modules\Teams\Infrastructure\Resolution\TeamAuthorizationScopes;
use App\Modules\Teams\Presentation\Policies\GameCataloguePolicy;
use App\Modules\Teams\Presentation\Policies\TeamLineupPolicy;
use App\Modules\Teams\Presentation\Policies\TeamMembershipPolicy;
use App\Modules\Teams\Presentation\Policies\TeamPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TeamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TeamLineupRepository::class, EloquentTeamLineupRepository::class);
        $this->app->bind(HistoricalLineups::class, TeamLineupService::class);
        $this->app->bind(TeamMembershipRepository::class, EloquentTeamMembershipRepository::class);
        $this->app->tag(TeamDepartureParticipant::class, 'people.departure-participants');
        $this->app->bind(TeamRepository::class,
            EloquentTeamRepository::class);
        $this->app->bind(GameCatalogueRepository::class, EloquentGameCatalogueRepository::class);
        $this->app->bind(GameProfileCatalogue::class, GameProfileCatalogueBridge::class);
        $this->app->bind(OrganizationGameCatalogue::class, EffectiveGameCatalogue::class);
    }

    public function boot(): void
    {
        Gate::policy(TeamLineup::class, TeamLineupPolicy::class);
        config(['tenancy.authorization_scope_resolvers.team' => TeamAuthorizationScopes::class]);
        Gate::policy(TeamMembership::class, TeamMembershipPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(GameCatalogueEntry::class, GameCataloguePolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
