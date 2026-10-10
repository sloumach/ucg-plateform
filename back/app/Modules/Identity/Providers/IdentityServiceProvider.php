<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Domain\Contracts\SecurityAuditRepository;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\EloquentAccountDirectory;
use App\Modules\Identity\Infrastructure\Persistence\EloquentSecurityAuditRepository;
use App\Modules\Identity\Infrastructure\Persistence\EloquentUserRepository;
use App\Modules\Identity\Presentation\Policies\UserPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(SecurityAuditRepository::class, EloquentSecurityAuditRepository::class);
        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);
        $this->app->bind(
            SessionGuard::class,
            fn ($app): SessionGuard => $app->make('auth')->guard('web'),
        );
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(5)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip(),
        ));
        RateLimiter::for('password-recovery', static fn (Request $request): Limit => Limit::perMinute(3)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip(),
        ));
        RateLimiter::for('verification', static fn (Request $request): Limit => Limit::perMinute(6)->by(
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        $resetUrl = static function (User $user, string $token): string {
            $query = http_build_query(['reset_token' => $token, 'email' => $user->email]);

            return rtrim((string) config('identity.frontend_url'), '/').'/?'.$query;
        };
        ResetPassword::createUrlUsing($resetUrl);
        ResetPassword::toMailUsing(static fn (User $user, string $token): MailMessage => (new MailMessage)
            ->subject(__('identity.mail.password_reset.subject'))
            ->line(__('identity.mail.password_reset.introduction'))
            ->action(__('identity.mail.password_reset.action'), $resetUrl($user, $token))
            ->line(__('identity.mail.password_reset.expiration', [
                'minutes' => (int) config('auth.passwords.users.expire', 60),
            ]))
            ->line(__('identity.mail.password_reset.ignore')));
        VerifyEmail::createUrlUsing(static fn (User $user): string => URL::temporarySignedRoute(
            'api.v1.verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        ));
        VerifyEmail::toMailUsing(static fn (User $user, string $url): MailMessage => (new MailMessage)
            ->subject(__('identity.mail.verification.subject'))
            ->line(__('identity.mail.verification.introduction'))
            ->action(__('identity.mail.verification.action'), $url)
            ->line(__('identity.mail.verification.ignore')));

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(__DIR__.'/../Presentation/Routes/api.php');
    }
}
