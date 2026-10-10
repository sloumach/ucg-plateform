<?php

namespace App\Modules\Identity\Application\Services;

use App\Http\Api\RequestId;
use App\Modules\Identity\Domain\Contracts\SecurityAuditRepository;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\SecurityEventAction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class SecurityAuditService
{
    public function __construct(private SecurityAuditRepository $events) {}

    public function record(
        SecurityEventAction $action,
        Request $request,
        ?User $user = null,
        ?string $email = null,
    ): void {
        $userAgent = $request->userAgent();

        $this->events->record(
            action: $action,
            requestId: RequestId::for($request),
            userId: $user?->getKey(),
            subjectHash: $email === null ? null : hash_hmac(
                'sha256',
                Str::lower(trim($email)),
                (string) config('app.key'),
            ),
            ipAddress: $request->ip(),
            userAgent: $userAgent === null ? null : Str::limit($userAgent, 512, ''),
        );
    }
}
