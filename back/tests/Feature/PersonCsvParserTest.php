<?php

namespace Tests\Feature;

use App\Modules\People\Application\Services\PersonCsvParser;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonCsvParserTest extends TestCase
{
    public function test_bom_quotes_multiline_and_physical_line_numbers_are_preserved(): void
    {
        $csv = hex2bin('efbbbf')."pseudo;bio\r\n\"Zoé\";\"Hello; \"\"team\"\"\r\nnext\"\r\n\r\nAlex;ok\r\n";
        $parsed = app(PersonCsvParser::class)->parse($csv, ';', 10);
        $this->assertSame(['pseudo', 'bio'], $parsed['headers']);
        $this->assertSame(['line' => 2, 'cells' => ['Zoé', "Hello; \"team\"\nnext"]], $parsed['rows'][0]);
        $this->assertSame(5, $parsed['rows'][1]['line']);
    }

    /** @return array<string,array{string,string,int}> */
    public static function malformed(): array
    {
        return [
            'empty' => ['', ',', 10], 'no data' => ["pseudo\n", ',', 10],
            'quote open' => ["pseudo\n\"Alex", ',', 10], 'quote inside plain' => ["pseudo\nAl\"ex", ',', 10],
            'after quote' => ["pseudo\n\"Alex\"oops", ',', 10], 'bad delimiter' => ["pseudo\nAlex", '|', 10],
            'duplicate headers' => ["Pseudo,pseudo\nA,B", ',', 10], 'empty header' => [",name\nA,B", ',', 10],
            'zero byte' => ["pseudo\nA\0B", ',', 10], 'invalid utf8' => ["pseudo\n".chr(255), ',', 10],
            'mac newline' => ["pseudo\rAlex", ',', 10], 'too many rows' => ["pseudo\nA\nB", ',', 1],
            'huge field' => ["pseudo\n".str_repeat('x', 32769), ',', 10],
            'many columns' => [implode(',', range(1, 33))."\nA", ',', 10],
            'long header' => [str_repeat('x', 81)."\nA", ',', 10],
        ];
    }

    #[DataProvider('malformed')]
    public function test_malformed_files_return_localized_validation(string $csv, string $delimiter, int $limit): void
    {
        try {
            app(PersonCsvParser::class)->parse($csv, $delimiter, $limit);
            $this->fail('Malformed CSV must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('people_import.invalid_csv')], $exception->errors()['file']);
        }
    }

    public function test_wrong_width_remains_identifiable_for_row_validation(): void
    {
        $parsed = app(PersonCsvParser::class)->parse("pseudo,email\nAlex\nBob,bob@example.test,extra", ',', 10);
        $this->assertSame(['Alex'], $parsed['rows'][0]['cells']);
        $this->assertSame(3, $parsed['rows'][1]['line']);
    }
}
