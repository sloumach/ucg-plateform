<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Modules\Partnerships\Application\Validation\PartnershipInput;
use App\Modules\Partnerships\Application\Validation\SponsorshipCampaignInput as Input;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipAgreementRepository;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipCampaignRepository;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SponsorshipDeliverableService
{
    public function __construct(private SponsorshipCampaignRepository $records, private SponsorshipAgreementRepository $agreementRecords,
        private SponsorshipCampaignService $campaigns, private SponsorshipAgreementService $agreements, private SponsorshipReferenceService $references,
        private SponsorshipCampaignProjection $projection, private TenantAuthorization $authorization, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,array<string,mixed>> */
    public function paginate(TenantContext $context, string $sponsor, string $agreement, string $campaign, array $filters): LengthAwarePaginator
    {
        $parent = $this->campaigns->find($context, $sponsor, $agreement, $campaign);
        $page = $this->records->deliverables($context->organizationId, $parent->id, PartnershipInput::validate($this->validator, $filters, Input::pageRules('deliverables')));
        $record = $this->agreements->find($context, $sponsor, $agreement);
        $page->setCollection(collect($this->projection->deliverables($context, $record, $parent, array_values($page->items()))));

        return $page;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function save(TenantContext $context, string $sponsor, string $agreement, string $campaign, array $input, ?string $id = null, ?string $ip = null): array
    {
        $this->agreements->authorizeWrite($context);
        $values = PartnershipInput::validate($this->validator, $input, Input::deliverableRules($id === null));
        if ($id !== null && array_diff(array_keys($values), ['version', 'agreement_version', 'campaign_version']) === []) {
            throw ValidationException::withMessages(['record' => __('partnerships.empty')]);
        }

        return $this->database->transaction(function () use ($context, $sponsor, $agreement, $campaign, $values, $id, $ip): array {
            [$parent,$owner,$record] = $this->lock($context, $sponsor, $agreement, $campaign, $values, $id);
            $this->agreements->assertEditable($parent);
            if ($record !== null && $record->status !== 'promised') {
                $this->campaigns->conflict('reopen');
            }
            $responsible = (int) ($values['responsible_user_id'] ?? $record?->responsible_user_id);
            if (! $this->references->responsibleEligible($context, $responsible)) {
                $this->campaigns->conflict('responsible');
            }
            $scope = $this->agreementRecords->exposure($context->organizationId, $agreement, $record === null ? $values['exposure_id'] : $record->exposure_id);
            if ($scope->status !== 'active') {
                $this->campaigns->conflict('scope');
            }
            $due = CarbonImmutable::parse($values['due_at'] ?? $record?->due_at)->utc();
            $this->campaigns->assertDue($context, $owner, $due);
            $attributes = $values;
            unset($attributes['version'],$attributes['agreement_version'],$attributes['campaign_version']);
            $attributes['title'] = trim(isset($values['title']) ? $values['title'] : ($record === null ? '' : $record->title));
            if (isset($attributes['exposure_id'])) {
                $attributes['exposure_id'] = strtolower($attributes['exposure_id']);
            }
            $from = $record?->status;
            $saved = $this->records->saveDeliverable($record, [...($record === null ? ['organization_id' => $context->organizationId, 'agreement_id' => $parent->id, 'campaign_id' => $owner->id,
                'status' => 'promised', 'proof_reference' => null, 'delivered_at' => null, 'delivered_by' => null, ...$this->clearValidation()] : []),
                ...$attributes, 'due_at' => $due, 'prepared_by' => $context->actorUserId, 'version' => (string) Str::uuid()]);
            $owner = $this->campaigns->touch($owner);
            $this->campaigns->record($context, $owner, $id === null ? 'promised' : 'updated', $from, $saved, ip: $ip);

            return $this->result($context, $parent, $owner, $saved);
        });
    }

    /** A delivery is a manual declaration. Only the separate validation checks current exposure approval.
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function deliver(TenantContext $context, string $sponsor, string $agreement, string $campaign, string $id, array $input, ?string $ip = null): array
    {
        $this->agreements->authorizeWrite($context);
        $values = PartnershipInput::validate($this->validator, $input, Input::deliveryRules());

        return $this->database->transaction(function () use ($context, $sponsor, $agreement, $campaign, $id, $values, $ip): array {
            [$parent,$owner,$record] = $this->lock($context, $sponsor, $agreement, $campaign, $values, $id);
            if ($record === null || $record->status !== 'promised') {
                $this->campaigns->conflict('transition');
            }
            if (! $this->references->responsibleEligible($context, $record->responsible_user_id)) {
                $this->campaigns->conflict('responsible');
            }
            $date = CarbonImmutable::parse($values['delivered_at'])->utc();
            $this->campaigns->assertDue($context, $owner, $date);
            $saved = $this->records->saveDeliverable($record, ['status' => 'delivered', 'proof_reference' => trim($values['proof_reference']),
                'delivered_at' => $date, 'delivered_by' => $context->actorUserId, 'version' => (string) Str::uuid()]);
            $owner = $this->campaigns->touch($owner);
            $this->campaigns->record($context, $owner, 'delivered', 'promised', $saved, $saved, $ip);

            return $this->result($context, $parent, $owner, $saved);
        });
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function transition(TenantContext $context, string $sponsor, string $agreement, string $campaign, string $id, array $input, ?string $ip = null): array
    {
        $this->agreements->authorizeWrite($context);
        $values = PartnershipInput::validate($this->validator, $input, Input::transitionRules());

        return $this->database->transaction(function () use ($context, $sponsor, $agreement, $campaign, $id, $values, $ip): array {
            [$parent,$owner,$record] = $this->lock($context, $sponsor, $agreement, $campaign, $values, $id);
            if ($record === null) {
                $this->campaigns->conflict('transition');
            }
            $target = $values['target_status'];
            $from = $record->status;
            $snapshot = clone $record;
            $allowed = match ($from) {
                'promised' => ['cancelled'],'delivered' => ['validated', 'promised', 'cancelled'],'validated' => ['promised'],default => []
            };
            if (! in_array($target, $allowed, true)) {
                $this->campaigns->conflict('transition');
            }
            $attributes = ['status' => $target, 'version' => (string) Str::uuid(), ...$this->clearValidation()];
            if (in_array($target, ['validated', 'promised'], true)) {
                $this->authorization->authorize($context, 'partnerships.validate');
            }
            if ($target === 'validated') {
                if (in_array($context->actorUserId, [$record->prepared_by, $record->responsible_user_id, $record->delivered_by], true)) {
                    $this->campaigns->conflict('independent');
                }
                $scope = $this->agreementRecords->exposure($context->organizationId, $agreement, $record->exposure_id);
                if (! $this->agreements->approvedCurrent($context, $parent) || ! ($this->references->eligibility($context, [$scope])[$scope->id] ?? false)
                    || ! $this->references->responsibleEligible($context, $record->responsible_user_id)
                    || ! $this->references->responsibleEligible($context, $owner->responsible_user_id)) {
                    $this->campaigns->conflict('authorization');
                }
                $attributes = [...$attributes, 'validated_by' => $context->actorUserId, 'validated_at' => now(),
                    'validated_agreement_version' => $parent->version, 'validated_exposure_version' => $scope->version];
            }
            if ($target === 'promised') {
                $attributes = [...$attributes, 'proof_reference' => null, 'delivered_at' => null, 'delivered_by' => null, 'prepared_by' => $context->actorUserId];
            }
            $saved = $this->records->saveDeliverable($record, $attributes);
            $owner = $this->campaigns->touch($owner);
            $this->campaigns->record($context, $owner, $target === 'promised' ? 'reopened' : $target, $from, $saved, $snapshot, $ip);

            return $this->result($context, $parent, $owner, $saved);
        });
    }

    /** @param array<string,mixed> $values
     * @return array{SponsorshipAgreement,SponsorshipCampaign,SponsorshipDeliverable|null} */
    private function lock(TenantContext $context, string $sponsor, string $agreement, string $campaign, array $values, ?string $id): array
    {
        $parent = $this->agreements->lock($context, $sponsor, $agreement, $values['agreement_version']);
        if ($parent->status === 'archived') {
            $this->campaigns->conflict('frozen');
        }
        $owner = $this->campaigns->lockCampaign($context, $parent, $campaign, $values['campaign_version']);
        if ($owner->starts_on->format('Y-m-d') < $parent->starts_on->format('Y-m-d') || $owner->ends_on->format('Y-m-d') > $parent->ends_on->format('Y-m-d')) {
            $this->campaigns->conflict('period');
        }
        $record = $id === null ? null : $this->records->deliverable($context->organizationId, $owner->id, $id);
        if ($record !== null) {
            $this->campaigns->assertVersion($record->version, $values['version']);
        }

        return [$parent, $owner, $record];
    }

    /**
     * @return array<string,mixed> */
    private function clearValidation(): array
    {
        return ['validated_at' => null, 'validated_by' => null, 'validated_agreement_version' => null, 'validated_exposure_version' => null];
    }

    /**
     * @return array<string,mixed> */
    private function result(TenantContext $context, SponsorshipAgreement $agreement, SponsorshipCampaign $campaign, SponsorshipDeliverable $record): array
    {
        return [...$this->projection->deliverables($context, $agreement, $campaign, [$record])[0], 'campaign_version' => $campaign->version, 'agreement_version' => $agreement->version];
    }

    /** @param array<string,mixed> $filters
     * @return array{page:LengthAwarePaginator<int,array<string,mixed>>,summary:array<string,int>} */
    public function dashboard(TenantContext $context, array $filters): array
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $values = PartnershipInput::validate($this->validator, $filters, Input::pageRules('dashboard'));
        $now = CarbonImmutable::now()->utc()->toDateTimeString();
        $page = $this->records->overdue($context->organizationId, $now, $values);
        $page->setCollection(collect($this->projection->dashboard($context, array_values($page->items()))));

        return ['page' => $page, 'summary' => $this->records->summary($context->organizationId, $now)];
    }
}
