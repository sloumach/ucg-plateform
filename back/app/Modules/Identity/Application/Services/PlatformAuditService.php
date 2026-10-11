<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAuditJournal;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditFilter;
use App\Support\Audit\AuditPage;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditRetentionPolicy;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

final readonly class PlatformAuditService
{
    public function __construct(private PlatformAuditJournal $journal, private PlatformAccountDirectory $platform, private AccountDirectory $accounts, private AuditRetentionPolicy $retention, private DatabaseManager $database) {}

    public function allows(int $actorId): bool
    {
        return $this->platform->isSuperadmin($actorId) && $this->accounts->verifiedAccountExists($actorId) && $this->accounts->hasConfirmedMfa($actorId);
    }

    public function listing(AuditFilter $filter, string $requestId, ?string $ip): AuditPage
    {
        if (! $this->allows($filter->auditorId)) {
            throw new AuthorizationException(__('audit.forbidden'));
        }
        $this->retention->description();

        return $this->database->transaction(function () use ($filter, $requestId, $ip): AuditPage {
            if (! $this->allows($filter->auditorId)) {
                throw new AuthorizationException(__('audit.forbidden'));
            }
            $page = $this->journal->read($filter);
            $this->journal->record(new AuditWriteData($filter->auditorId, AuditAction::JournalViewed, AuditTarget::Journal, 'platform', AuditReason::Review, $requestId, $ip));

            return $page;
        });
    }
}
