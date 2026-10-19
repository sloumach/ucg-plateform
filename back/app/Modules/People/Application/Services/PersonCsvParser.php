<?php

namespace App\Modules\People\Application\Services;

use App\Support\Files\CsvParser;

final readonly class PersonCsvParser
{
    public function __construct(private CsvParser $parser) {}

    /** @return array{headers:list<string>,rows:list<array{line:int,cells:list<string>}>} */
    public function parse(string $contents, string $delimiter, int $maximumRows): array
    {
        return $this->parser->parse($contents, $delimiter, $maximumRows, (int) config('person_imports.max_columns', 32));
    }
}
