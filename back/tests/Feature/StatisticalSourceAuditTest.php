<?php

namespace Tests\Feature;

use App\Modules\Performance\Application\Contracts\StatisticalSourceAudit;
use App\Support\Files\CsvParser;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatisticalSourceAuditTest extends TestCase
{
    public function test_clean_csv_preserves_zero_and_unavailable_values_without_database_lookup(): void
    {
        $analysis = app(StatisticalSourceAudit::class)->analyze(File::get(base_path('tests/Fixtures/data-preparation/statistics-clean.csv')), ',', '2026-10-28');
        $this->assertSame(2, $analysis['row_count']);
        $this->assertSame(['STATISTICAL_REFERENCES_UNVERIFIED'], array_column($analysis['issues'], 'code'));
        $this->assertSame([], $analysis['rows'][0]['issues']);
        $this->assertSame([], $analysis['rows'][1]['issues']);
        $payload = json_decode($analysis['rows'][0]['keys']['statistical_payload'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('0', $payload[2]['value']);
        $missing = json_decode($analysis['rows'][1]['keys']['statistical_payload'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($missing[2]['value']);
    }

    /** @return array<string,array{string,string,?string}> */
    public static function invalidRows(): array
    {
        return [
            'UUID' => ['person_id', 'PRIVATE_BAD_UUID', 'person_id'],
            'metric version' => ['metric_version', '', 'metric_version'],
            'decimal comma' => ['value', '1,25', 'value'],
            'scientific notation' => ['value', '1e6', 'value'],
            'precision overflow' => ['value', '1.1234567', 'value'],
            'integer overflow' => ['value', '1000000000000', 'value'],
            'zero sample' => ['sample_size', '0', 'sample_size'],
            'large sample' => ['sample_size', '1000001', 'sample_size'],
            'future date' => ['observed_on', '2026-10-29', 'observed_on'],
            'impossible date' => ['period_start', '2026-02-30', 'period_start'],
            'reversed period' => ['period_end', '2026-09-30', 'period_end'],
            'early observation' => ['observed_on', '2026-10-01', 'observed_on'],
            'available missing value' => ['value', '', 'value'],
            'unavailable with value' => ['availability', 'unavailable', 'value'],
            'available missing reason' => ['missing_reason', 'FICTIF absent', 'value'],
            'voided creation' => ['status', 'voided', 'status'],
            'trimmed reason' => ['reason', '         x         ', 'reason'],
            'part without match' => ['match_game_id', 'd0000000-0000-4000-8000-000000000001', 'match_id'],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_observation_has_no_comparison_key(string $field, string $value, ?string $expected): void
    {
        $analysis = app(StatisticalSourceAudit::class)->analyze($this->csv([$field => $value]), ',', '2026-10-28');
        $row = $analysis['rows'][0];
        $this->assertContains($expected, array_column($row['issues'], 'field'));
        $this->assertContains('INVALID_FIELD', array_column($row['issues'], 'code'));
        $this->assertSame([], $row['keys']);
    }

    /** @return array<string,array{string}> */
    public static function validDecimals(): array
    {
        return ['negative' => ['-12.340000'], 'negative zero' => ['-0.000000'], 'maximum precision' => ['999999999999.123456']];
    }

    #[DataProvider('validDecimals')]
    public function test_valid_signed_decimals_are_exact_and_not_formula_warnings(string $value): void
    {
        $result = app(StatisticalSourceAudit::class)->analyze($this->csv(['value' => $value]), ',', '2026-10-28');
        $this->assertSame([], $result['rows'][0]['issues']);
        $payload = json_decode($result['rows'][0]['keys']['statistical_payload'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($value === '-0.000000' ? '0' : ($value === '-12.340000' ? '-12.34' : $value), $payload[2]['value']);
    }

    public function test_missing_cutoff_and_incompatible_headers_are_blocking(): void
    {
        $audit = app(StatisticalSourceAudit::class);
        $this->assertSame('STATISTICS_CONTEXT_REQUIRED', $audit->analyze($this->csv(), ',', null)['issues'][0]['code']);
        $this->assertSame('STATISTICS_CONTEXT_REQUIRED', $audit->analyze($this->csv(), ',', '2026-02-30')['issues'][0]['code']);
        $this->assertSame('CSV_SCHEMA_INVALID', $audit->analyze("metric,value\nFICTIF_SCORE,1", ',', null)['issues'][0]['code']);
        $this->assertSame('CSV_SCHEMA_INVALID', $audit->analyze($this->csv(['unknown_private_header' => 'PRIVATE']), ',', '2026-10-28')['issues'][0]['code']);
    }

    public function test_limits_malformed_csv_and_width_mismatch_fail_closed(): void
    {
        $audit = app(StatisticalSourceAudit::class);
        $csv = $this->csv();
        $this->assertSame('FILE_LIMIT', $audit->analyze(str_repeat('x', 1048577), ',', '2026-10-28')['issues'][0]['code']);
        $this->assertSame('FILE_LIMIT', $audit->analyze($csv, ',', '2026-10-28', 0)['issues'][0]['code']);
        $this->assertSame('CSV_FORMAT_INVALID', $audit->analyze("x,x\n1,2", ',', '2026-10-28')['issues'][0]['code']);
        $lines = explode("\n", trim($csv));
        $this->assertSame('CSV_FORMAT_INVALID', $audit->analyze($lines[0]."\n".str_repeat($lines[1]."\n", 101), ',', '2026-10-28')['issues'][0]['code']);
        $this->assertSame('CSV_FORMAT_INVALID', $audit->analyze($lines[0]."\n".str_repeat($lines[1]."\n", 2), ',', '2026-10-28', 1)['issues'][0]['code']);
        $width = $audit->analyze(trim($csv).",extra\n", ',', '2026-10-28');
        $this->assertContains('COLUMN_COUNT', array_column($width['rows'][0]['issues'], 'code'));
        $this->assertSame([], $width['rows'][0]['keys']);
    }

    public function test_formula_warning_is_redacted_and_physical_line_is_preserved(): void
    {
        $csv = $this->csv(['notes' => "FICTIF\nnote", 'source_label' => '=PRIVATE()']);
        $csv .= substr($csv, strpos($csv, "\n") + 1);
        $analysis = app(StatisticalSourceAudit::class)->analyze($csv, ',', '2026-10-28');
        $this->assertSame(2, $analysis['rows'][0]['line']);
        $this->assertSame(4, $analysis['rows'][1]['line']);
        $this->assertSame('SPREADSHEET_FORMULA_RISK', $analysis['rows'][0]['issues'][0]['code']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($analysis['rows'][0]['issues'], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,string> $changes */
    private function csv(array $changes = []): string
    {
        $parsed = app(CsvParser::class)->parse(File::get(base_path('tests/Fixtures/data-preparation/statistics-clean.csv')), ',', 100);
        $row = array_replace(array_combine($parsed['headers'], $parsed['rows'][0]['cells']), $changes);
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fputcsv($stream, array_keys($row), escape: '');
        fputcsv($stream, array_values($row), escape: '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }
}
