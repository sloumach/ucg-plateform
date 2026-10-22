<?php

namespace Database\Factories;

use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AcademySession> */
final class AcademySessionFactory extends Factory
{
    protected $model = AcademySession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['responsible_enrollment_id' => AcademyEnrollment::factory()->state(['role' => 'mentor']),
            'organization_id' => fn (array $a): string => AcademyEnrollment::query()->whereKey($a['responsible_enrollment_id'])->firstOrFail()->organization_id,
            'cohort_id' => fn (array $a): string => AcademyEnrollment::query()->whereKey($a['responsible_enrollment_id'])->firstOrFail()->cohort_id,
            'cycle_id' => fn (array $a): string => AcademyEnrollment::query()->whereKey($a['responsible_enrollment_id'])->firstOrFail()->cycle_id,
            'activity_id' => fn (array $a): string => CalendarActivity::factory()->shared()->create(['organization_id' => $a['organization_id'], 'kind' => 'academy', 'starts_at' => '2026-10-22 10:00:00', 'ends_at' => '2026-10-22 11:00:00'])->id,
            'activity_version' => fn (array $a): string => CalendarActivity::query()->whereKey($a['activity_id'])->firstOrFail()->version,
            'title' => 'Séance pilote', 'exercises' => [], 'report' => null, 'starts_at' => '2026-10-22 10:00:00', 'ends_at' => '2026-10-22 11:00:00', 'status' => 'planned', 'version' => (string) Str::uuid()];
    }
}
