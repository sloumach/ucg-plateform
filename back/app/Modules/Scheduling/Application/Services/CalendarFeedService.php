<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\Scheduling\Application\Data\CalendarFeedSecret;
use App\Modules\Scheduling\Application\Data\CalendarSubscription;
use App\Modules\Scheduling\Application\Validation\CalendarFeedInput;
use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\CalendarIcs;
use App\Modules\Scheduling\Domain\Contracts\CalendarFeedRepository;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Scheduling\Domain\Models\CalendarFeed;
use App\Modules\Teams\Application\Contracts\CalendarTeamsDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantResourceLimits;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;

final readonly class CalendarFeedService
{
    public function __construct(private CalendarFeedRepository $feeds, private CalendarRepository $calendar,
        private CalendarPeopleDirectory $people, private CalendarTeamsDirectory $teams, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantNotificationRecipientDirectory $recipients, private TenantAuditJournal $audit,
        private TenantResourceLimits $limits, private Factory $validator, private ConnectionInterface $database,
        private Repository $config, private UrlGenerator $urls, private CalendarIcs $ics) {}

    /** @param array<string,mixed> $input */
    public function personal(TenantContext $context, array $input): ?CalendarFeed
    {
        $this->authorize($context);
        CalendarFeedInput::validate($this->validator, $input, 'show');

        return $this->feeds->personal($context->organizationId, $context->actorUserId);
    }

    /** @param array<string,mixed> $input */
    public function issue(TenantContext $context, array $input, ?string $ip): CalendarFeedSecret
    {
        $this->authorize($context);
        $values = CalendarFeedInput::validate($this->validator, $input, 'store');

        return $this->database->transaction(function () use ($context, $values, $ip): CalendarFeedSecret {
            $this->guard->lock($context, $context->actorUserId);
            $this->authorize($context);
            $feed = $this->feeds->personal($context->organizationId, $context->actorUserId, true);
            $this->version($feed, $values['expected_version']);
            $action = $feed === null ? 'feed_created' : 'feed_rotated';
            $token = bin2hex(random_bytes(32));
            $feed = $this->feeds->save($feed, ['organization_id' => $context->organizationId, 'user_id' => $context->actorUserId,
                'token_hash' => hash('sha256', $token), 'before_days' => $values['before_days'], 'after_days' => $values['after_days'],
                'expires_at' => now()->addDays($values['expires_in_days']), 'revoked_at' => null, 'version' => (string) Str::uuid()]);
            $url = $this->url($feed, $token);
            $this->audit($context, $feed, $action, $ip);

            return new CalendarFeedSecret($feed, $url);
        });
    }

    /** @param array<string,mixed> $input */
    public function revoke(TenantContext $context, array $input, ?string $ip): CalendarFeed
    {
        $this->authorize($context);
        $values = CalendarFeedInput::validate($this->validator, $input, 'revoke');

        return $this->database->transaction(function () use ($context, $values, $ip): CalendarFeed {
            $this->guard->lock($context, $context->actorUserId);
            $this->authorize($context);
            $feed = $this->feeds->personal($context->organizationId, $context->actorUserId, true) ?? throw new ModelNotFoundException;
            $this->version($feed, $values['version']);
            if ($feed->revoked_at === null) {
                $feed = $this->feeds->save($feed, ['token_hash' => null, 'revoked_at' => now(), 'version' => (string) Str::uuid()]);
                $this->audit($context, $feed, 'feed_revoked', $ip);
            }

            return $feed;
        });
    }

    public function open(string $organizationId, string $feedId, #[SensitiveParameter] string $token, string $requestId): CalendarSubscription
    {
        $feed = $this->feeds->find(strtolower($organizationId), $feedId);
        $hash = hash('sha256', $token);
        $this->assertToken($feed, $hash);
        $context = new TenantContext($feed->organization_id, $feed->user_id, '', 'UTC', 'fr', $requestId);
        $this->authorize($context);

        return new CalendarSubscription($context, $feed->id, $hash);
    }

    public function render(CalendarSubscription $subscription): string
    {
        $context = $subscription->context;

        return $this->database->transaction(function () use ($subscription, $context): string {
            // Same account -> organization -> feed lock order as rotation/revocation.
            $this->guard->lock($context, $context->actorUserId);
            $feed = $this->feeds->find($context->organizationId, $subscription->feedId, true);
            $this->assertToken($feed, $subscription->tokenHash);
            $this->authorize($context);
            $viewer = $this->people->viewer($context);
            $audience = new CalendarAudience($context->actorUserId, $viewer['person_id'], $this->teams->currentIds($context));
            $today = CarbonImmutable::now('UTC')->startOfDay();
            $events = $this->calendar->feed($context->organizationId, $audience, $today->subDays($feed->before_days), $today->addDays($feed->after_days));
            $this->limits->assertExport($context, count($events));

            return $this->ics->render($feed->id, $events);
        });
    }

    private function authorize(TenantContext $context): void
    {
        foreach (['calendar.read', 'calendar.export'] as $permission) {
            $this->authorization->authorize($context, $permission);
        }
        if ($this->recipients->email($context->organizationId, $context->actorUserId, $context->requestId, ['calendar.read', 'calendar.export']) === null) {
            throw new ModelNotFoundException;
        }
    }

    private function assertToken(CalendarFeed $feed, string $hash): void
    {
        if ($feed->token_hash === null || ! hash_equals($feed->token_hash, $hash) || $feed->revoked_at !== null || $feed->expires_at->lte(now())) {
            throw new ModelNotFoundException;
        }
    }

    private function version(?CalendarFeed $feed, ?string $version): void
    {
        if (($feed?->version) !== ($version === null ? null : strtolower($version))) {
            throw new DomainConflictException(__('calendar.feed_stale'), 'CALENDAR_FEED_STALE');
        }
    }

    private function url(CalendarFeed $feed, #[SensitiveParameter] string $token): string
    {
        $base = rtrim((string) $this->config->get('app.url'), '/');
        $parts = parse_url($base);
        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (! str_starts_with($base, 'https://') && ! (in_array($this->config->get('app.env'), ['local', 'testing'], true) && str_starts_with($base, 'http://')))) {
            throw new LogicException('A trusted HTTPS APP_URL is required for calendar subscriptions.');
        }

        return $base.$this->urls->route('api.v1.calendar.subscription', ['tenant' => $feed->organization_id, 'feed' => $feed->id], false).'?token='.$token;
    }

    private function audit(TenantContext $context, CalendarFeed $feed, string $action, ?string $ip): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
            $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['calendar_reference_id' => $feed->id, 'calendar_action' => $action]));
    }
}
