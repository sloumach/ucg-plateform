<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Modules\Partnerships\Application\Validation\PartnershipInput;
use App\Modules\Partnerships\Application\Validation\SponsorshipAgreementInput as Input;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipAgreementRepository;
use App\Modules\Partnerships\Domain\Models\SponsorshipExposure;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SponsorshipExposureService
{
    public function __construct(private SponsorshipAgreementRepository $records, private SponsorshipAgreementService $agreements,
        private SponsorshipReferenceService $references, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginate(TenantContext $context, string $sponsor, string $id, array $filters): LengthAwarePaginator
    {
        $agreement = $this->agreements->find($context, $sponsor, $id);
        $values = PartnershipInput::validate($this->validator, $filters, Input::pageRules('exposures'));
        $page = $this->records->exposures($context->organizationId, $agreement->id, $values, $this->references->constraints($context));
        $eligible = $this->references->eligibility($context, array_values($page->items()));
        $this->references->assertVisible($context, array_values($page->items()));
        $metadata = $this->references->contextualMetadata($context, array_values($page->items()));
        $approved = $this->agreements->approvedCurrent($context, $agreement);

        return $page->through(fn (SponsorshipExposure $exposure): array => $this->project($exposure, $approved && ($eligible[$exposure->id] ?? false), $metadata[$exposure->id]['version'] ?? null));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function save(TenantContext $context, string $sponsor, string $agreementId, array $input, ?string $id = null, ?string $ip = null): array
    {
        $this->agreements->authorizeWrite($context);
        $values = PartnershipInput::validate($this->validator, $input, Input::exposureRules($id === null));
        if ($id !== null && array_diff(array_keys($values), ['version', 'agreement_version']) === []) {
            throw ValidationException::withMessages(['record' => __('partnerships.empty')]);
        }

        return $this->database->transaction(function () use ($context, $sponsor, $agreementId, $values, $id, $ip): array {
            $agreement = $this->agreements->lock($context, $sponsor, $agreementId, $values['agreement_version']);
            $this->agreements->assertEditable($agreement);
            $exposure = $id === null ? null : $this->records->exposure($context->organizationId, $agreementId, $id);
            if ($exposure !== null && ($exposure->version !== strtolower($values['version']) || $exposure->status === 'archived')) {
                $this->agreements->conflict('stale', 'SPONSORSHIP_EXPOSURE_STALE');
            }
            if ($exposure === null && $this->records->exposureCount($context->organizationId, $agreementId) >= Input::MAX_EXPOSURES) {
                $this->agreements->conflict('limit', 'SPONSORSHIP_EXPOSURE_LIMIT');
            }
            $attributes = $values;
            unset($attributes['version'], $attributes['agreement_version'], $attributes['target_id'], $attributes['target_version']);
            $source = null;
            $type = $exposure?->context?->type() ?? ($values['target_type'] ?? $exposure?->target_type);
            $archiving = ($values['status'] ?? null) === 'archived';
            if ($exposure !== null) {
                $this->references->assertVisible($context, [$exposure]);
            }
            if (in_array($type, ['event', 'content'], true) && ! $archiving) {
                $source = $this->references->resolve($context, $type, $exposure?->context?->sourceId() ?? $values['target_id'], $values['target_version'] ?? null);
                $attributes['target_label'] = $source['label'];
            } elseif (isset($values['target_version'])) {
                throw ValidationException::withMessages(['target_version' => __('sponsorships.target_version_unexpected')]);
            }
            if ($exposure === null) {
                $label = $source['label'] ?? $this->references->label($context, $values['target_type'], $values['target_id']);
                if ($source !== null) {
                    $attributes['target_type'] = 'organization';
                }
                $attributes = ['organization_id' => $context->organizationId, 'agreement_id' => $agreement->id,
                    'team_id' => $values['target_type'] === 'team' ? strtolower($values['target_id']) : null,
                    'person_id' => $values['target_type'] === 'player' ? strtolower($values['target_id']) : null,
                    'target_label' => $label, 'description' => null, 'exclusivity_key' => null, 'status' => 'active', ...$attributes];
            }
            $from = $agreement->status;
            $agreement = $this->agreements->invalidate($context, $agreement);
            $saved = $this->records->saveExposure($exposure, [...$attributes, 'version' => (string) Str::uuid()]);
            if ($source !== null) {
                $this->records->saveContext($saved, $type, $source['id'], $source['version']);
            }
            $this->agreements->record($context, $agreement, 'exposure_saved', $from, $ip);

            return [...$this->project($saved, false, $source['version'] ?? null), 'agreement_version' => $agreement->version];
        });
    }

    /** @return array<string, mixed> */
    private function project(SponsorshipExposure $record, bool $effective, ?string $currentVersion = null): array
    {
        return ['id' => $record->id, 'agreement_id' => $record->agreement_id, 'target_type' => $record->context?->type() ?? $record->target_type,
            'target_id' => $record->context?->sourceId() ?? $record->team_id ?? $record->person_id ?? $record->organization_id, 'target_label' => $record->target_label,
            ...($record->context === null ? [] : ['target_version' => $record->context->source_version, 'current_target_version' => $currentVersion,
                'target_current' => $currentVersion === $record->context->source_version]),
            'channel' => $record->channel, 'description' => $record->description, 'authorization_reference' => $record->authorization_reference,
            'exclusivity_key' => $record->exclusivity_key, 'status' => $record->status, 'version' => $record->version,
            'exposure_authorized' => $effective, 'publication_status' => 'private'];
    }
}
