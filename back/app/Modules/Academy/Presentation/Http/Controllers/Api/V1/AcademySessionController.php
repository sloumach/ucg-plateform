<?php

namespace App\Modules\Academy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Academy\Application\Services\AcademyAttendanceService;
use App\Modules\Academy\Application\Services\AcademySessionService;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Academy\Presentation\Http\Requests\Api\V1\AcademySessionRequest;
use App\Modules\Academy\Presentation\Http\Resources\Api\V1\AcademyActivityResource;
use App\Modules\Academy\Presentation\Http\Resources\Api\V1\AcademyAttendanceResource;
use App\Modules\Academy\Presentation\Http\Resources\Api\V1\AcademyResource;
use App\Modules\Academy\Presentation\Http\Resources\Api\V1\AcademySessionResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AcademySessionController extends Controller
{
    public function __construct(private readonly AcademySessionService $sessions, private readonly AcademyAttendanceService $attendance, private readonly Gate $gate) {}

    public function index(AcademySessionRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [AcademySession::class, $context]);
        $cohort = (string) $request->route('cohort');
        $cycle = (string) $request->route('cycle');

        return match ($request->route('session_kind')) {
            'session' => AcademySessionResource::collection($this->sessions->paginate($context, $cohort, $cycle, $request->validated())),
            'attendance' => AcademyAttendanceResource::collection($this->attendance->paginate($context, $cohort, $cycle, (string) $request->route('session'), $request->validated())),
            'history' => AcademyResource::collection($this->sessions->history($context, $cohort, $cycle, (string) $request->route('session'), $request->validated())),
            default => throw new \InvalidArgumentException('Unknown academy session listing.'),
        };
    }

    public function activities(AcademySessionRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('activities', [AcademySession::class, $context]);

        return AcademyActivityResource::collection($this->sessions->activities($context, (string) $request->route('cohort'), (string) $request->route('cycle'), $request->validated()));
    }

    public function save(AcademySessionRequest $request, TenantContext $context): JsonResponse
    {
        $attendance = $request->route('session_kind') === 'attendance';
        $this->gate->authorize($attendance ? 'attendance' : 'manage', [AcademySession::class, $context]);
        $cohort = (string) $request->route('cohort');
        $cycle = (string) $request->route('cycle');
        $resource = $attendance
            ? AcademyAttendanceResource::make($this->attendance->save($context, $cohort, $cycle, (string) $request->route('session'), $request->validated(), $request->route('attendance'), $request->ip()))
            : AcademySessionResource::make($this->sessions->save($context, $cohort, $cycle, $request->validated(), $request->route('session'), $request->ip()));

        return $resource->additional(['notification' => ['type' => 'success', 'message' => __('academy_sessions.saved')]])->response()->setStatusCode($request->isMethod('POST') ? 201 : 200);
    }
}
