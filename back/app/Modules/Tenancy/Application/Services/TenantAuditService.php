<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditFilter;
use App\Support\Audit\AuditPage;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditRetentionPolicy;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

final readonly class TenantAuditService
{
    public function __construct(private TenantAuditJournal $journal, private TenantAuthorization $authorization,
        private PlatformAccountDirectory $platform, private AccountDirectory $accounts, private OrganizationRepository $organizations,
        private AuditRetentionPolicy $retention, private DatabaseManager $database) {}

    public function allows(TenantContext $context): bool
    {
        return ! $this->platform->isTechnical($context->actorUserId) && $this->accounts->verifiedAccountExists($context->actorUserId)
            && $this->accounts->hasConfirmedMfa($context->actorUserId) && $this->authorization->allows($context, 'audit.view');
    }

    public function listing(TenantContext $context, AuditFilter $filter, ?string $ip): AuditPage
    {
        if ($filter->auditorId !== $context->actorUserId || ! $this->allows($context)) {
            throw new AuthorizationException(__('audit.forbidden'));
        }
        $this->retention->description();

        return $this->database->transaction(function () use ($context, $filter, $ip): AuditPage {
            $this->organizations->lock($context->organizationId);
            if (! $this->allows($context)) {
                throw new AuthorizationException(__('audit.forbidden'));
            }
            $page = $this->journal->read($context->organizationId, $filter);
            $this->journal->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::JournalViewed, AuditTarget::Journal, 'tenant', AuditReason::Review, $context->requestId, $ip));

            return $page;
        });
    }
}
