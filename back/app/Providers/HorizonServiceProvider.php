<?php

namespace App\Providers;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Contracts\MfaRepository;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
        Horizon::auth(static fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            $authorizedEmails = config('horizon.authorized_emails', []);

            return $user !== null
                && is_array($authorizedEmails)
                && in_array($user->email, $authorizedEmails, true)
                && app(MfaSession::class)->verified(
                    app(MfaRepository::class)->find((int) $user->getKey()), request(),
                );
        });
    }
}
