<?php

use App\Modules\Tenancy\Application\Services\TenantScheduleRegistry;
use App\Support\Cache\TenantCacheStore;
use App\Support\Operations\BackgroundObserver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('operations.background.enabled') === true) {
    Schedule::call(static fn () => app(BackgroundObserver::class)->schedulerTick())
        ->name('ops-background-heartbeat')->everyMinute();
}

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(static fn (): bool => config('horizon.metrics_enabled') === true);

Schedule::command('auth:clear-resets')->everyFifteenMinutes()->withoutOverlapping();

$tenantTasks = app(TenantScheduleRegistry::class)->names();
if ($tenantTasks !== []) {
    // Refuse local/failover stores before registering distributed scheduler guards.
    app(TenantCacheStore::class)->repository();
    Schedule::useCache((string) config('tenancy.cache_store'));
}
foreach ($tenantTasks as $task) {
    Schedule::command('tenancy:dispatch-scheduled', [$task])
        ->name('tenant-schedule:'.$task)
        ->everyMinute()
        ->onOneServer()
        ->withoutOverlapping(2);
}

if (config('tenancy.portability.expire_exports_scheduled') === true) {
    app(TenantCacheStore::class)->repository();
    Schedule::useCache((string) config('tenancy.cache_store'));
    Schedule::command('organizations:expire-exports')->everyTenMinutes()
        ->name('tenant-export-expiry')->onOneServer()->withoutOverlapping(2);
}

if (config('report_exports.expire_artifacts_scheduled') === true) {
    app(TenantCacheStore::class)->repository();
    Schedule::useCache((string) config('tenancy.cache_store'));
    Schedule::command('tenancy:expire-artifacts')->everyTenMinutes()
        ->name('tenant-artifact-expiry')->onOneServer()->withoutOverlapping(2);
}
