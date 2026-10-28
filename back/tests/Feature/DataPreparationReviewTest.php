<?php

namespace Tests\Feature;

use App\Modules\Reporting\Application\Services\DataPreparationReviewService;
use App\Modules\Reporting\Application\Services\DataPreparationService;
use App\Modules\Reporting\Domain\Contracts\DataSourceBundle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class DataPreparationReviewTest extends TestCase
{
    private const string ORGANIZATION = '10000000-0000-4000-8000-000000000001';

    public function test_even_approved_fictitious_review_waits_for_real_sources_and_never_closes_data001(): void
    {
        $path = $this->bundle();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $report = app(DataPreparationReviewService::class)->review($path, self::ORGANIZATION, 'review.json');
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame('awaiting_real_sources', $report['status']);
        $this->assertSame([], $report['pending']);
        $this->assertTrue($report['business_approval_declared']);
        foreach (['business_signature_verified', 'source_completeness_verified', 'database_checked', 'data001_closed', 'import_authorized', 'files_modified'] as $flag) {
            $this->assertFalse($report[$flag]);
        }
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('REVIEWER-FICTIF', $encoded);
        $this->assertStringNotContainsString('FICTIF Contrat', $encoded);
    }

    public function test_operator_declarations_do_not_become_an_authenticated_signature_or_database_proof(): void
    {
        $path = $this->bundle(realDeclaration: true);
        $report = app(DataPreparationReviewService::class)->review($path, self::ORGANIZATION, 'review.json');
        $this->assertSame('business_approval_declared', $report['status']);
        $this->assertFalse($report['business_signature_verified']);
        $this->assertFalse($report['database_checked']);
        $this->assertFalse($report['import_authorized']);
        $this->assertFalse($report['data001_closed']);
        $this->assertSame(0, Artisan::call('data:review', ['manifest' => $path, 'review' => 'review.json', '--organization' => self::ORGANIZATION]));
    }

    public function test_approval_declaration_cannot_override_source_validation_errors(): void
    {
        $path = $this->bundle(realDeclaration: true);
        $document = json_decode(File::get(Storage::disk('data-review')->path('contracts-clean.json')), true, flags: JSON_THROW_ON_ERROR);
        $document['records'][0]['data']['title'] = '';
        Storage::disk('data-review')->put('contracts-clean.json', json_encode($document, JSON_THROW_ON_ERROR));
        $audit = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $review = json_decode(File::get(Storage::disk('data-review')->path('review.json')), true, flags: JSON_THROW_ON_ERROR);
        $review['plan_digest'] = $audit['plan_digest'];
        Storage::disk('data-review')->put('review.json', json_encode($review, JSON_THROW_ON_ERROR));
        $report = app(DataPreparationReviewService::class)->review($path, self::ORGANIZATION, 'review.json');
        $this->assertSame('blocked', $report['status']);
        $this->assertFalse($report['import_authorized']);
        $this->assertFalse($report['data001_closed']);
    }

    /** @return array<string,array{string,string}> */
    public static function commandFailures(): array
    {
        return ['review' => ['data:review', 'data.review.failed'], 'references' => ['data:references', 'data.references.failed']];
    }

    #[DataProvider('commandFailures')]
    public function test_unexpected_failure_logs_only_exception_type_and_never_source_values(string $command, string $event): void
    {
        $files = $this->mock(DataSourceBundle::class);
        $files->shouldReceive('manifest')->once()->andThrow(new \RuntimeException('PRIVATE_SOURCE_VALUE'));
        $logger = $this->mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with($event, ['exception_type' => \RuntimeException::class]);
        $arguments = ['manifest' => 'PRIVATE_SOURCE_PATH.json', '--organization' => self::ORGANIZATION];
        if ($command === 'data:review') {
            $arguments['review'] = 'PRIVATE_REVIEW.json';
        } else {
            $arguments['checks'] = 'PRIVATE_CHECKS.json';
            $arguments['--actor'] = '1';
        }
        $this->assertSame(1, Artisan::call($command, $arguments));
        $output = Artisan::output();
        $this->assertStringNotContainsString('PRIVATE', $output);
        $this->assertStringContainsString(__('data_audit.failed'), $output);
    }

    /** @return array<string,array{string}> */
    public static function invalidReviews(): array
    {
        return ['stale digest' => ['digest'], 'foreign organization' => ['organization'], 'missing category' => ['missing'], 'duplicate category' => ['duplicate'],
            'false no source' => ['no_source'], 'false inclusion' => ['included'], 'missing check' => ['check'], 'unknown check' => ['unknown_check'],
            'unknown root field' => ['unknown'], 'invalid date' => ['date'], 'future date' => ['future'], 'missing source' => ['source'], 'duplicate source' => ['source_duplicate']];
    }

    #[DataProvider('invalidReviews')]
    public function test_review_scope_coverage_and_freshness_are_enforced(string $variant): void
    {
        $path = $this->bundle();
        $review = json_decode(File::get(Storage::disk('data-review')->path('review.json')), true, flags: JSON_THROW_ON_ERROR);
        switch ($variant) {
            case 'digest': $review['plan_digest'] = str_repeat('0', 64);
                break;
            case 'organization': $review['organization_id'] = '20000000-0000-4000-8000-000000000002';
                break;
            case 'missing': array_pop($review['categories']);
                break;
            case 'duplicate': $review['categories'][1] = $review['categories'][0];
                break;
            case 'no_source': $review['categories'][4]['state'] = 'no_source';
                break;
            case 'included': $review['categories'][0]['state'] = 'included';
                break;
            case 'check': unset($review['checks']['retention']);
                break;
            case 'unknown_check': $review['checks']['PRIVATE_UNKNOWN'] = ['state' => 'reviewed', 'evidence_reference' => 'PRIVATE-VALUE'];
                break;
            case 'unknown': $review['PRIVATE_UNKNOWN'] = 'PRIVATE_VALUE';
                break;
            case 'date': $review['reviewed_on'] = '2026-02-30';
                break;
            case 'future': $review['reviewed_on'] = '2026-10-29';
                break;
            case 'source': array_pop($review['source_reviews']);
                break;
            case 'source_duplicate': $review['source_reviews'][1] = $review['source_reviews'][0];
                break;
        }
        Storage::disk('data-review')->put('review.json', json_encode($review, JSON_THROW_ON_ERROR));
        $this->expectException(ValidationException::class);
        app(DataPreparationReviewService::class)->review($path, self::ORGANIZATION, 'review.json');
    }

    public function test_source_change_invalidates_a_previously_prepared_review(): void
    {
        $path = $this->bundle();
        $document = json_decode(File::get(Storage::disk('data-review')->path('teams-clean.json')), true, flags: JSON_THROW_ON_ERROR);
        $document['records'][0]['data']['name'] = 'FICTIF CHANGED';
        Storage::disk('data-review')->put('teams-clean.json', json_encode($document, JSON_THROW_ON_ERROR));
        $this->expectException(ValidationException::class);
        app(DataPreparationReviewService::class)->review($path, self::ORGANIZATION, 'review.json');
    }

    public function test_pending_checks_sources_and_deferred_categories_are_explicit(): void
    {
        $path = $this->bundle(realDeclaration: true);
        $review = json_decode(File::get(Storage::disk('data-review')->path('review.json')), true, flags: JSON_THROW_ON_ERROR);
        $review['checks']['references']['state'] = 'pending';
        $review['source_reviews'][0]['decision'] = 'pending';
        $review['categories'][0]['state'] = 'deferred';
        $review['decision'] = 'pending';
        Storage::disk('data-review')->put('review.json', json_encode($review, JSON_THROW_ON_ERROR));
        $report = app(DataPreparationReviewService::class)->review($path, self::ORGANIZATION, 'review.json');
        $this->assertSame('review_incomplete', $report['status']);
        $this->assertSame(['category:organization', 'source:STRUCT-1', 'check:references', 'business_signoff'], $report['pending']);
        $this->assertSame(2, Artisan::call('data:review', ['manifest' => $path, 'review' => 'review.json', '--organization' => self::ORGANIZATION]));
        $this->assertSame('review_incomplete', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status']);
    }

    public function test_console_rejects_unsafe_paths_without_exposing_them(): void
    {
        $path = $this->bundle();
        $this->assertSame(1, Artisan::call('data:review', ['manifest' => $path, 'review' => '../PRIVATE.json', '--organization' => self::ORGANIZATION]));
        $output = Artisan::output();
        $this->assertStringNotContainsString('PRIVATE', $output);
        $this->assertStringContainsString(__('data_audit.invalid_review'), $output);
    }

    private function bundle(bool $realDeclaration = false): string
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00+00:00'));
        Storage::fake('data-review');
        $manifest = json_decode(File::get(base_path('tests/Fixtures/data-preparation/structured-clean.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($realDeclaration) {
            $manifest['dataset_kind'] = 'real';
        }
        Storage::disk('data-review')->put('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        foreach ($manifest['sources'] as $source) {
            Storage::disk('data-review')->put($source['file'], File::get(base_path('tests/Fixtures/data-preparation/'.$source['file'])));
        }
        $path = Storage::disk('data-review')->path('manifest.json');
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $review = json_decode(File::get(base_path('tests/Fixtures/data-preparation/review.template.json')), true, flags: JSON_THROW_ON_ERROR);
        $review['plan_digest'] = $report['plan_digest'];
        $review['decision'] = 'approved';
        foreach ($review['checks'] as &$check) {
            $check['state'] = 'reviewed';
        }
        unset($check);
        foreach ($review['source_reviews'] as &$source) {
            $source['decision'] = 'approved';
        }
        unset($source);
        Storage::disk('data-review')->put('review.json', json_encode($review, JSON_THROW_ON_ERROR));

        return $path;
    }
}
