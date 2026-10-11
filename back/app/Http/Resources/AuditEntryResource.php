<?php

namespace App\Http\Resources;

use App\Http\Api\ApiResource;
use App\Http\Api\RequestId;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** @mixin AuditEntry */
final class AuditEntryResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'source' => $this->source, 'organization_id' => $this->organizationId,
            'actor_user_id' => $this->actorUserId, 'action' => $this->action, 'target' => ['type' => $this->targetType, 'id' => $this->targetId],
            'before' => (object) $this->before, 'after' => (object) $this->after, 'reason_code' => $this->reasonCode,
            'request_id' => $this->requestId, 'ip_address' => $this->ipAddress, 'occurred_at' => $this->occurredAt];
    }

    public static function page(AuditPage $page, Request $request): AnonymousResourceCollection
    {
        return self::collection($page->entries)->additional(['meta' => ['request_id' => RequestId::for($request), 'per_page' => $page->perPage, 'next_cursor' => $page->nextCursor,
            'retention' => ['mode' => 'unlimited', 'purge_enabled' => false]]]);
    }
}
