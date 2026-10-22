<?php

namespace App\Modules\Academy\Domain\Contracts;

use App\Modules\Academy\Domain\Models\AcademyAttendance;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Academy\Domain\Models\AcademySessionRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AcademySessionRepository
{
    /** @return LengthAwarePaginator<int, AcademySession> */
    public function sessions(string $org, string $cycle, int $page, int $perPage): LengthAwarePaginator;

    public function session(string $org, string $cycle, string $id, bool $lock = false): AcademySession;

    /** @return LengthAwarePaginator<int, AcademyAttendance> */
    public function attendances(string $org, string $session, int $page, int $perPage): LengthAwarePaginator;

    public function attendance(string $org, string $session, string $id, bool $lock = false): AcademyAttendance;

    /** @return LengthAwarePaginator<int, AcademySessionRevision> */
    public function history(string $org, string $session, int $page, int $perPage): LengthAwarePaginator;

    public function hasAttendance(string $org, string $session): bool;

    /** @param array<string, mixed> $attributes */
    public function saveSession(AcademySession $session, array $attributes): AcademySession;

    /** @param array<string, mixed> $attributes */
    public function saveAttendance(AcademyAttendance $attendance, array $attributes): AcademyAttendance;

    /** @param array<string, mixed> $attributes */
    public function revise(array $attributes): void;
}
