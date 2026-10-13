<?php

namespace App\Modules\Teams\Providers;

use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use App\Modules\Teams\Application\Contracts\OrganizationGameCatalogue;
use App\Modules\Teams\Application\Services\EffectiveGameCatalogue;
use App\Modules\Teams\Application\Services\GameProfileCatalogueBridge;
use App\Modules\Teams\Domain\Contracts\GameCatalogueRepository;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Infrastructure\Persistence\EloquentGameCatalogueRepository;
use App\Modules\Teams\Presentation\Policies\GameCataloguePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TeamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GameCatalogueRepository::class, EloquentGameCatalogueRepository::class);
        $this->app->bind(GameProfileCatalogue::class, GameProfileCatalogueBridge::class);
        $this->app->bind(OrganizationGameCatalogue::class, EffectiveGameCatalogue::class);
    }

    public function boot(): void
    {
        Gate::policy(GameCatalogueEntry::class, GameCataloguePolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
