<?php

namespace App\Support\Audit;

use App\Support\Data\DataTransferObject;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Closed metadata only: no URL, query, filename, contract contents or free-form reason. */
final readonly class SensitiveAccessData extends DataTransferObject
{
    public const array PURPOSES = ['operational', 'internal_control', 'legal_review', 'operator_portability'];

    public function __construct(public int $actorUserId, public SensitiveAccessAction $action, public string $targetId,
        public string $requestId, public string $purpose = 'operational', public ?string $ipAddress = null)
    {
        if ($actorUserId < 1 || ! Str::isUuid($targetId) || ! Str::isUuid($requestId) || ! in_array($purpose, self::PURPOSES, true)
            || ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false)) {
            throw new InvalidArgumentException('Invalid sensitive access metadata.');
        }
    }

    /** @return array<string,mixed> */
    public function attributes(): array
    {
        return ['actor_user_id' => $this->actorUserId, 'action' => $this->action->value, 'target_type' => $this->action->targetType(),
            'target_id' => strtolower($this->targetId), 'request_id' => strtolower($this->requestId),
            'reason_code' => $this->purpose, 'ip_address' => $this->ipAddress, 'occurred_at' => now()];
    }
}
