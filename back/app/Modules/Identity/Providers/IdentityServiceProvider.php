<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\EloquentAccountDirectory;
use App\Modules\Identity\Infrastructure\Persistence\EloquentUserRepository;
use App\Modules\Identity\Presentation\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);
        $this->app->bind(
            StatefulGuard::class,
            fn ($app): StatefulGuard => $app->make('auth')->guard('web'),
        );
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(5)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip(),
        ));

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
