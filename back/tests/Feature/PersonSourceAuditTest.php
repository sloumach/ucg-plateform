<?php

namespace Tests\Feature;

use App\Modules\People\Application\Contracts\PersonSourceAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonSourceAuditTest extends TestCase
{
    public function test_explicit_mapping_reuses_person_rules_and_preserves_physical_line_numbers(): void
    {
        $analysis = $this->analyze("pseudo;email;bio\n\"FICTIF_A\";a@example.test;\"deux\nlignes\"\nFICTIF_B;incorrect;texte", ['pseudonym' => 0, 'contact_email' => 1, 'biography_public' => 2], ';');

        $this->assertSame(2, $analysis['row_count']);
        $this->assertSame(2, $analysis['rows'][0]['line']);
        $this->assertSame(4, $analysis['rows'][1]['line']);
        $this->assertSame([], $analysis['rows'][0]['issues']);
        $this->assertSame('INVALID_FIELD', $analysis['rows'][1]['issues'][0]['code']);
        $this->assertSame('contact_email', $analysis['rows'][1]['issues'][0]['field']);
        $this->assertArrayNotHasKey('email', $analysis['rows'][1]['keys']);
    }

    /** @return array<string, array{string}> */
    public static function invalidCsv(): array
    {
        return ['empty' => [''], 'unclosed quote' => ["pseudo\n\"FICTIF_A"], 'invalid utf8' => ["pseudo\n".chr(255)],
            'duplicate headers' => ["pseudo,PSEUDO\nFICTIF_A,FICTIF_B"], 'too many rows' => ["pseudo\n".str_repeat("FICTIF_A\n", 1001)]];
    }

    #[DataProvider('invalidCsv')]
    public function test_invalid_csv_is_a_redacted_file_issue(string $contents): void
    {
        $analysis = $this->analyze($contents);
        $this->assertSame('CSV_FORMAT_INVALID', $analysis['issues'][0]['code']);
        $this->assertSame([], $analysis['rows']);
    }

    public function test_unknown_unmapped_columns_and_missing_cells_are_reported_without_discarding_them(): void
    {
        $analysis = $this->analyze("pseudo,autre\nFICTIF_A,valeur\nFICTIF_B");
        $this->assertSame('UNMAPPED_COLUMN', $analysis['issues'][0]['code']);
        $this->assertSame(1, $analysis['issues'][0]['column']);
        $this->assertSame('COLUMN_COUNT', $analysis['rows'][1]['issues'][0]['code']);
    }

    /** @return array<string, array{array<string,int>, array<string,string>, list<array{column:int,reason_reference:string}>}> */
    public static function invalidMappings(): array
    {
        $defaults = ['language' => 'fr', 'timezone' => 'UTC', 'status' => 'candidate'];

        return ['missing pseudonym' => [[], $defaults, []],
            'immutable account field' => [['pseudonym' => 0, 'user_id' => 1], $defaults, []],
            'duplicate column' => [['pseudonym' => 0, 'contact_email' => 0], $defaults, []],
            'column outside file' => [['pseudonym' => 0, 'contact_email' => 3], $defaults, []],
            'missing defaults' => [['pseudonym' => 0], [], []],
            'invalid default timezone' => [['pseudonym' => 0], [...$defaults, 'timezone' => 'Not/AZone'], []],
            'mapped ignored overlap' => [['pseudonym' => 0], $defaults, [['column' => 0, 'reason_reference' => 'REVIEW-001']]],
            'repeated ignored column' => [['pseudonym' => 0], $defaults, [['column' => 1, 'reason_reference' => 'REVIEW-001'], ['column' => 1, 'reason_reference' => 'REVIEW-002']]]];
    }

    /** @param array<string,int> $mapping
     * @param  array<string,string>  $defaults
     * @param  list<array{column:int,reason_reference:string}>  $ignored
     */
    #[DataProvider('invalidMappings')]
    public function test_unsafe_mapping_never_validates_rows(array $mapping, array $defaults, array $ignored): void
    {
        $analysis = app(PersonSourceAudit::class)->analyze("pseudo,email\nFICTIF_A,a@example.test", ',', $mapping, $defaults, $ignored);
        $this->assertSame('MAPPING_INVALID', $analysis['issues'][0]['code']);
        $this->assertSame([], $analysis['rows']);
    }

    public function test_spreadsheet_formulas_are_flagged_but_valid_international_phone_numbers_are_allowed(): void
    {
        $analysis = $this->analyze("pseudo,telephone,note\nFICTIF_A,+216 20 000 001,=HYPERLINK(\"\"x\"\")",
            ['pseudonym' => 0, 'contact_phone' => 1], ignored: [['column' => 2, 'reason_reference' => 'REVIEW-001']]);
        // Quotes in an unquoted cell are invalid CSV; the same content must not become executable data.
        $this->assertSame('CSV_FORMAT_INVALID', $analysis['issues'][0]['code']);
        $analysis = $this->analyze("pseudo,telephone,note\nFICTIF_A,+216 20 000 001,=1+1",
            ['pseudonym' => 0, 'contact_phone' => 1], ignored: [['column' => 2, 'reason_reference' => 'REVIEW-001']]);
        $this->assertSame('IGNORED_COLUMN_REQUIRES_REVIEW', $analysis['issues'][0]['code']);
        $this->assertCount(1, $analysis['rows'][0]['issues']);
        $this->assertSame('SPREADSHEET_FORMULA_RISK', $analysis['rows'][0]['issues'][0]['code']);
        $this->assertSame(2, $analysis['rows'][0]['issues'][0]['column']);
        $this->assertSame('+21620000001', $analysis['rows'][0]['keys']['phone']);
    }

    /** @param array<string,int> $mapping
     * @param  list<array{column:int,reason_reference:string}>  $ignored
     * @return array{columns:int,row_count:int,issues:list<array{code:string,field:?string,column:?int,severity:string}>,rows:list<array{line:int,issues:list<array{code:string,field:?string,column:?int,severity:string}>,keys:array<string,string>}>}
     */
    private function analyze(string $contents, array $mapping = ['pseudonym' => 0], string $delimiter = ',', array $ignored = []): array
    {
        return app(PersonSourceAudit::class)->analyze($contents, $delimiter, $mapping, ['language' => 'fr', 'timezone' => 'UTC', 'status' => 'candidate'], $ignored);
    }
}
