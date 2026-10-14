<?php

namespace App\Modules\Contracts\Providers;

use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Infrastructure\Persistence\EloquentContractRepository;
use App\Modules\Contracts\Presentation\Policies\ContractPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContractRepository::class, EloquentContractRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(ContractRecord::class, ContractPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
