<?php

namespace Tests\Unit;

use App\Modules\Competition\Domain\MatchResultWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MatchResultWorkflowTest extends TestCase
{
    /** @return array<string,array{string,string,?string,string,?string}> */
    public static function transitions(): array
    {
        return ['played before start' => ['submit', 'confirmed', null, 'played', null], 'forfeit before start' => ['submit', 'confirmed', null, 'forfeit', 'awaiting_validation'],
            'pending cannot resubmit' => ['submit', 'awaiting_validation', 'submit', 'played', null], 'dispute requires resubmission' => ['approve', 'disputed', 'dispute', 'played', null],
            'revised proposition' => ['submit', 'disputed', 'dispute', 'played', 'awaiting_validation'], 'approved forfeit' => ['approve', 'awaiting_validation', 'submit', 'forfeit', 'forfeited'],
            'approved abandon' => ['approve', 'awaiting_validation', 'submit', 'abandon', 'validated'], 'contest forfeit' => ['dispute', 'forfeited', 'approve', 'forfeit', 'disputed'],
            'cancelled cannot submit' => ['submit', 'cancelled', null, 'forfeit', null], 'approval once' => ['approve', 'validated', 'approve', 'played', null]];
    }

    #[DataProvider('transitions')]
    public function test_result_commands_follow_the_proof_workflow(string $action, string $phase, ?string $previous, string $outcome, ?string $target): void
    {
        $this->assertSame($target, MatchResultWorkflow::target($action, $phase, $previous, $outcome));
    }
}
