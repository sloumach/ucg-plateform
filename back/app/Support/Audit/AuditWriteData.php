<?php

namespace App\Support\Audit;

use App\Support\Data\DataTransferObject;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AuditWriteData extends DataTransferObject
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(public ?int $actorUserId, public AuditAction $action, public AuditTarget $targetType,
        public string $targetId, public AuditReason $reason, public string $requestId, public ?string $ipAddress = null,
        public array $before = [], public array $after = [])
    {
        $targetValid = match ($targetType) {
            AuditTarget::Journal => in_array($targetId, ['platform', 'tenant'], true),
            AuditTarget::Route => preg_match('/^api\.v1\.[a-zA-Z0-9_.-]{1,100}$/D', $targetId) === 1,
            AuditTarget::Account => ctype_digit($targetId) && (int) $targetId > 0,
            default => Str::isUuid($targetId),
        };
        if (! $targetValid || ! Str::isUuid($requestId) || ($actorUserId !== null && $actorUserId < 1)
            || ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false)) {
            throw new InvalidArgumentException('Invalid audit metadata.');
        }
        AuditState::project($before, true);
        AuditState::project($after, true);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return ['actor_user_id' => $this->actorUserId, 'action' => $this->action->value, 'target_type' => $this->targetType->value,
            'target_id' => $this->targetId, 'reason_code' => $this->reason->value, 'request_id' => $this->requestId,
            'ip_address' => $this->ipAddress, 'before' => $this->before, 'after' => $this->after, 'occurred_at' => now()];
    }
}
