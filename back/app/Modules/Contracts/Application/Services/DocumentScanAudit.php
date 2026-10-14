<?php

namespace App\Modules\Contracts\Application\Services;

use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;

final readonly class DocumentScanAudit
{
    public function __construct(private TenantAuditJournal $audit) {}

    public function record(TenantContext $context, ContractDocumentScan $scan): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
            AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, null, [],
            ['contract_id' => $scan->contract_id, 'contract_document_id' => $scan->document_id, 'contract_document_version_id' => $scan->document_version_id,
                'document_scan_id' => $scan->id, 'document_scan_status' => $scan->invalidated_at === null ? $scan->status : 'rejected',
                ...($scan->reason === null ? [] : ['document_scan_reason' => $scan->reason])]));
    }
}
