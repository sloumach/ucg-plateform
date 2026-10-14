<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Teams\Application\Contracts\ExternalOpponents;
use App\Modules\Teams\Application\Validation\ExternalOpponentInput;
use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Domain\Contracts\ExternalOpponentRepository;
use App\Modules\Teams\Domain\Models\ExternalOpponentSnapshot;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;

final readonly class ExternalOpponentSnapshotService implements ExternalOpponents
{
    public function __construct(private ExternalOpponentRepository $opponents, private EffectiveGameCatalogue $games,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ExternalOpponentSnapshot>
     */
    public function paginate(TenantContext $context, string $opponentId, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = ExternalOpponentInput::validate($this->validator, $filters, GameCatalogueInput::pageRules(false));
        $opponent = $this->opponents->find($context->organizationId, $opponentId);

        return $this->opponents->snapshots($context->organizationId, $opponent->id, $values);
    }

    /** @param array<string, mixed> $input */
    public function store(TenantContext $context, string $opponentId, array $input, ?string $ipAddress = null): ExternalOpponentSnapshot
    {
        $values = ExternalOpponentInput::validate($this->validator, $input, ExternalOpponentInput::captureRules());
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $opponentId, $values, $ipAddress): ExternalOpponentSnapshot {
            $this->guard->lock($context);
            $this->authorize($context);
            $opponent = $this->opponents->find($context->organizationId, $opponentId, true);
            if ($opponent->version !== $values['version']) {
                throw new DomainConflictException(__('external_opponents.stale'), 'EXTERNAL_OPPONENT_VERSION_STALE');
            }
            if ($opponent->status === 'archived') {
                throw new DomainConflictException(__('external_opponents.archived'), 'EXTERNAL_OPPONENT_ARCHIVED');
            }
            if ($opponent->game_id !== null) {
                $this->games->assertGameAvailable($context->organizationId, $opponent->game_id);
            }
            if ($this->opponents->snapshotCount($context->organizationId, $opponent->id) >= ExternalOpponentInput::MAX_SNAPSHOTS) {
                throw new DomainConflictException(__('external_opponents.snapshot_limit'), 'EXTERNAL_OPPONENT_SNAPSHOT_LIMIT_REACHED');
            }
            // Copy only the declared public facts; never inspect another tenant or its roster.
            $snapshot = $this->opponents->capture(['organization_id' => $context->organizationId,
                'opponent_id' => $opponent->id, 'opponent_version' => $opponent->version, 'context_label' => trim($values['context_label']),
                'kind' => $opponent->kind, 'name' => $opponent->name, 'short_name' => $opponent->short_name,
                'organization_name' => $opponent->organization_name, 'game_key' => $opponent->game?->key, 'game_name' => $opponent->game?->name,
                'source_label' => $opponent->source_label, 'source_url' => $opponent->source_url, 'observed_at' => $opponent->observed_at,
                'captured_at' => CarbonImmutable::now(), 'created_by' => $context->actorUserId]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress,
                [], ['external_opponent_id' => $opponent->id, 'opponent_snapshot_id' => $snapshot->id, 'version' => $opponent->version]));

            return $snapshot;
        });
    }

    public function capture(TenantContext $context, string $opponentId, string $version, string $contextLabel, bool $confirmPublicData): array
    {
        return $this->store($context, $opponentId, ['version' => $version, 'context_label' => $contextLabel,
            'confirm_public_data' => $confirmPublicData])->projection();
    }

    public function snapshot(TenantContext $context, string $snapshotId): array
    {
        $this->authorization->authorize($context, 'sports.read');

        return $this->opponents->findSnapshot($context->organizationId, $snapshotId)->projection();
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'teams.manage');
    }
}
