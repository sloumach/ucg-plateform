<?php

namespace App\Modules\Reporting\Domain\Contracts;

interface DataRehearsalRunner
{
    /** @return array{exit_code:int,tests:int,failures:int,errors:int,skipped:int,assertions:int,checks:list<array{check:string,facts:array<string,int|bool>}>,scenario_sha256:string} */
    public function run(): array;
}
