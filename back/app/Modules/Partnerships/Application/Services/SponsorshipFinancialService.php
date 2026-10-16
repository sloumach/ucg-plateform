<?php

namespace App\Modules\Partnerships\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Exceptions\SensitiveAuditUnavailableException;
use App\Modules\Partnerships\Application\Validation\PartnershipInput;
use App\Modules\Partnerships\Application\Validation\SponsorshipAgreementInput as Input;
use App\Modules\Partnerships\Domain\Contracts\SponsorshipAgreementRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class SponsorshipFinancialService
{
    public function __construct(private SponsorshipAgreementRepository $records, private SponsorshipAgreementService $agreements,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private Factory $validator,
        private ConnectionInterface $database, private LoggerInterface $logger) {}

    /** @return array<string, mixed> */
    public function read(TenantContext $context, string $sponsor, string $id): array
    {
        $this->authorize($context);
        $this->agreements->find($context, $sponsor, $id);
        try {
            return $this->database->transaction(function () use ($context, $sponsor, $id): array {
                $this->guard->lock($context);
                $this->authorize($context);
                $agreement = $this->agreements->find($context, $sponsor, $id);
                $record = $this->records->terms($context->organizationId, $id);
                $this->agreements->record($context, $agreement, 'financial_viewed', $agreement->status);

                return ['id' => $record?->id, 'version' => $record?->version, 'terms' => $record?->terms, 'agreement_version' => $agreement->version];
            });
        } catch (AuthorizationException|DomainConflictException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error('sponsorship.financial_audit_failed', ['organization_id' => $context->organizationId,
                'request_id' => $context->requestId, 'exception_type' => $exception::class]);
            throw new SensitiveAuditUnavailableException;
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function save(TenantContext $context, string $sponsor, string $id, array $input, ?string $ip = null): array
    {
        $this->authorize($context, true);
        $values = PartnershipInput::validate($this->validator, $input, Input::financialRules());
        $terms = $values['terms'];
        $cash = in_array($terms['kind'], ['cash', 'mixed'], true);
        $inKind = in_array($terms['kind'], ['in_kind', 'mixed'], true);
        if (($cash && ($terms['amount'] === null || $terms['amount'] === '0.00' || $terms['currency'] === null))
            || (! $cash && ($terms['amount'] !== null || $terms['currency'] !== null))
            || ($inKind && $terms['in_kind'] === null) || (! $inKind && $terms['in_kind'] !== null)) {
            throw ValidationException::withMessages(['terms' => __('sponsorships.financial_format')]);
        }

        return $this->database->transaction(function () use ($context, $sponsor, $id, $values, $ip): array {
            $agreement = $this->agreements->lock($context, $sponsor, $id, $values['agreement_version']);
            $this->authorize($context, true);
            $this->agreements->assertEditable($agreement);
            $previous = $this->records->terms($context->organizationId, $id);
            if ($previous?->version !== $values['expected_version']) {
                $this->agreements->conflict('stale', 'SPONSORSHIP_FINANCIAL_STALE');
            }
            $from = $agreement->status;
            $agreement = $this->agreements->invalidate($context, $agreement);
            $saved = $this->records->saveTerms($previous, ['organization_id' => $context->organizationId, 'agreement_id' => $id,
                'terms' => $values['terms'], 'prepared_by' => $context->actorUserId, 'version' => (string) Str::uuid()]);
            $this->agreements->record($context, $agreement, 'financial_saved', $from, $ip);

            return ['id' => $saved->id, 'version' => $saved->version, 'terms' => $saved->terms, 'agreement_version' => $agreement->version];
        });
    }

    private function authorize(TenantContext $context, bool $write = false): void
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $this->authorization->authorize($context, 'financial.read');
        if ($write) {
            $this->agreements->authorizeWrite($context);
            $this->authorization->authorize($context, 'financial.write');
        }
    }
}
