<?php

namespace Tests\Feature;

use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Scheduling\Application\Contracts\AcademySchedule;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesAcademySessions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class AcademyScheduleTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesAcademySessions, ProvidesPeopleAccess;

    public function test_choices_are_paginated_and_never_include_private_foreign_or_non_academy_activities(): void
    {
        $s = $this->sessionScenario();
        CalendarActivity::factory()->create(['organization_id' => $s['org']->id, 'kind' => 'academy']);
        CalendarActivity::factory()->shared()->create(['organization_id' => $s['org']->id, 'kind' => 'meeting']);
        CalendarActivity::factory()->shared()->create(['kind' => 'academy']);
        $path = str_replace('/sessions', '/session-activities', $s['path']);
        $this->getJson($path.'?from_date=2026-10-01&to_date=2026-11-01&per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $s['activity']->id)->assertJsonMissingPath('data.0.description');
        $this->getJson($path.'?from_date=2026-01-01&to_date=2026-12-31')->assertUnprocessable()->assertJsonValidationErrors('to_date');
        $this->getJson($path.'?from_date=2026-10-01&to_date=2026-11-01&organization_id=spoof')->assertUnprocessable();
    }

    public function test_calendar_read_is_required_for_reference_and_attendance_but_session_content_keeps_its_own_authorization(): void
    {
        $s = $this->sessionScenario(['academy.read', 'academy.manage', 'academy.attendance.manage']);
        $path = str_replace('/sessions', '/session-activities', $s['path']);
        $this->getJson($path.'?from_date=2026-10-01&to_date=2026-11-01')->assertForbidden();
        $this->postJson($s['path'], $s['body'])->assertForbidden();
        AcademySession::factory()->create(['organization_id' => $s['org']->id, 'cohort_id' => $s['cohort']->id, 'cycle_id' => $s['cycle']->id,
            'responsible_enrollment_id' => $s['mentor']->id]);
        $this->getJson($s['path'])->assertOk()->assertJsonPath('data.0.activity', null)->assertJsonPath('data.0.starts_at', null);
    }

    public function test_session_page_resolves_calendar_and_person_names_in_batches(): void
    {
        $s = $this->sessionScenario();
        AcademySession::factory()->count(3)->create(['organization_id' => $s['org']->id, 'cohort_id' => $s['cohort']->id,
            'cycle_id' => $s['cycle']->id, 'responsible_enrollment_id' => $s['mentor']->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson($s['path'].'?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
            $calendarQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'from "calendar_activities"'));
            $personQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'from "people"'));
            $this->assertCount(1, $calendarQueries);
            $this->assertCount(1, $personQueries);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_calendar_resolution_rejects_an_unbounded_identifier_batch(): void
    {
        $s = $this->sessionScenario();
        $this->expectException(\LogicException::class);
        app(AcademySchedule::class)->available($this->personContext($s['org'], $s['actor']), array_fill(0, 101, $s['activity']->id), true);
    }
}
