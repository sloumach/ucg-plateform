<?php

namespace Tests\Feature;

use App\Modules\Reporting\Application\Services\DataPreparationService;
use App\Modules\Reporting\Domain\Contracts\DataSourceBundle;
use App\Support\Files\CsvParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatisticalDataPreparationTest extends TestCase
{
    private const string ORGANIZATION = '10000000-0000-4000-8000-000000000001';

    public function test_statistical_audit_is_read_only_and_never_authorizes_import(): void
    {
        Queue::fake();
        Mail::fake();
        $file = base_path('tests/Fixtures/data-preparation/statistics-clean.csv');
        $before = hash_file('sha256', $file);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $report = app(DataPreparationService::class)->audit(base_path('tests/Fixtures/data-preparation/statistics-clean.manifest.json'), self::ORGANIZATION)->data;
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame('ready_for_business_review', $report['status']);
        $this->assertSame(0, $report['summary']['people_rows']);
        $this->assertSame(2, $report['summary']['statistics_rows']);
        $this->assertSame(2, $report['sources'][0]['schema_valid_rows']);
        $this->assertSame(1, $report['summary']['review_issues']);
        $this->assertSame('statistics_csv_audited', $report['sources'][0]['status']);
        foreach (['import_authorized', 'database_checked', 'files_modified', 'automatic_merge'] as $flag) {
            $this->assertFalse($report[$flag]);
        }
        $this->assertContains('statistical_references_and_metric_rules', $report['not_verified']);
        $this->assertSame($before, hash_file('sha256', $file));
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_duplicate_and_conflicting_observations_are_reported_without_source_values(): void
    {
        $report = app(DataPreparationService::class)->audit(base_path('tests/Fixtures/data-preparation/statistics-issues.manifest.json'), self::ORGANIZATION)->data;
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(5, $report['summary']['statistics_rows']);
        $this->assertSame(1, $report['issue_counts']['DUPLICATE_OBSERVATION']);
        $this->assertSame(1, $report['issue_counts']['CONFLICTING_OBSERVATION']);
        $duplicate = array_values(array_filter($report['issues'], static fn (array $issue): bool => $issue['code'] === 'DUPLICATE_OBSERVATION'))[0];
        $this->assertSame(['STATS-B', 2, 'STATS-A', 2], [$duplicate['source_id'], $duplicate['line'], $duplicate['related_source_id'], $duplicate['related_line']]);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['FICTIF_SESSION_SECRET', 'FICTIF_INVALID_UUID', '=SUM', 'c0000000', 'a0000000', 'b0000000', '2026-10-03', 'statistics-errors.csv', 'OWNER-FICTIF-001', 'FICTIF valeur absente'] as $value) {
            $this->assertStringNotContainsString($value, $encoded);
        }
    }

    public function test_mixed_people_and_statistics_keep_separate_totals(): void
    {
        $manifest = $this->manifest();
        $people = json_decode(File::get(base_path('tests/Fixtures/data-preparation/clean.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $manifest['sources'][] = $people['sources'][0];
        $path = $this->bundle($manifest);
        Storage::disk('statistical-audit')->put('people-clean.csv', File::get(base_path('tests/Fixtures/data-preparation/people-clean.csv')));
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame('ready_for_business_review', $report['status']);
        $this->assertSame(2, $report['summary']['people_rows']);
        $this->assertSame(2, $report['summary']['statistics_rows']);
    }

    /** @return array<string,array{string}> */
    public static function forbiddenSettings(): array
    {
        return ['mapping' => ['mapping'], 'defaults' => ['defaults'], 'ignored columns' => ['ignored_columns']];
    }

    #[DataProvider('forbiddenSettings')]
    public function test_statistics_never_silently_maps_defaults_or_discards_columns(string $setting): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0][$setting] = match ($setting) {
            'mapping' => ['value' => 0],
            'defaults' => ['status' => 'active'],
            default => [['column' => 0, 'reason_reference' => 'REVIEW-FICTIF-001']],
        };
        $report = app(DataPreparationService::class)->audit($this->bundle($manifest), self::ORGANIZATION)->data;
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(1, $report['issue_counts']['MAPPING_INVALID']);
        $this->assertSame(0, $report['summary']['statistics_rows']);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalidContexts(): array
    {
        return ['missing cutoff' => [[]], 'unknown key' => [['as_of' => '2026-10-28', 'execute' => true]], 'invalid date' => [['as_of' => '2026-02-30']]];
    }

    /** @param array<string,mixed> $context */
    #[DataProvider('invalidContexts')]
    public function test_invalid_context_is_rejected_before_source_read(array $context): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0]['statistics_context'] = $context;
        $files = $this->mock(DataSourceBundle::class);
        $files->shouldReceive('manifest')->once()->andReturn(['root' => 'unused', 'contents' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
        $files->shouldNotReceive('read');
        $this->expectException(ValidationException::class);
        app(DataPreparationService::class)->audit('selected.json', self::ORGANIZATION);
    }

    public function test_missing_context_is_a_blocking_report_and_console_uses_exit_two(): void
    {
        $manifest = $this->manifest();
        unset($manifest['sources'][0]['statistics_context']);
        $path = $this->bundle($manifest);
        $this->assertSame(2, Artisan::call('data:audit', ['manifest' => $path, '--organization' => self::ORGANIZATION]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['issue_counts']['STATISTICS_CONTEXT_REQUIRED']);
        $this->assertSame(0, $report['sources'][0]['schema_valid_rows']);
    }

    public function test_reference_case_header_order_and_exact_decimal_normalization_detect_same_observation(): void
    {
        $manifest = $this->manifest();
        $second = $manifest['sources'][0];
        $second['id'] = 'STATS-B';
        $second['file'] = 'second.csv';
        $manifest['sources'][] = $second;
        $path = $this->bundle($manifest);
        $parsed = app(CsvParser::class)->parse(File::get(base_path('tests/Fixtures/data-preparation/statistics-clean.csv')), ',', 100);
        $row = array_combine($parsed['headers'], $parsed['rows'][0]['cells']);
        $row['person_id'] = strtoupper($row['person_id']);
        $row['metric_id'] = strtoupper($row['metric_id']);
        $row['metric_version'] = strtoupper($row['metric_version']);
        $row['value'] = '-0.000';
        $row['reason'] = 'FICTIF autre justification';
        $row = array_reverse($row, true);
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fputcsv($stream, array_keys($row), escape: '');
        fputcsv($stream, array_values($row), escape: '');
        rewind($stream);
        Storage::disk('statistical-audit')->put('second.csv', stream_get_contents($stream));
        fclose($stream);
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(1, $report['issue_counts']['DUPLICATE_OBSERVATION']);
        $this->assertArrayNotHasKey('CONFLICTING_OBSERVATION', $report['issue_counts']);
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return json_decode(File::get(base_path('tests/Fixtures/data-preparation/statistics-clean.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $manifest */
    private function bundle(array $manifest): string
    {
        Storage::fake('statistical-audit');
        Storage::disk('statistical-audit')->put('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        Storage::disk('statistical-audit')->put('statistics-clean.csv', File::get(base_path('tests/Fixtures/data-preparation/statistics-clean.csv')));

        return Storage::disk('statistical-audit')->path('manifest.json');
    }
}
