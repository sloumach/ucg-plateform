<?php

namespace Tests\Unit;

use App\Modules\Competition\Domain\Scoring\BestOfScore;
use App\Modules\Competition\Domain\Scoring\PointsScore;
use PHPUnit\Framework\TestCase;

class MatchScoreTest extends TestCase
{
    public function test_best_of_counts_wins_instead_of_raw_points_and_stops_at_majority(): void
    {
        $games = [$this->game('played', 1, 50), $this->game('played', 100, 0), $this->game('abandon', 2, 1)];
        $score = (new BestOfScore)->calculate(['a', 'b'], $games, 3);
        $this->assertSame([['participant_id' => 'a', 'score' => 2], ['participant_id' => 'b', 'score' => 1]], $score['standings']);
        $this->assertSame(['a'], $score['winner_ids']);
        $this->expectException(\DomainException::class);
        (new BestOfScore)->calculate(['a', 'b'], [...$games, $this->game('remake', null, null)], 3);
    }

    public function test_bo5_and_authorized_draws_consume_scheduled_games_and_remakes_do_not(): void
    {
        $score = (new BestOfScore)->calculate(['a', 'b'], [$this->game('remake', null, null), $this->game('draw', 1, 1), $this->game('played', 2, 1), $this->game('played', 1, 2)], 3);
        $this->assertSame(true, $score['draw']);
        $this->assertSame(3, $score['played']);
        $this->assertSame(['a', 'b'], $score['winner_ids']);
        $score = (new BestOfScore)->calculate(['a', 'b'], array_fill(0, 3, $this->game('played', 2, 1)), 5);
        $this->assertSame(true, $score['complete']);
        $this->assertSame(3, $score['standings'][0]['score']);
    }

    public function test_points_support_more_than_two_participants_and_preserve_tied_rankings(): void
    {
        $score = (new PointsScore)->calculate(['a', 'b', 'c'], [['outcome' => 'played', 'results' => [
            ['participant_id' => 'a', 'score' => 7], ['participant_id' => 'b', 'score' => 9], ['participant_id' => 'c', 'score' => 9]]]], 1);
        $this->assertSame(['b', 'c'], $score['winner_ids']);
        $this->assertSame(true, $score['draw']);
        $this->assertSame('b', $score['standings'][0]['participant_id']);
    }

    /** @return array{outcome:string,results:list<array{participant_id:string,score:?int}>} */
    private function game(string $outcome, ?int $a, ?int $b): array
    {
        return ['outcome' => $outcome, 'results' => [['participant_id' => 'a', 'score' => $a], ['participant_id' => 'b', 'score' => $b]]];
    }
}
