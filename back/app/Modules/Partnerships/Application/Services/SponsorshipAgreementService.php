<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Partnerships\Application\Validation\PartnershipInput;
use App\Modules\Partnerships\Application\Validation\SponsorshipAgreementInput as Input;
use App\Modules\Partnerships\Domain\Contracts\SponsorRepository;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipAgreementRepository;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreementEvent;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SponsorshipAgreementService
{
    public function __construct(private SponsorshipAgreementRepository $records, private SponsorRepository $sponsors,
        private SponsorshipReferenceService $references, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, SponsorshipAgreement> */
    public function paginate(TenantContext $context, string $sponsor, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $this->sponsors->find($context->organizationId, $sponsor);

        return $this->records->paginate($context->organizationId, $sponsor, PartnershipInput::validate($this->validator, $filters, Input::pageRules()));
    }

    public function find(TenantContext $context, string $sponsor, string $id): SponsorshipAgreement
    {
        $this->authorization->authorize($context, 'partnerships.read');

        return $this->records->find($context->organizationId, $sponsor, $id);
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, SponsorshipAgreementEvent> */
    public function history(TenantContext $context, string $sponsor, string $id, array $filters): LengthAwarePaginator
    {
        $record = $this->find($context, $sponsor, $id);

        return $this->records->history($context->organizationId, $record->id, PartnershipInput::validate($this->validator, $filters, Input::pageRules('history')));
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, string $sponsor, array $input, ?string $id = null, ?string $ip = null): SponsorshipAgreement
    {
        $this->authorizeWrite($context);
        $values = PartnershipInput::validate($this->validator, $input, Input::saveRules($id === null));
        if ($id !== null && array_diff(array_keys($values), ['version']) === []) {
            throw ValidationException::withMessages(['record' => __('partnerships.empty')]);
        }
        try {
            return $this->database->transaction(function () use ($context, $sponsor, $values, $id, $ip): SponsorshipAgreement {
                $this->lockSponsor($context, $sponsor);
                $record = $id === null ? null : $this->records->find($context->organizationId, $sponsor, $id, true);
                if ($record !== null) {
                    $this->assertVersion($record, $values['version']);
                    $this->assertEditable($record);
                }
                $attributes = $values;
                unset($attributes['version']);
                $start = $values['starts_on'] ?? $record?->starts_on->format('Y-m-d');
                $end = $values['ends_on'] ?? $record?->ends_on->format('Y-m-d');
                if ($end < $start) {
                    throw ValidationException::withMessages(['ends_on' => __('sponsorships.dates')]);
                }
                $responsible = (int) ($values['responsible_user_id'] ?? $record?->responsible_user_id);
                if (! $this->references->responsibleEligible($context, $responsible)) {
                    $this->conflict('responsible', 'SPONSORSHIP_RESPONSIBLE_UNAVAILABLE');
                }
                if (isset($values['opportunity_id'])) {
                    $opportunity = $this->sponsors->findRelated($context->organizationId, $sponsor, 'opportunities', $values['opportunity_id']);
                    if ($opportunity->status === 'archived') {
                        $this->conflict('frozen', 'SPONSORSHIP_OPPORTUNITY_UNAVAILABLE');
                    }
                    $attributes['opportunity_id'] = $opportunity->id;
                }
                if (array_key_exists('contract_id', $values)) {
                    $attributes['contract_id'] = $values['contract_id'] === null ? null : strtolower($values['contract_id']);
                    $attributes['contract_version'] = $values['contract_id'] === null ? null : $this->references->contracts->bind($context, $values['contract_id']);
                }
                if (isset($attributes['title'])) {
                    $attributes['title'] = trim($attributes['title']);
                }
                $from = $record?->status;
                $saved = $this->records->save($record, [...($record === null ? ['organization_id' => $context->organizationId, 'sponsor_id' => strtolower($sponsor),
                    'opportunity_id' => null, 'contract_id' => null, 'contract_version' => null] : []), ...$attributes, ...$this->draftAttributes($context)]);
                $this->record($context, $saved, $id === null ? 'created' : 'updated', $from, $ip);

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            $this->conflict('duplicate', 'SPONSORSHIP_REFERENCE_EXISTS');
        }
    }

    /** @param array<string, mixed> $input */
    public function transition(TenantContext $context, string $sponsor, string $id, array $input, ?string $ip = null): SponsorshipAgreement
    {
        $this->authorizeWrite($context);
        $values = PartnershipInput::validate($this->validator, $input, Input::transitionRules());

        return $this->database->transaction(function () use ($context, $sponsor, $id, $values, $ip): SponsorshipAgreement {
            $record = $this->lock($context, $sponsor, $id, $values['version']);
            $target = $values['target_status'];
            $allowed = match ($record->status) {
                'draft' => ['approved', 'archived'], 'approved' => ['ended'], 'ended' => ['archived'], default => []
            };
            if (! in_array($target, $allowed, true)) {
                $this->conflict('transition', 'SPONSORSHIP_TRANSITION_INVALID');
            }
            $attributes = ['status' => $target, 'version' => (string) Str::uuid()];
            if (in_array($target, ['approved', 'ended'], true)) {
                $this->authorization->authorize($context, 'partnerships.validate');
            }
            if ($target === 'approved') {
                $this->authorization->authorize($context, 'financial.read');
                $this->authorization->authorize($context, 'financial.validate');
                $terms = $this->records->terms($context->organizationId, $record->id);
                if ($terms === null) {
                    $this->conflict('terms_required', 'SPONSORSHIP_FINANCIAL_TERMS_REQUIRED');
                }
                if (in_array($context->actorUserId, [$record->prepared_by, $terms->prepared_by], true)) {
                    $this->conflict('self_approval', 'SPONSORSHIP_SELF_APPROVAL_FORBIDDEN');
                }
                if (! $this->references->responsibleEligible($context, $record->responsible_user_id)) {
                    $this->conflict('responsible', 'SPONSORSHIP_RESPONSIBLE_UNAVAILABLE');
                }
                $scopes = $this->records->activeExposures($context->organizationId, $record->id);
                if ($scopes === [] || count($scopes) > Input::MAX_EXPOSURES || in_array(false, $this->references->eligibility($context, $scopes), true)) {
                    $this->conflict('scopes_required', 'SPONSORSHIP_EXPOSURES_UNAVAILABLE');
                }
                $exclusive = array_values(array_filter(array_map(fn ($scope): ?string => $scope->exclusivity_key, $scopes)));
                if ($this->records->hasExclusiveConflict($record, $exclusive)) {
                    $this->conflict('exclusive', 'SPONSORSHIP_EXCLUSIVITY_CONFLICT');
                }
                if ($record->contract_id !== null && ! $this->references->contracts->isCurrent($context, $record->contract_id, $record->contract_version ?? '')) {
                    $this->conflict('contract_unavailable', 'SPONSORSHIP_CONTRACT_UNAVAILABLE');
                }
                $attributes = [...$attributes, 'approved_by' => $context->actorUserId, 'approved_at' => now(), 'approved_financial_version' => $terms->version];
            }
            $from = $record->status;
            $saved = $this->records->save($record, $attributes);
            $this->record($context, $saved, $target, $from, $ip);

            return $saved;
        });
    }

    public function authorizeWrite(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $this->authorization->authorize($context, 'partnerships.write');
    }

    private function lockSponsor(TenantContext $context, string $id): void
    {
        $this->guard->lock($context);
        $this->authorizeWrite($context);
        $sponsor = $this->sponsors->find($context->organizationId, $id, true);
        if ($sponsor->status === 'archived') {
            $this->conflict('frozen', 'SPONSORSHIP_FROZEN');
        }
    }

    public function lock(TenantContext $context, string $sponsor, string $id, string $version): SponsorshipAgreement
    {
        $this->lockSponsor($context, $sponsor);
        $record = $this->records->find($context->organizationId, $sponsor, $id, true);
        $this->assertVersion($record, $version);

        return $record;
    }

    public function assertEditable(SponsorshipAgreement $record): void
    {
        if (in_array($record->status, ['ended', 'archived'], true)) {
            $this->conflict('frozen', 'SPONSORSHIP_FROZEN');
        }
    }

    private function assertVersion(SponsorshipAgreement $record, string $version): void
    {
        if ($record->version !== strtolower($version)) {
            $this->conflict('stale', 'SPONSORSHIP_VERSION_STALE');
        }
    }

    /** Every material change invalidates the entire prior approval, including financial and exposure changes.
     * @return array<string, mixed> */
    public function draftAttributes(TenantContext $context): array
    {
        return ['status' => 'draft', 'version' => (string) Str::uuid(), 'prepared_by' => $context->actorUserId,
            'approved_by' => null, 'approved_at' => null, 'approved_financial_version' => null];
    }

    public function invalidate(TenantContext $context, SponsorshipAgreement $record): SponsorshipAgreement
    {
        $this->assertEditable($record);

        return $this->records->save($record, $this->draftAttributes($context));
    }

    public function record(TenantContext $context, SponsorshipAgreement $record, string $action, ?string $from, ?string $ip = null): void
    {
        $this->records->event(['organization_id' => $context->organizationId, 'agreement_id' => $record->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $record->status, 'agreement_version' => $record->version,
            'actor_user_id' => $context->actorUserId, 'occurred_at' => now()]);
        if ($action !== 'financial_viewed') {
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [],
                ['sponsorship_agreement_id' => $record->id, 'sponsorship_action' => $action, 'sponsorship_status' => $record->status]));
        }
    }

    public function approvedCurrent(TenantContext $context, SponsorshipAgreement $record): bool
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $today = CarbonImmutable::now($context->timezone)->format('Y-m-d');

        return $record->status === 'approved' && $record->starts_on->format('Y-m-d') <= $today && $record->ends_on->format('Y-m-d') >= $today
            && $this->sponsors->find($context->organizationId, $record->sponsor_id)->status !== 'archived'
            && $this->references->responsibleEligible($context, $record->responsible_user_id)
            && $record->approved_by !== null && $this->references->approverEligible($context, $record->approved_by)
            && ($record->contract_id === null || $this->references->contracts->isCurrent($context, $record->contract_id, $record->contract_version ?? ''));
    }

    public function conflict(string $key, string $code): never
    {
        throw new DomainConflictException(__('sponsorships.'.$key), $code);
    }
}
