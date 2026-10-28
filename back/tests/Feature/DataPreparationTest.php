<?php

namespace Tests\Feature;

use App\Modules\Reporting\Application\Services\DataPreparationService;
use App\Modules\Reporting\Domain\Contracts\DataSourceBundle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class DataPreparationTest extends TestCase
{
    private const string ORGANIZATION = '10000000-0000-4000-8000-000000000001';

    public function test_clean_fictitious_bundle_requires_business_review_and_does_not_touch_application_or_sources(): void
    {
        Queue::fake();
        Mail::fake();
        $path = base_path('tests/Fixtures/data-preparation/clean.manifest.json');
        $before = hash_file('sha256', base_path('tests/Fixtures/data-preparation/people-clean.csv'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame('ready_for_business_review', $report['status']);
        $this->assertSame(1, $report['summary']['read_files']);
        $this->assertSame(2, $report['summary']['people_rows']);
        $this->assertSame(0, $report['summary']['blocking_issues']);
        $this->assertSame(2, $report['sources'][0]['schema_valid_rows']);
        $this->assertFalse($report['import_authorized']);
        $this->assertFalse($report['database_checked']);
        $this->assertFalse($report['files_modified']);
        $this->assertContains('application_tenant_and_duplicates', $report['not_verified']);
        $this->assertSame($before, hash_file('sha256', base_path('tests/Fixtures/data-preparation/people-clean.csv')));
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_mixed_bundle_reports_cross_file_duplicates_missing_ownership_and_invalid_statistical_schema_without_values(): void
    {
        $report = app(DataPreparationService::class)->audit(base_path('tests/Fixtures/data-preparation/issues.manifest.json'), self::ORGANIZATION)->data;
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(3, $report['summary']['declared_files']);
        $this->assertSame(6, $report['summary']['people_rows']);
        $this->assertSame(8, $report['summary']['blocking_issues']);
        $this->assertSame(5, $report['summary']['review_issues']);
        $this->assertSame(1, $report['issue_counts']['DUPLICATE_PSEUDONYM']);
        $this->assertSame(3, $report['issue_counts']['POSSIBLE_SHARED_IDENTITY']);
        $this->assertSame(1, $report['issue_counts']['CSV_SCHEMA_INVALID']);
        $duplicate = array_values(array_filter($report['issues'], static fn (array $issue): bool => $issue['code'] === 'DUPLICATE_PSEUDONYM'))[0];
        $this->assertSame(['PEOPLE-B', 2, 'PEOPLE-A', 2], [$duplicate['source_id'], $duplicate['line'], $duplicate['related_source_id'], $duplicate['related_line']]);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['alpha@example.test', 'FICTIF_Alpha', 'fictif_alpha', 'Alpha', 'Fictif', '+21620000001', 'people-clean.csv', 'incorrect-email', 'OWNER-FICTIF-001'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $encoded);
        }
    }

    public function test_free_csv_headers_are_not_exposed_in_reports(): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0]['mapping'] = ['pseudonym' => 0];
        $path = $this->bundle($manifest);
        Storage::disk('data001')->put('people-clean.csv', "PRIVATE_HEADER<script>\nFICTIF_PRIVATE_ROW");
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame('ready_for_business_review', $report['status']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE_HEADER', $encoded);
        $this->assertStringNotContainsString('FICTIF_PRIVATE_ROW', $encoded);
    }

    public function test_manifest_organization_mismatch_is_refused_before_reading_any_source(): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0]['organization_id'] = '20000000-0000-4000-8000-000000000002';
        $files = $this->mock(DataSourceBundle::class);
        $files->shouldReceive('manifest')->once()->with('selected.json')->andReturn(['root' => 'unused', 'contents' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
        $files->shouldNotReceive('read');
        $this->expectException(ValidationException::class);
        app(DataPreparationService::class)->audit('selected.json', self::ORGANIZATION);
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return ['parent' => ['../outside.csv'], 'absolute' => ['/outside.csv'], 'drive' => ['C:/outside.csv'],
            'network' => ['//server/share.csv'], 'stream wrapper' => ['php://filter/resource=outside.csv'],
            'URL' => ['https://example.test/source.csv'], 'alternate data stream' => ['people-clean.csv:hidden'],
            'encoded parent' => ['%2e%2e/outside.csv'], 'empty segment' => ['folder//outside.csv'],
            'dot segment' => ['./people-clean.csv'], 'trailing dot' => ['people-clean.csv.'], 'missing' => ['missing.csv']];
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_or_missing_source_is_blocked_without_exposing_path(string $file): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0]['file'] = $file;
        $report = app(DataPreparationService::class)->audit($this->bundle($manifest), self::ORGANIZATION)->data;
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(1, $report['issue_counts']['FILE_UNAVAILABLE_OR_UNSAFE']);
        $this->assertSame(1, $report['summary']['unprocessed_files']);
        $this->assertStringNotContainsString($file, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, array{string}> */
    public static function invalidManifests(): array
    {
        return ['unexpected root key' => ['root'], 'unexpected source key' => ['source'], 'duplicate source ID' => ['duplicate'],
            'unknown category' => ['category'], 'unknown version' => ['version'], 'no sources' => ['empty'],
            'unknown dataset kind' => ['kind'], 'numeric mapping' => ['mapping'], 'missing ignore justification' => ['ignore'], 'too many files' => ['many']];
    }

    #[DataProvider('invalidManifests')]
    public function test_invalid_manifest_cannot_produce_a_successful_inventory(string $variant): void
    {
        $manifest = $this->manifest();
        switch ($variant) {
            case 'root': $manifest['execute'] = true;
                break;
            case 'source': $manifest['sources'][0]['execute'] = true;
                break;
            case 'duplicate': $manifest['sources'][] = $manifest['sources'][0];
                break;
            case 'category': $manifest['sources'][0]['category'] = 'arbitrary';
                break;
            case 'version': $manifest['version'] = 2;
                break;
            case 'empty': $manifest['sources'] = [];
                break;
            case 'kind': $manifest['dataset_kind'] = 'trusted';
                break;
            case 'mapping': $manifest['sources'][0]['mapping']['pseudonym'] = 'private';
                break;
            case 'ignore': $manifest['sources'][0]['ignored_columns'] = [['column' => 9]];
                break;
            case 'many':
                $source = $manifest['sources'][0];
                $manifest['sources'] = [];
                for ($index = 0; $index < 21; $index++) {
                    $manifest['sources'][] = [...$source, 'id' => 'FILE-'.$index];
                }
                break;
        }
        $path = $this->bundle($manifest);
        $this->expectException(ValidationException::class);
        app(DataPreparationService::class)->audit($path, self::ORGANIZATION);
    }

    public function test_identical_sources_are_not_silently_combined_and_report_digest_is_repeatable(): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][] = [...$manifest['sources'][0], 'id' => 'PEOPLE-B'];
        $path = $this->bundle($manifest);
        $first = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $second = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame($first, $second);
        $this->assertSame(1, $first['issue_counts']['IDENTICAL_FILE_CONTENT']);
        $this->assertSame(2, $first['issue_counts']['DUPLICATE_PSEUDONYM']);
        $this->assertFalse($first['automatic_merge']);
        $this->assertSame(4, $first['summary']['people_rows']);
    }

    public function test_large_issue_set_is_summarized_completely_with_bounded_details(): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0]['file'] = 'many.csv';
        $manifest['sources'][0]['mapping'] = ['pseudonym' => 0];
        $path = $this->bundle($manifest);
        Storage::disk('data001')->put('many.csv', "pseudo\n".str_repeat("FICTIF_REPEAT\n", 500));
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(499, $report['issue_counts']['DUPLICATE_PSEUDONYM']);
        $this->assertSame(499, $report['summary']['blocking_issues']);
        $this->assertCount(200, $report['issues']);
        $this->assertTrue($report['issues_truncated']);
    }

    public function test_bundle_byte_limit_prevents_reading_the_remaining_sources(): void
    {
        $manifest = $this->manifest();
        $source = $manifest['sources'][0];
        $manifest['sources'] = [];
        for ($index = 0; $index < 12; $index++) {
            $manifest['sources'][] = [...$source, 'id' => 'FILE-'.$index, 'category' => 'contracts', 'file' => 'large.txt'];
        }
        $path = $this->bundle($manifest);
        Storage::disk('data001')->put('large.txt', str_repeat('x', 1048576));
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(10, $report['summary']['read_files']);
        $this->assertSame(2, $report['summary']['unprocessed_files']);
        $this->assertSame(10485760, $report['summary']['bytes']);
        $this->assertSame(1, $report['issue_counts']['BUNDLE_LIMIT']);
        $this->assertFalse($report['import_authorized']);
    }

    public function test_file_larger_than_allowed_is_not_read_or_fingerprinted(): void
    {
        $manifest = $this->manifest();
        $path = $this->bundle($manifest);
        Storage::disk('data001')->put('people-clean.csv', str_repeat('x', 1048577));
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(0, $report['summary']['read_files']);
        $this->assertSame(1, $report['summary']['unprocessed_files']);
        $this->assertNull($report['sources'][0]['sha256']);
        $this->assertSame(1, $report['issue_counts']['FILE_UNAVAILABLE_OR_UNSAFE']);
    }

    public function test_validated_numeric_column_strings_are_normalized_to_import_column_indexes(): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0]['mapping']['pseudonym'] = '0';
        $report = app(DataPreparationService::class)->audit($this->bundle($manifest), self::ORGANIZATION)->data;
        $this->assertSame('ready_for_business_review', $report['status']);
        $this->assertSame([0, 1, 2, 3, 4], $report['sources'][0]['mapped_columns']);
    }

    public function test_people_spreadsheet_requires_dedicated_review_and_missing_purpose_is_not_inferred(): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0]['file'] = 'people.xlsx';
        unset($manifest['sources'][0]['purpose_reference']);
        $path = $this->bundle($manifest);
        Storage::disk('data001')->put('people.xlsx', 'Not an accepted People CSV');
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(1, $report['issue_counts']['FORMAT_REQUIRES_DEDICATED_AUDIT']);
        $this->assertSame(1, $report['issue_counts']['PURPOSE_UNRESOLVED']);
        $this->assertSame('dedicated_audit_required', $report['sources'][0]['status']);
        $this->assertSame(0, $report['summary']['people_rows']);
    }

    public function test_global_people_row_limit_stops_before_reading_another_people_source(): void
    {
        $manifest = $this->manifest();
        $source = [...$manifest['sources'][0], 'file' => 'many.csv', 'mapping' => ['pseudonym' => 0]];
        $manifest['sources'] = [];
        for ($index = 0; $index < 11; $index++) {
            $manifest['sources'][] = [...$source, 'id' => 'PEOPLE-'.$index];
        }
        $path = $this->bundle($manifest);
        Storage::disk('data001')->put('many.csv', "pseudo\n".str_repeat("FICTIF_REPEAT\n", 1000));
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(10000, $report['summary']['people_rows']);
        $this->assertSame(10, $report['summary']['read_files']);
        $this->assertSame(1, $report['summary']['unprocessed_files']);
        $this->assertSame(1, $report['issue_counts']['BUNDLE_LIMIT']);
        $this->assertSame('bundle_limit', $report['sources'][10]['status']);
    }

    public function test_source_symlink_cannot_escape_the_selected_bundle(): void
    {
        $manifest = $this->manifest();
        $manifest['sources'][0]['file'] = 'link.csv';
        $path = $this->bundle($manifest);
        Storage::disk('data001')->put('outside.csv', "pseudo\nPRIVATE_OUTSIDE");
        $root = dirname($path);
        Storage::disk('data001')->put('bundle/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $path = Storage::disk('data001')->path('bundle/manifest.json');
        if (! @symlink($root.DIRECTORY_SEPARATOR.'outside.csv', dirname($path).DIRECTORY_SEPARATOR.'link.csv')) {
            $this->markTestSkipped('This platform does not allow unprivileged fixture symlinks.');
        }
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(1, $report['issue_counts']['FILE_UNAVAILABLE_OR_UNSAFE']);
        $this->assertSame("pseudo\nPRIVATE_OUTSIDE", Storage::disk('data001')->get('outside.csv'));
        $this->assertStringNotContainsString('PRIVATE_OUTSIDE', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_console_exit_codes_distinguish_review_ready_blocked_and_invalid_manifests(): void
    {
        $this->assertSame(0, Artisan::call('data:audit', ['manifest' => base_path('tests/Fixtures/data-preparation/clean.manifest.json'), '--organization' => self::ORGANIZATION]));
        $output = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('ready_for_business_review', $output['status']);
        $this->assertFalse($output['import_authorized']);
        $this->assertSame(2, Artisan::call('data:audit', ['manifest' => base_path('tests/Fixtures/data-preparation/issues.manifest.json'), '--organization' => self::ORGANIZATION]));
        $this->assertSame('blocked', json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR)['status']);
        $this->assertSame(1, Artisan::call('data:audit', ['manifest' => base_path('tests/Fixtures/data-preparation/clean.manifest.json')]));
        $this->assertStringContainsString(__('data_audit.invalid_manifest'), Artisan::output());
    }

    public function test_unexpected_failure_is_logged_without_exception_text_or_source_path(): void
    {
        $files = $this->mock(DataSourceBundle::class);
        $files->shouldReceive('manifest')->once()->andThrow(new \RuntimeException('PRIVATE_SOURCE_VALUE'));
        $logger = $this->mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with('data.audit.failed', ['exception_type' => \RuntimeException::class]);
        $this->assertSame(1, Artisan::call('data:audit', ['manifest' => 'PRIVATE_SOURCE_PATH.json', '--organization' => self::ORGANIZATION]));
        $output = Artisan::output();
        $this->assertStringNotContainsString('PRIVATE_SOURCE', $output);
        $this->assertStringContainsString(__('data_audit.failed'), $output);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return json_decode(File::get(base_path('tests/Fixtures/data-preparation/clean.manifest.json')), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $manifest */
    private function bundle(array $manifest): string
    {
        Storage::fake('data001');
        Storage::disk('data001')->put('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        Storage::disk('data001')->put('people-clean.csv', File::get(base_path('tests/Fixtures/data-preparation/people-clean.csv')));

        return Storage::disk('data001')->path('manifest.json');
    }
}
