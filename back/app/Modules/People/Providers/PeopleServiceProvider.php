<?php

namespace App\Modules\People\Providers;

use App\Modules\People\Application\Contracts\PeopleDirectory;
use App\Modules\People\Application\Services\PersonService;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Infrastructure\Persistence\EloquentPersonRepository;
use App\Modules\People\Presentation\Policies\PersonPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PeopleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PersonRepository::class, EloquentPersonRepository::class);
        $this->app->bind(PeopleDirectory::class, PersonService::class);
    }

    public function boot(): void
    {
        Gate::policy(Person::class, PersonPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
