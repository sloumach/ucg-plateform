<?php

namespace App\Modules\Reporting\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Reporting\Application\Contracts\DashboardIndicatorQueue;
use App\Modules\Reporting\Application\Validation\DashboardInput;
use App\Modules\Reporting\Domain\Contracts\DashboardIndicatorRepository;
use App\Modules\Reporting\Domain\Models\DashboardIndicator;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class DashboardIndicatorService
{
    public const ENGINE = 'rep-002.v1';

    public const MAX_INDICATORS = 64;

    public const LIFETIME_SECONDS = 300;

    public const CARDS = ['active_people', 'unlinked_people', 'people_imports', 'expiring_contracts', 'overdue_deliverables', 'registration_due'];

    public function __construct(private DashboardService $dashboards, private DashboardIndicatorRepository $records,
        private TenantBusinessGuard $guard, private ConnectionInterface $database, private Encrypter $crypt,
        private Factory $validator, private DashboardIndicatorQueue $queue, private LoggerInterface $logger, private TenantAuditJournal $audit) {}

    public function enabled(): bool
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');

        return (bool) config('dashboard_indicators.processing_enabled', false) && in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function selector(TenantContext $context, string $card, array $input): array
    {
        $values = DashboardInput::validate($this->validator, $input, 'show');
        $definition = $this->dashboards->authorizeCard($context, $card, $values['view']);
        if (! in_array($card, self::CARDS, true)) {
            throw new ModelNotFoundException;
        }
        $from = $definition['period'] === 'snapshot' ? '2000-01-01' : $values['from'];
        $to = $definition['period'] === 'snapshot' ? '2000-01-01' : $values['to'];
        $fingerprint = hash_hmac('sha256', json_encode([$context->organizationId, $context->actorUserId, $card, $values['view'], $from, $to, $context->timezone, self::ENGINE], JSON_THROW_ON_ERROR), $this->crypt->getKey());

        return ['card' => $card, 'view' => $values['view'], 'from_date' => $from, 'to_date' => $to, 'timezone' => $context->timezone,
            'fingerprint' => $fingerprint, 'cache_key' => TenantResourceNamespace::key($context->organizationId, 'cache', 'indicator:'.$context->actorUserId.':'.$fingerprint)];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function read(TenantContext $context, string $card, array $input): array
    {
        $selector = $this->selector($context, $card, $input);
        $row = $this->enabled() ? $this->records->fingerprint($context->organizationId, $context->actorUserId, $selector['fingerprint']) : null;
        $base = ['checked_at' => now()->toISOString(), 'enabled' => $this->enabled(), 'engine_version' => self::ENGINE, 'id' => $row?->id, 'version' => $row?->version,
            'state' => $row === null ? 'missing' : $this->state($row), 'evaluated_at' => $row?->current?->evaluated_at->toISOString(),
            'expires_at' => $row?->current?->expires_at->toISOString(), 'revision' => $row?->current?->number, 'result' => null, 'history' => null];
        if ($row !== null) {
            if ($base['state'] === 'fresh') {
                $base['result'] = $row->current?->result;
            }
            $page = $this->records->history($context->organizationId, $row->id, (int) ($input['page'] ?? 1));
            $base['history'] = ['items' => array_map(fn ($revision) => ['number' => $revision->number, 'engine_version' => $revision->engine_version,
                'evaluated_at' => $revision->evaluated_at->toISOString(), 'expires_at' => $revision->expires_at->toISOString()], $page->items()),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]];
        }

        return $base;
    }

    /** @param array<string,mixed> $input */
    public function request(TenantContext $context, string $card, array $input): void
    {
        $allowed = ['view', 'from', 'to', 'confirm', 'version'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            $this->conflict('input');
        }
        $this->validator->make($input, ['confirm' => ['required', 'accepted'], 'version' => ['sometimes', 'uuid']])->validate();
        $selector = $this->selector($context, $card, array_intersect_key($input, array_flip(['view', 'from', 'to'])));
        if (! $this->enabled()) {
            $this->conflict('disabled');
        }
        $queued = false;
        $row = $this->database->transaction(function () use ($context, $card, $selector, $input, &$queued): DashboardIndicator {
            $this->guard->lock($context);
            $this->dashboards->authorizeCard($context, $card, $selector['view']);
            $row = $this->records->fingerprint($context->organizationId, $context->actorUserId, $selector['fingerprint']);
            if ($row !== null) {
                $row = $this->records->find($context->organizationId, $context->actorUserId, $row->id, true);
                if ($this->state($row) === 'fresh' || ($row->status === 'queued' && $row->updated_at?->gt(now()->subSeconds(self::LIFETIME_SECONDS)) === true)) {
                    return $row;
                }
                if (($input['version'] ?? null) !== $row->version) {
                    $this->conflict('stale');
                }
                if (($row->current->number ?? 0) >= 1000) {
                    $this->conflict('limit');
                }
            } elseif ($this->records->count($context->organizationId, $context->actorUserId) >= self::MAX_INDICATORS) {
                $this->conflict('limit');
            }
            $queued = true;

            $saved = $this->records->save($row, [...($row === null ? ['organization_id' => $context->organizationId, 'actor_user_id' => $context->actorUserId, ...$selector] : []),
                'status' => 'queued', 'version' => (string) Str::uuid(), 'failure_code' => null]);
            $this->record($context, $saved, 'requested');

            return $saved;
        }, 3);
        if ($queued) {
            try {
                $this->queue->dispatch($context->toJobContext(), $row->id, $row->version);
            } catch (Throwable $exception) {
                $this->fail($context->organizationId, $context->actorUserId, $row->id, $row->version);
                $this->logger->warning('reporting.dispatch_failed', ['tenant_id' => $context->organizationId, 'indicator_id' => $row->id, 'exception_type' => $exception::class]);
                $this->conflict('dispatch_failed');
            }
        }
    }

    public function state(DashboardIndicator $row): string
    {
        if ($row->status === 'queued') {
            return ($row->updated_at === null || $row->updated_at->lte(now()->subSeconds(self::LIFETIME_SECONDS))) ? 'stale' : 'queued';
        }
        if ($row->status === 'failed') {
            return 'failed';
        }
        $revision = $row->current;

        return $revision !== null && $revision->generation === $row->version && $revision->engine_version === self::ENGINE
            && $revision->expires_at->isFuture() && $revision->source_version === $this->records->generation($row->organization_id, $row->card) ? 'fresh' : 'stale';
    }

    public function fail(string $org, int $actor, string $id, string $version): void
    {
        try {
            $this->database->transaction(function () use ($org, $actor, $id, $version): void {
                $row = $this->records->find($org, $actor, $id, true);
                if ($row->version === $version && $row->status === 'queued') {
                    $this->records->save($row, ['status' => 'failed', 'failure_code' => 'INDICATOR_INTERRUPTED']);
                }
            }, 3);
        } catch (ModelNotFoundException) {
        }
    }

    public function record(TenantContext $context, DashboardIndicator $row, string $action): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId,
            AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId,
            AuditReason::Domain, $context->requestId, null, [], ['dashboard_indicator_id' => $row->id, 'dashboard_indicator_action' => $action]));
    }

    public function conflict(string $reason): never
    {
        throw new DomainConflictException(__('dashboards.indicator_'.$reason), 'DASHBOARD_INDICATOR_'.strtoupper($reason));
    }
}
