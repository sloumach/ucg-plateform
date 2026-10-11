<?php

namespace App\Support\Audit;

enum AuditAction: string
{
    case JournalViewed = 'journal.viewed';
    case AccessDenied = 'access.denied';
    case DomainChanged = 'domain.changed';
}
