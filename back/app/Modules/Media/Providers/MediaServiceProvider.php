<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Application\Contracts\PartnershipMediaDirectory;
use App\Modules\Media\Application\Services\PartnershipMediaService;
use App\Modules\Media\Domain\Contracts\CommunicationRepository;
use App\Modules\Media\Domain\Contracts\MediaRepository;
use App\Modules\Media\Domain\Contracts\WorkTaskRepository;
use App\Modules\Media\Domain\Models\Announcement;
use App\Modules\Media\Domain\Models\MediaItem;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\Media\Infrastructure\Persistence\EloquentCommunicationRepository;
use App\Modules\Media\Infrastructure\Persistence\EloquentMediaRepository;
use App\Modules\Media\Infrastructure\Persistence\EloquentWorkTaskRepository;
use App\Modules\Media\Presentation\Policies\CommunicationPolicy;
use App\Modules\Media\Presentation\Policies\MediaPolicy;
use App\Modules\Media\Presentation\Policies\WorkTaskPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PartnershipMediaDirectory::class, PartnershipMediaService::class);
        $this->app->bind(WorkTaskRepository::class, EloquentWorkTaskRepository::class);
        $this->app->bind(CommunicationRepository::class, EloquentCommunicationRepository::class);
        $this->app->bind(MediaRepository::class, EloquentMediaRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(WorkTask::class, WorkTaskPolicy::class);
        Gate::policy(Announcement::class, CommunicationPolicy::class);
        Gate::policy(MediaItem::class, MediaPolicy::class);
        Route::middleware(['api', 'auth:sanctum', 'identity.session', 'audit.failures', 'mfa'])
            ->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
