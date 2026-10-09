<?php

namespace App\Providers;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
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
                && in_array($user->email, $authorizedEmails, true);
        });
    }
}
