<?php

namespace Tests\Unit;

use App\Modules\Competition\Domain\MatchWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MatchWorkflowTest extends TestCase
{
    /** @return array<string,array{string,list<string>}> */
    public static function transitions(): array
    {
        return ['proposal' => ['proposed', ['confirmed', 'cancelled']], 'confirmation' => ['confirmed', ['proposed', 'in_progress', 'cancelled', 'forfeited']],
            'playing' => ['in_progress', ['awaiting_validation', 'cancelled', 'forfeited']], 'review' => ['awaiting_validation', ['validated', 'disputed']],
            'accepted' => ['validated', ['disputed']], 'dispute' => ['disputed', ['awaiting_validation', 'validated', 'cancelled']],
            'cancellation terminal' => ['cancelled', []], 'forfeit terminal' => ['forfeited', []]];
    }

    /** @param list<string> $targets */
    #[DataProvider('transitions')]
    public function test_domain_controls_each_status_and_refuses_self_transition(string $from, array $targets): void
    {
        $this->assertSame($targets, MatchWorkflow::next($from));
        $this->assertFalse(MatchWorkflow::permits($from, $from));
        foreach ($targets as $target) {
            $this->assertTrue(MatchWorkflow::permits($from, $target));
        }
    }

    public function test_series_count_and_lobby_bounds_do_not_impose_two_teams(): void
    {
        $this->assertTrue(MatchWorkflow::participantCount('bo3', 2));
        $this->assertFalse(MatchWorkflow::participantCount('bo5', 3));
        $this->assertTrue(MatchWorkflow::participantCount('lobby', 100));
        $this->assertFalse(MatchWorkflow::participantCount('lobby', 101));
        $this->assertFalse(MatchWorkflow::participantCount('free_for_all', 1));
        $this->assertFalse(MatchWorkflow::participantCount('invented', 2));
        $this->assertSame([], MatchWorkflow::operational('awaiting_validation'));
        $this->assertSame([], MatchWorkflow::operational('disputed'));
    }
}
