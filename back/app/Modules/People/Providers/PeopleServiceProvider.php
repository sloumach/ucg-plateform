<?php

namespace App\Modules\People\Providers;

use App\Modules\People\Application\Contracts\AdministrativePeopleDirectory;
use App\Modules\People\Application\Contracts\AvailabilityPeopleDirectory;
use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\People\Application\Contracts\CompetitionRegistrationPeople;
use App\Modules\People\Application\Contracts\EligibilityPeopleDirectory;
use App\Modules\People\Application\Contracts\LinkedPersonAccountDirectory;
use App\Modules\People\Application\Contracts\PartnershipPeopleDirectory;
use App\Modules\People\Application\Contracts\PeopleDirectory;
use App\Modules\People\Application\Contracts\PersonImportQueue;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\People\Application\Services\AdministrativePeopleService;
use App\Modules\People\Application\Services\AvailabilityPeopleService;
use App\Modules\People\Application\Services\CalendarPeopleService;
use App\Modules\People\Application\Services\CompetitionRegistrationPeopleService;
use App\Modules\People\Application\Services\EligibilityPeopleService;
use App\Modules\People\Application\Services\LinkedPersonAccountService;
use App\Modules\People\Application\Services\PartnershipPeopleService;
use App\Modules\People\Application\Services\PersonDepartureService;
use App\Modules\People\Application\Services\PersonService;
use App\Modules\People\Application\Services\SportingPeopleService;
use App\Modules\People\Domain\Contracts\CalendarPeopleRepository;
use App\Modules\People\Domain\Contracts\ExternalGameAccountRepository;
use App\Modules\People\Domain\Contracts\GameProfileRepository;
use App\Modules\People\Domain\Contracts\PersonImportRepository;
use App\Modules\People\Domain\Contracts\PersonLifecycleRepository;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\People\Domain\Models\PersonPeriod;
use App\Modules\People\Infrastructure\Persistence\EloquentCalendarPeopleRepository;
use App\Modules\People\Infrastructure\Persistence\EloquentExternalGameAccountRepository;
use App\Modules\People\Infrastructure\Persistence\EloquentGameProfileRepository;
use App\Modules\People\Infrastructure\Persistence\EloquentPersonImportRepository;
use App\Modules\People\Infrastructure\Persistence\EloquentPersonLifecycleRepository;
use App\Modules\People\Infrastructure\Persistence\EloquentPersonRepository;
use App\Modules\People\Infrastructure\Queue\LaravelPersonImportQueue;
use App\Modules\People\Presentation\Policies\GameProfilePolicy;
use App\Modules\People\Presentation\Policies\PersonImportPolicy;
use App\Modules\People\Presentation\Policies\PersonLifecyclePolicy;
use App\Modules\People\Presentation\Policies\PersonPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CompetitionRegistrationPeople::class, CompetitionRegistrationPeopleService::class);
        $this->app->bind(AvailabilityPeopleDirectory::class, AvailabilityPeopleService::class);
        $this->app->bind(CalendarPeopleDirectory::class, CalendarPeopleService::class);
        $this->app->bind(CalendarPeopleRepository::class, EloquentCalendarPeopleRepository::class);
        $this->app->bind(PartnershipPeopleDirectory::class, PartnershipPeopleService::class);
        $this->app->bind(LinkedPersonAccountDirectory::class, LinkedPersonAccountService::class);
        $this->app->bind(AdministrativePeopleDirectory::class, AdministrativePeopleService::class);
        $this->app->bind(EligibilityPeopleDirectory::class, EligibilityPeopleService::class);
        $this->app->bind(SportingPeopleDirectory::class, SportingPeopleService::class);
        $this->app->bind(PersonImportQueue::class, LaravelPersonImportQueue::class);
        $this->app->bind(PersonImportRepository::class, EloquentPersonImportRepository::class);
        $this->app->bind(PersonLifecycleRepository::class, EloquentPersonLifecycleRepository::class);
        $this->app->when(PersonDepartureService::class)->needs('$participants')->giveTagged('people.departure-participants');
        $this->app->bind(GameProfileRepository::class, EloquentGameProfileRepository::class);
        $this->app->bind(ExternalGameAccountRepository::class, EloquentExternalGameAccountRepository::class);
        $this->app->bind(PersonRepository::class, EloquentPersonRepository::class);
        $this->app->bind(PeopleDirectory::class, PersonService::class);
    }

    public function boot(): void
    {
        Gate::policy(PersonImport::class, PersonImportPolicy::class);
        Gate::policy(PersonPeriod::class, PersonLifecyclePolicy::class);
        Gate::policy(PersonDepartureItem::class, PersonLifecyclePolicy::class);
        Gate::policy(PersonDeparture::class, PersonLifecyclePolicy::class);
        Gate::policy(GameProfile::class, GameProfilePolicy::class);
        Gate::policy(ExternalGameAccount::class, GameProfilePolicy::class);
        Gate::policy(Person::class, PersonPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
