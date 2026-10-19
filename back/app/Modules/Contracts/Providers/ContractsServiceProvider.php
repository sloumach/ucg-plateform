<?php

namespace App\Modules\Contracts\Providers;

use App\Modules\Contracts\Application\Contracts\ContractDocumentScanQueue;
use App\Modules\Contracts\Application\Contracts\ContractsDashboard;
use App\Modules\Contracts\Application\Contracts\PartnershipContractDirectory;
use App\Modules\Contracts\Application\Services\ContractsDashboardService;
use App\Modules\Contracts\Application\Services\PartnershipContractService;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanner;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanRepository;
use App\Modules\Contracts\Domain\Contracts\ContractGovernanceRepository;
use App\Modules\Contracts\Domain\Contracts\ContractReminderMailer;
use App\Modules\Contracts\Domain\Contracts\ContractReminderRepository;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Infrastructure\Mail\LaravelContractReminderMailer;
use App\Modules\Contracts\Infrastructure\Persistence\EloquentContractDocumentRepository;
use App\Modules\Contracts\Infrastructure\Persistence\EloquentContractDocumentScanRepository;
use App\Modules\Contracts\Infrastructure\Persistence\EloquentContractGovernanceRepository;
use App\Modules\Contracts\Infrastructure\Persistence\EloquentContractReminderRepository;
use App\Modules\Contracts\Infrastructure\Persistence\EloquentContractRepository;
use App\Modules\Contracts\Infrastructure\Queue\LaravelContractDocumentScanQueue;
use App\Modules\Contracts\Infrastructure\Scanning\ClamAvDocumentScanner;
use App\Modules\Contracts\Presentation\Policies\ContractPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContractsDashboard::class, ContractsDashboardService::class);
        $this->app->bind(PartnershipContractDirectory::class, PartnershipContractService::class);
        $this->app->bind(ContractReminderRepository::class, EloquentContractReminderRepository::class);
        $this->app->bind(ContractReminderMailer::class, LaravelContractReminderMailer::class);
        $this->app->bind(ContractGovernanceRepository::class, EloquentContractGovernanceRepository::class);
        $this->app->bind(ContractRepository::class, EloquentContractRepository::class);
        $this->app->bind(ContractDocumentRepository::class, EloquentContractDocumentRepository::class);
        $this->app->bind(ContractDocumentScanRepository::class, EloquentContractDocumentScanRepository::class);
        $this->app->bind(ContractDocumentScanner::class, ClamAvDocumentScanner::class);
        $this->app->bind(ContractDocumentScanQueue::class, LaravelContractDocumentScanQueue::class);
    }

    public function boot(): void
    {
        foreach (['document-scans' => 10, 'document-links' => 60, 'document-downloads' => 20] as $limiter => $perMinute) {
            RateLimiter::for($limiter, fn (Request $request) => Limit::perMinute($perMinute)
                ->by(strtolower((string) $request->route('tenant')).':'.(string) $request->user()?->getAuthIdentifier()));
        }
        Gate::policy(ContractRecord::class, ContractPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
