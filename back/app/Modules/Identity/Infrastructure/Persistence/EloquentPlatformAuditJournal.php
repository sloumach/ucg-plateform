<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Contracts\PlatformAuditJournal;
use App\Modules\Identity\Domain\Models\IdentityAuditEvent;
use App\Support\Audit\AuditFilter;
use App\Support\Audit\AuditPage;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditSource;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditTimelineQuery;
use App\Support\Audit\AuditWriteData;

final readonly class EloquentPlatformAuditJournal implements PlatformAuditJournal
{
    public function __construct(private AuditTimelineQuery $timeline) {}

    public function read(AuditFilter $filter): AuditPage
    {
        $base = ['id', 'action', 'request_id', 'occurred_at'];

        return $this->timeline->page([
            new AuditSource('identity.security', 'identity_security_events', [...$base, 'user_id', 'ip_address'], 'user_id', 'user_id', AuditTarget::Account, AuditReason::Authentication,
                knownActorActions: ['auth.login.succeeded', 'auth.logout.succeeded', 'auth.password_reset.succeeded', 'auth.email_verified', 'auth.sessions.revoked', 'auth.mfa.enrollment_started', 'auth.mfa.enabled', 'auth.mfa.challenge_succeeded', 'auth.mfa.challenge_failed', 'auth.mfa.recovery_used', 'auth.mfa.recovery_regenerated', 'auth.mfa.disabled']),
            new AuditSource('identity.platform_roles', 'platform_role_events', ['id', 'actor_user_id', 'user_id', 'request_id', 'occurred_at', 'before_role', 'after_role', 'before_active', 'after_active', 'before_version', 'after_version'],
                'actor_user_id', 'user_id', AuditTarget::Account, AuditReason::Governance, beforeMap: ['role' => 'before_role', 'active' => 'before_active', 'version' => 'before_version'], afterMap: ['role' => 'after_role', 'active' => 'after_active', 'version' => 'after_version'], fixedAction: 'platform.role.changed'),
            new AuditSource('identity.audit', 'identity_audit_events', [...$base, 'actor_user_id', 'target_type', 'target_id', 'reason_code', 'ip_address', 'before', 'after'],
                'actor_user_id', 'target_id', AuditTarget::Journal, AuditReason::Review, targetTypeColumn: 'target_type', jsonStates: true),
        ], $filter);
    }

    public function record(AuditWriteData $data): void
    {
        $attributes = $data->attributes();
        $attributes['before'] = (object) $attributes['before'];
        $attributes['after'] = (object) $attributes['after'];
        (new IdentityAuditEvent)->forceFill($attributes)->save();
    }
}
