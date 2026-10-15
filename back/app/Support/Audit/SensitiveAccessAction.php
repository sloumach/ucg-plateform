<?php

namespace App\Support\Audit;

enum SensitiveAccessAction: string
{
    case ContractListed = 'contract.listed';
    case ContractViewed = 'contract.viewed';
    case ContractHistoryViewed = 'contract.history_viewed';
    case FinancialViewed = 'contract.financial_viewed';
    case AmendmentFinancialViewed = 'contract.amendment_financial_viewed';
    case DocumentsViewed = 'contract.documents_viewed';
    case VersionsViewed = 'contract.versions_viewed';
    case ApprovalsViewed = 'contract.approvals_viewed';
    case ScanHistoryViewed = 'contract.scan_history_viewed';
    case DownloadLinkCreated = 'contract.download_link_created';
    case DocumentDownloaded = 'contract.document_downloaded';
    case RemindersViewed = 'contract.reminders_viewed';
    case ReminderHistoryViewed = 'contract.reminder_history_viewed';
    case ReminderInboxViewed = 'contract.reminder_inbox_viewed';
    case ExportRequested = 'tenant.export_requested';
    case ExportRetried = 'tenant.export_retried';
    case ExportGenerated = 'tenant.export_generated';
    case ExportRead = 'tenant.export_read';

    public function targetType(): string
    {
        return match ($this) {
            self::ContractListed, self::ReminderInboxViewed => 'contract_collection',
            self::AmendmentFinancialViewed, self::VersionsViewed, self::ApprovalsViewed => 'contract_document',
            self::ScanHistoryViewed, self::DownloadLinkCreated, self::DocumentDownloaded => 'contract_document_version',
            self::ExportRequested, self::ExportRetried, self::ExportGenerated, self::ExportRead => 'organization_export',
            default => 'contract',
        };
    }

    /** Only server-selected route names. Mutations keep their own domain audit. */
    public static function forRoute(string $route): ?self
    {
        return match ($route) {
            'api.v1.contracts.index' => self::ContractListed,
            'api.v1.contracts.show' => self::ContractViewed,
            'api.v1.contracts.transitions' => self::ContractHistoryViewed,
            'api.v1.contracts.financial.show' => self::FinancialViewed,
            'api.v1.contract-documents.financial.show' => self::AmendmentFinancialViewed,
            'api.v1.contract-documents.index' => self::DocumentsViewed,
            'api.v1.contract-documents.versions' => self::VersionsViewed,
            'api.v1.contract-documents.approvals' => self::ApprovalsViewed,
            'api.v1.contract-document-security.index' => self::ScanHistoryViewed,
            'api.v1.contract-document-security.link' => self::DownloadLinkCreated,
            'api.v1.contract-document-security.download' => self::DocumentDownloaded,
            'api.v1.contract-reminders.show' => self::RemindersViewed,
            'api.v1.contract-reminders.history' => self::ReminderHistoryViewed,
            'api.v1.contract-reminders.inbox' => self::ReminderInboxViewed,
            default => null,
        };
    }

    public function routeParameter(): ?string
    {
        return match ($this->targetType()) {
            'contract' => 'contract', 'contract_document' => 'document', 'contract_document_version' => 'version', default => null,
        };
    }
}
