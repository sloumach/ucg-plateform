<?php

namespace App\Modules\Reporting\Application\Services;

use App\Exceptions\ArtifactStorageException;
use App\Exceptions\DomainConflictException;
use App\Modules\Reporting\Application\Contracts\ReportExportQueue;
use App\Modules\Reporting\Application\Validation\DashboardInput;
use App\Modules\Reporting\Domain\Contracts\DashboardIndicatorRepository;
use App\Modules\Reporting\Domain\Contracts\ReportExportRepository;
use App\Modules\Reporting\Domain\DashboardCatalogue;
use App\Modules\Reporting\Domain\Models\ReportExport;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantSensitiveAccessJournal;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use App\Support\Audit\SensitiveAccessAction;
use App\Support\Audit\SensitiveAccessData;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ReportExportService
{
    public const MAX_ROWS = 500;

    public const MAX_BYTES = 8_388_608;

    public const MAX_ACTIVE = 10;

    public const LIFETIME_MINUTES = 60;

    // Only organization-wide projections with transactional source invalidation.
    public const CARDS = ['active_people', 'unlinked_people', 'people_imports', 'expiring_contracts', 'overdue_deliverables', 'registration_due'];

    public function __construct(private DashboardService $dashboards, private DashboardCatalogue $catalogue, private ReportExportRepository $records,
        private DashboardIndicatorRepository $sources, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private ConnectionInterface $database, private Factory $validator,
        private ReportExportQueue $queue, private TenantArtifacts $artifacts, private Encrypter $crypt,
        private TenantAuditJournal $audit, private TenantSensitiveAccessJournal $sensitive, private LoggerInterface $logger) {}

    public function enabled(): bool
    {
        return (bool) config('report_exports.processing_enabled', false)
            && in_array(config('queue.connections.'.config('queue.default').'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true);
    }

    public function authorize(TenantContext $context, ?ReportExport $row = null): void
    {
        $this->authorization->authorize($context, 'reporting.export');
        if ($row !== null) {
            $context->assertOrganization($row->organization_id);
            if ($context->actorUserId !== $row->actor_user_id) {
                throw new ModelNotFoundException;
            }
            $this->dashboards->authorizeCard($context, $row->card, $row->view);
        }
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function request(TenantContext $context, array $input): array
    {
        $this->authorize($context);
        if (array_diff(array_keys($input), ['card', 'view', 'from', 'to', 'confirm', 'request_key']) !== []) {
            throw ValidationException::withMessages(['export' => __('dashboards.closed')]);
        }
        $v = $this->validator->make($input, ['card' => ['required', Rule::in(self::CARDS)], 'request_key' => ['required', 'uuid'],
            'confirm' => ['required', 'accepted'], ...array_diff_key(DashboardInput::rules('show'), ['page' => true])])->validate();
        DashboardInput::validate($this->validator, array_intersect_key($v, array_flip(['view', 'from', 'to'])), 'show');
        if (! $this->enabled()) {
            $this->conflict('disabled');
        }
        $queued = false;
        $row = $this->database->transaction(function () use ($context, $v, &$queued): ReportExport {
            $this->guard->lock($context);
            $this->dashboards->authorizeCard($context, $v['card'], $v['view']);
            $row = $this->records->byKey($context->organizationId, $context->actorUserId, strtolower($v['request_key']));
            if ($row !== null) {
                if ([$row->card, $row->view, $row->from_date, $row->to_date, $row->timezone] !== [$v['card'], $v['view'], $v['from'], $v['to'], $context->timezone]) {
                    $this->conflict('input');
                }

                return $row;
            }
            if ($this->records->activeCount($context->organizationId) >= self::MAX_ACTIVE) {
                $this->conflict('limit');
            }
            $queued = true;
            $row = $this->records->save(null, ['organization_id' => $context->organizationId, 'actor_user_id' => $context->actorUserId,
                'request_key' => strtolower($v['request_key']), 'card' => $v['card'], 'view' => $v['view'], 'timezone' => $context->timezone,
                'from_date' => $v['from'], 'to_date' => $v['to'], 'status' => 'queued', 'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES)]);
            $this->record($context, $row, 'requested');

            return $row;
        }, 3);
        if ($queued) {
            try {
                $this->queue->dispatch($context->toJobContext(), $row->id);
            } catch (Throwable $exception) {
                $this->fail($context->organizationId, $context->actorUserId, $row->id);
                $this->logger->warning('reporting.export_dispatch_failed', ['tenant_id' => $context->organizationId, 'export_id' => $row->id, 'exception_type' => $exception::class]);
                $this->conflict('dispatch_failed');
            }
        }

        return $this->describe($row);
    }

    /** @return array<string,mixed> */
    public function index(TenantContext $context, int $page): array
    {
        $this->authorize($context);
        $allowed = [];
        foreach ($this->dashboards->index($context, [])['views'] as $view) {
            foreach ($view['cards'] as $card) {
                if (in_array($card['id'], self::CARDS, true)) {
                    $allowed[] = ['card' => $card['id'], 'view' => $view['id']];
                }
            }
        }
        $rows = $this->records->paginate($context->organizationId, $context->actorUserId, $page, $allowed);

        return ['checked_at' => now()->toISOString(), 'enabled' => $this->enabled(), 'max_rows' => self::MAX_ROWS, 'lifetime_minutes' => self::LIFETIME_MINUTES,
            'items' => array_map(fn (ReportExport $row) => $this->describe($row), $rows->items()),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]];
    }

    /** @return array<string,mixed> */
    public function show(TenantContext $context, string $id): array
    {
        $this->authorize($context);
        $row = $this->records->find($context->organizationId, $context->actorUserId, $id);
        $this->authorize($context, $row);

        return $this->describe($row);
    }

    public function download(TenantContext $context, string $id): string
    {
        return $this->database->transaction(function () use ($context, $id): string {
            $this->guard->lock($context);
            $row = $this->records->find($context->organizationId, $context->actorUserId, $id, true);
            $this->authorize($context, $row);
            $this->assertLive($row, $context);
            if (! $this->enabled() || $row->status !== 'completed' || $row->artifact_id === null) {
                $this->conflict('unavailable');
            }
            // A changed source invalidates the snapshot; no expensive regeneration in HTTP.
            if ($row->source_version !== $this->sources->generation($context->organizationId, $row->card, true)) {
                $this->conflict('stale');
            }
            try {
                $contents = $this->crypt->decryptString($this->artifacts->read($context, $row->artifact_id));
            } catch (ArtifactStorageException|DecryptException $exception) {
                $this->logger->warning('reporting.export_read_failed', ['tenant_id' => $context->organizationId, 'export_id' => $row->id, 'exception_type' => $exception::class]);
                $this->conflict('unavailable');
            }
            if (strlen($contents) > self::MAX_BYTES || ! hash_equals((string) $row->digest, hash('sha256', $contents))) {
                $this->conflict('unavailable');
            }
            $this->assertLive($row, $context);
            $this->authorize($context, $row);
            $this->record($context, $row, 'downloaded');

            return $contents;
        });
    }

    public function assertLive(ReportExport $row, TenantContext $context): void
    {
        if ($row->expires_at->lessThanOrEqualTo(now())) {
            $this->conflict('expired');
        }
        if ($row->timezone !== $context->timezone) {
            $this->conflict('stale');
        }
    }

    public function fail(string $org, int $actor, string $id): void
    {
        try {
            $this->database->transaction(function () use ($org, $actor, $id): void {
                $row = $this->records->find($org, $actor, $id, true);
                if ($row->status === 'queued') {
                    $this->records->save($row, ['status' => 'failed']);
                }
            }, 3);
        } catch (ModelNotFoundException) {
        }
    }

    public function record(TenantContext $context, ReportExport $row, string $action): void
    {
        $this->sensitive->record($context->organizationId, new SensitiveAccessData($context->actorUserId,
            match ($action) {
                'requested' => SensitiveAccessAction::ExportRequested,
                'generated' => SensitiveAccessAction::ExportGenerated,
                'downloaded' => SensitiveAccessAction::ExportRead,
                default => throw new \InvalidArgumentException('Unknown export audit action.'),
            }, $row->id, $context->requestId));
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
            AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, null, [],
            ['report_export_id' => $row->id, 'report_export_action' => $action]));
    }

    /** @return array<string,mixed> */
    private function describe(ReportExport $row): array
    {
        return ['id' => $row->id, 'card' => $row->card, 'label' => __('dashboards.cards.'.$row->card.'.label'), 'period_kind' => $this->catalogue->find($row->card)['period'],
            'view' => $row->view, 'view_label' => __('dashboards.views.'.$row->view), 'from' => $row->from_date, 'to' => $row->to_date, 'timezone' => $row->timezone,
            'state' => $row->expires_at->lessThanOrEqualTo(now()) ? 'expired' : $row->status, 'row_count' => $row->row_count,
            'created_at' => $row->created_at?->toISOString(), 'generated_at' => $row->generated_at?->toISOString(), 'expires_at' => $row->expires_at->toISOString()];
    }

    public function conflict(string $reason): never
    {
        throw new DomainConflictException(__('report_exports.'.$reason), 'REPORT_EXPORT_'.strtoupper($reason));
    }
}
