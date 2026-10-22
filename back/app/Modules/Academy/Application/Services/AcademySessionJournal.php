<?php

namespace App\Modules\Academy\Application\Services;

use App\Modules\Academy\Domain\Contracts\AcademySessionRepository;
use App\Modules\Academy\Domain\Models\AcademyAttendance;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;

final readonly class AcademySessionJournal
{
    public function __construct(private AcademySessionRepository $records, private TenantAuditJournal $audit) {}

    public function record(TenantContext $context, AcademySession|AcademyAttendance $record, ?string $reason, ?string $ip): void
    {
        $kind = $record instanceof AcademySession ? 'session' : 'attendance';
        $this->records->revise(['organization_id' => $context->organizationId, 'session_id' => $record instanceof AcademySession ? $record->id : $record->session_id,
            'kind' => $kind, 'resource_id' => $record->id, 'actor_user_id' => $context->actorUserId,
            'snapshot' => ['fields' => $record->attributesToArray(), 'change_reason' => $reason], 'recorded_at' => now()]);
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
            $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['academy_resource_id' => $record->id, 'academy_kind' => $kind, 'version' => $record->version]));
    }
}
