<?php

namespace App\Modules\Teams\Providers;

use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use App\Modules\Teams\Application\Contracts\CalendarTeamsDirectory;
use App\Modules\Teams\Application\Contracts\CommunicationTeamsDirectory;
use App\Modules\Teams\Application\Contracts\ExternalOpponents;
use App\Modules\Teams\Application\Contracts\HistoricalLineups;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Teams\Application\Contracts\MediaTeamsDirectory;
use App\Modules\Teams\Application\Contracts\OrganizationGameCatalogue;
use App\Modules\Teams\Application\Contracts\PartnershipTeamsDirectory;
use App\Modules\Teams\Application\Services\CalendarTeamsService;
use App\Modules\Teams\Application\Services\CommunicationTeamsService;
use App\Modules\Teams\Application\Services\EffectiveGameCatalogue;
use App\Modules\Teams\Application\Services\ExternalOpponentSnapshotService;
use App\Modules\Teams\Application\Services\GameProfileCatalogueBridge;
use App\Modules\Teams\Application\Services\MatchReferenceService;
use App\Modules\Teams\Application\Services\MediaTeamsService;
use App\Modules\Teams\Application\Services\PartnershipTeamsService;
use App\Modules\Teams\Application\Services\TeamDepartureParticipant;
use App\Modules\Teams\Application\Services\TeamLineupService;
use App\Modules\Teams\Domain\Contracts\ExternalOpponentRepository;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Contracts\MatchReferenceRepository;
use App\Modules\Teams\Domain\Contracts\TeamAudienceRepository;
use App\Modules\Teams\Domain\Contracts\TeamEligibilityRepository;
use App\Modules\Teams\Domain\Contracts\TeamLineupRepository;
use App\Modules\Teams\Domain\Contracts\TeamMembershipRepository;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Teams\Infrastructure\Persistence\EloquentExternalOpponentRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentGameCatalogueRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentMatchReferenceRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentTeamAudienceRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentTeamEligibilityRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentTeamLineupRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentTeamMembershipRepository;
use App\Modules\Teams\Infrastructure\Persistence\EloquentTeamRepository;
use App\Modules\Teams\Infrastructure\Resolution\TeamAuthorizationScopes;
use App\Modules\Teams\Presentation\Policies\ExternalOpponentPolicy;
use App\Modules\Teams\Presentation\Policies\GameCataloguePolicy;
use App\Modules\Teams\Presentation\Policies\TeamEligibilityPolicy;
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
        $this->app->bind(MatchReferenceDirectory::class, MatchReferenceService::class);
        $this->app->bind(MatchReferenceRepository::class, EloquentMatchReferenceRepository::class);
        $this->app->bind(CalendarTeamsDirectory::class, CalendarTeamsService::class);
        $this->app->bind(CommunicationTeamsDirectory::class, CommunicationTeamsService::class);
        $this->app->bind(TeamAudienceRepository::class, EloquentTeamAudienceRepository::class);
        $this->app->bind(MediaTeamsDirectory::class, MediaTeamsService::class);
        $this->app->bind(PartnershipTeamsDirectory::class, PartnershipTeamsService::class);
        $this->app->bind(ExternalOpponentRepository::class, EloquentExternalOpponentRepository::class);
        $this->app->bind(ExternalOpponents::class, ExternalOpponentSnapshotService::class);
        $this->app->bind(TeamEligibilityRepository::class, EloquentTeamEligibilityRepository::class);
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
        Gate::policy(ExternalOpponent::class, ExternalOpponentPolicy::class);
        Gate::policy(TeamEligibilityRuleset::class, TeamEligibilityPolicy::class);
        Gate::policy(TeamLineup::class, TeamLineupPolicy::class);
        config(['tenancy.authorization_scope_resolvers.team' => TeamAuthorizationScopes::class]);
        Gate::policy(TeamMembership::class, TeamMembershipPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(GameCatalogueEntry::class, GameCataloguePolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
