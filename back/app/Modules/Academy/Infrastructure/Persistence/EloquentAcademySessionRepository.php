<?php

namespace App\Modules\Academy\Infrastructure\Persistence;

use App\Modules\Academy\Domain\Contracts\AcademySessionRepository;
use App\Modules\Academy\Domain\Models\AcademyAttendance;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Academy\Domain\Models\AcademySessionRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EloquentAcademySessionRepository implements AcademySessionRepository
{
    public function sessions(string $org, string $cycle, int $page, int $perPage): LengthAwarePaginator
    {
        return AcademySession::query()->where('organization_id', $org)->where('cycle_id', strtolower($cycle))->with('responsibleEnrollment')->withCount('attendances')
            ->orderBy('starts_at')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function session(string $org, string $cycle, string $id, bool $lock = false): AcademySession
    {
        return AcademySession::query()->where('organization_id', $org)->where('cycle_id', strtolower($cycle))->with('responsibleEnrollment')->withCount('attendances')
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function attendances(string $org, string $session, int $page, int $perPage): LengthAwarePaginator
    {
        return AcademyAttendance::query()->where('organization_id', $org)->where('session_id', $session)->with('enrollment')
            ->orderBy('created_at')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function attendance(string $org, string $session, string $id, bool $lock = false): AcademyAttendance
    {
        return AcademyAttendance::query()->where('organization_id', $org)->where('session_id', $session)->with('enrollment')
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function history(string $org, string $session, int $page, int $perPage): LengthAwarePaginator
    {
        return AcademySessionRevision::query()->where('organization_id', $org)->where('session_id', $session)
            ->orderByDesc('recorded_at')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function hasAttendance(string $org, string $session): bool
    {
        return AcademyAttendance::query()->where('organization_id', $org)->where('session_id', $session)->exists();
    }

    public function saveSession(AcademySession $session, array $attributes): AcademySession
    {
        $session->forceFill($attributes)->save();

        return $session->load('responsibleEnrollment')->loadCount('attendances');
    }

    public function saveAttendance(AcademyAttendance $attendance, array $attributes): AcademyAttendance
    {
        $attendance->forceFill($attributes)->save();

        return $attendance->load('enrollment');
    }

    public function revise(array $attributes): void
    {
        (new AcademySessionRevision)->forceFill($attributes)->save();
    }
}
