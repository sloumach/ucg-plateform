<?php

namespace App\Support\Audit;

enum AuditReason: string
{
    case Authentication = 'authentication_event';
    case Governance = 'governance_change';
    case Support = 'support_operation';
    case Review = 'authorized_review';
    case Refused = 'authorization_refused';
    case Domain = 'domain_change';
}
