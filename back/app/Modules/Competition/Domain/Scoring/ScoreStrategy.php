<?php

namespace App\Modules\Competition\Domain\Scoring;

interface ScoreStrategy
{
    /** @param list<string> $participants
     * @param  list<array{outcome:string,results:list<array{participant_id:string,score:?int}>}>  $games
     * @return array{complete:bool,played:int,winner_ids:list<string>,standings:list<array{participant_id:string,score:int}>,draw:bool}
     */
    public function calculate(array $participants, array $games, int $limit): array;
}
