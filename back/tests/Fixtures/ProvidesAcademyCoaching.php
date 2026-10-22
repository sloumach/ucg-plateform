<?php

namespace Tests\Fixtures;

use App\Modules\Academy\Domain\Models\AcademyObjective;

trait ProvidesAcademyCoaching
{
    /** @return array<string, mixed> */
    private function coachingScenario(): array
    {
        $s = $this->sessionScenario(['academy.read', 'academy.manage', 'academy.feedback.manage', 'academy.notes.manage']);
        $s['path'] = str_replace('/sessions', '/objectives', $s['path']);
        $s['body'] = ['title' => 'Objectif tactique', 'description' => 'Améliorer les placements', 'due_on' => '2026-11-01',
            'responsible_enrollment_id' => $s['mentor']->id, 'target_enrollment_id' => $s['student']->id];

        return $s;
    }

    /** @param array<string, mixed> $s */
    private function coachingObjective(array $s, bool $collective = false): AcademyObjective
    {
        return AcademyObjective::factory()->create(['organization_id' => $s['org']->id, 'cohort_id' => $s['cohort']->id,
            'cycle_id' => $s['cycle']->id, 'responsible_enrollment_id' => $s['mentor']->id,
            'target_enrollment_id' => $collective ? null : $s['student']->id]);
    }
}
