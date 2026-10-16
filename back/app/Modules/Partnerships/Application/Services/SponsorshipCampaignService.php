<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Partnerships\Application\Validation\PartnershipInput;
use App\Modules\Partnerships\Application\Validation\SponsorshipCampaignInput as Input;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipCampaignRepository;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaignEvent;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SponsorshipCampaignService
{
    public function __construct(private SponsorshipCampaignRepository $records, private SponsorshipAgreementService $agreements,
        private SponsorshipReferenceService $references, private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,SponsorshipCampaign> */
    public function paginate(TenantContext $context, string $sponsor, string $agreement, array $filters): LengthAwarePaginator
    {
        $this->agreements->find($context, $sponsor, $agreement);

        return $this->records->campaigns($context->organizationId, $agreement, PartnershipInput::validate($this->validator, $filters, Input::pageRules()));
    }

    public function find(TenantContext $context, string $sponsor, string $agreement, string $id): SponsorshipCampaign
    {
        $this->agreements->find($context, $sponsor, $agreement);

        return $this->records->campaign($context->organizationId, $agreement, $id);
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,SponsorshipCampaignEvent> */
    public function history(TenantContext $context, string $sponsor, string $agreement, string $id, array $filters): LengthAwarePaginator
    {
        $record = $this->find($context, $sponsor, $agreement, $id);

        return $this->records->history($context->organizationId, $record->id, PartnershipInput::validate($this->validator, $filters, Input::pageRules('history')));
    }

    /** @param array<string,mixed> $input */
    public function save(TenantContext $context, string $sponsor, string $agreement, array $input, ?string $id = null, ?string $ip = null): SponsorshipCampaign
    {
        $this->agreements->authorizeWrite($context);
        $values = PartnershipInput::validate($this->validator, $input, Input::campaignRules($id === null));
        if ($id !== null && array_diff(array_keys($values), ['version', 'agreement_version']) === []) {
            throw ValidationException::withMessages(['record' => __('partnerships.empty')]);
        }

        return $this->database->transaction(function () use ($context, $sponsor, $agreement, $values, $id, $ip): SponsorshipCampaign {
            $parent = $this->agreements->lock($context, $sponsor, $agreement, $values['agreement_version']);
            $record = $id === null ? null : $this->lockCampaign($context, $parent, $id, $values['version']);
            $attributes = $values;
            unset($attributes['version'],$attributes['agreement_version']);
            $archiving = ($values['status'] ?? null) === 'archived';
            if ($archiving) {
                if ($record === null) {
                    $this->conflict('transition');
                }
                if (count($attributes) !== 1) {
                    throw ValidationException::withMessages(['status' => __('campaigns.frozen')]);
                }
                if ($this->records->hasPending($context->organizationId, $record->id)) {
                    $this->conflict('pending');
                }
            } else {
                $this->agreements->assertEditable($parent);
                $start = $values['starts_on'] ?? $record?->starts_on->format('Y-m-d');
                $end = $values['ends_on'] ?? $record?->ends_on->format('Y-m-d');
                if ($start === null || $end === null || $start > $end || $start < $parent->starts_on->format('Y-m-d') || $end > $parent->ends_on->format('Y-m-d')) {
                    $this->conflict('period');
                }
                if ($record !== null && $this->records->hasDueOutside($context->organizationId, $record->id,
                    CarbonImmutable::parse($start, $context->timezone)->utc()->toDateTimeString(),
                    CarbonImmutable::parse($end, $context->timezone)->addDay()->utc()->toDateTimeString())) {
                    $this->conflict('period');
                }
                $responsible = (int) ($values['responsible_user_id'] ?? $record?->responsible_user_id);
                if (! $this->references->responsibleEligible($context, $responsible)) {
                    $this->conflict('responsible');
                }
                if (isset($attributes['title'])) {
                    $attributes['title'] = trim($attributes['title']);
                }
            }
            $from = $record?->status;
            $saved = $this->records->saveCampaign($record, [...($record === null ? ['organization_id' => $context->organizationId, 'agreement_id' => $parent->id, 'status' => 'active', 'created_by' => $context->actorUserId] : []),
                ...$attributes, 'version' => (string) Str::uuid()]);
            $this->record($context, $saved, $archiving ? 'campaign_archived' : ($id === null ? 'campaign_created' : 'campaign_updated'), $from, null, null, $ip);

            return $saved;
        });
    }

    public function lockCampaign(TenantContext $context, SponsorshipAgreement $agreement, string $id, string $version): SponsorshipCampaign
    {
        $record = $this->records->campaign($context->organizationId, $agreement->id, $id, true);
        $this->assertVersion($record->version, $version);
        if ($record->status === 'archived') {
            $this->conflict('frozen');
        }

        return $record;
    }

    public function touch(SponsorshipCampaign $record): SponsorshipCampaign
    {
        return $this->records->saveCampaign($record, ['version' => (string) Str::uuid()]);
    }

    public function assertVersion(string $actual, string $expected): void
    {
        if ($actual !== strtolower($expected)) {
            $this->conflict('stale');
        }
    }

    public function assertDue(TenantContext $context, SponsorshipCampaign $campaign, CarbonImmutable $date): void
    {
        $day = $date->setTimezone($context->timezone)->format('Y-m-d');
        if ($day < $campaign->starts_on->format('Y-m-d') || $day > $campaign->ends_on->format('Y-m-d')) {
            $this->conflict('due');
        }
    }

    public function record(TenantContext $context, SponsorshipCampaign $campaign, string $action, ?string $from,
        ?SponsorshipDeliverable $deliverable = null, ?SponsorshipDeliverable $snapshot = null, ?string $ip = null): void
    {
        $this->records->event(['organization_id' => $context->organizationId, 'campaign_id' => $campaign->id, 'deliverable_id' => $deliverable?->id,
            'action' => $action, 'from_status' => $from, 'to_status' => $deliverable === null ? $campaign->status : $deliverable->status, 'record_version' => $deliverable === null ? $campaign->version : $deliverable->version,
            'actor_user_id' => $context->actorUserId, 'occurred_at' => now(),
            'delivery_snapshot' => $snapshot?->proof_reference === null ? null : ['proof_reference' => $snapshot->proof_reference, 'delivered_at' => $snapshot->delivered_at?->toIso8601String(),
                'delivered_by' => $snapshot->delivered_by, 'validated_by' => $snapshot->validated_by, 'validated_at' => $snapshot->validated_at?->toIso8601String()]]);
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
            $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['sponsorship_campaign_id' => $campaign->id, 'sponsorship_campaign_action' => $action]));
    }

    public function conflict(string $key): never
    {
        throw new DomainConflictException(__('campaigns.'.$key), 'SPONSORSHIP_CAMPAIGN_'.strtoupper($key));
    }
}
