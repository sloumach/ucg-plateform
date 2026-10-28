<?php

namespace Tests\Feature;

use App\Modules\Reporting\Application\Services\DataPreparationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StructuredDataPreparationTest extends TestCase
{
    private const string ORGANIZATION = '10000000-0000-4000-8000-000000000001';

    public function test_structured_fictitious_bundle_is_redacted_read_only_and_not_an_import(): void
    {
        Queue::fake();
        Mail::fake();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $report = app(DataPreparationService::class)->audit(base_path('tests/Fixtures/data-preparation/structured-clean.manifest.json'), self::ORGANIZATION)->data;
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame('ready_for_business_review', $report['status']);
        $this->assertSame(3, $report['summary']['structured_rows']);
        $this->assertSame(3, $report['summary']['review_issues']);
        $this->assertSame([1, 1, 1], array_column($report['sources'], 'schema_valid_rows'));
        $this->assertSame(['record_number', 'record_number', 'record_number'], array_column($report['sources'], 'position_kind'));
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['FICTIF Equipe', 'FICTIF Rencontre', 'FICTIF Contrat', 'FICTIF Personne', 'fictif-player-01', 'a0000000', 'b0000000', 'teams-clean.json'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertFalse($report['import_authorized']);
        $this->assertFalse($report['database_checked']);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    /** @return array<string,array{string,string,mixed}> */
    public static function invalidRecords(): array
    {
        return [
            'team UUID' => ['teams', 'game_id', 'bad'],
            'team category required' => ['teams', 'kind', 'custom'],
            'team category forbidden' => ['teams', 'custom_category', 'Private category'],
            'team slug' => ['teams', 'slug', '../INVALID'],
            'team text' => ['teams', 'name', '   '],
            'team tenant injection' => ['teams', 'organization_id', self::ORGANIZATION],
            'team unknown' => ['teams', 'PRIVATE_UNKNOWN_FIELD', 'PRIVATE_VALUE'],
            'match end' => ['matches', 'ends_at', '2026-10-01T11:00:00+00:00'],
            'match offset' => ['matches', 'starts_at', '2026-10-01T12:00:00+02:00'],
            'match timezone' => ['matches', 'origin_timezone', 'PRIVATE_BAD_TIMEZONE'],
            'match participants count' => ['matches', 'participants', []],
            'match nested unknown' => ['matches', 'participants', [['kind' => 'team', 'source_id' => self::ORGANIZATION, 'PRIVATE_UNKNOWN' => true], ['kind' => 'person', 'source_id' => self::ORGANIZATION]]],
            'match duplicate participants' => ['matches', 'participants', [['kind' => 'team', 'source_id' => self::ORGANIZATION], ['kind' => 'team', 'source_id' => self::ORGANIZATION]]],
            'contract dates' => ['contracts', 'ends_on', '2026-09-30'],
            'contract party count' => ['contracts', 'parties', []],
            'contract external person link' => ['contracts', 'parties', [['kind' => 'external', 'person_id' => self::ORGANIZATION, 'name' => 'FICTIF PRIVATE']]],
            'contract parties duplicate' => ['contracts', 'parties', [['kind' => 'person', 'person_id' => self::ORGANIZATION, 'name' => 'FICTIF A'], ['kind' => 'person', 'person_id' => self::ORGANIZATION, 'name' => 'FICTIF B']]],
            'contract signature bypass' => ['contracts', 'status', 'signed'],
            'contract nested unknown' => ['contracts', 'parties', [['kind' => 'external', 'name' => 'FICTIF PRIVATE', 'PRIVATE_UNKNOWN' => 'PRIVATE_VALUE']]],
        ];
    }

    #[DataProvider('invalidRecords')]
    public function test_invalid_structured_record_is_blocked_without_leaking_values(string $category, string $field, mixed $value): void
    {
        $document = $this->document($category);
        $document['records'][0]['data'][$field] = $value;
        $report = $this->auditDocument($category, $document);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(0, $report['sources'][0]['schema_valid_rows']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($report, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,array{string}> */
    public static function categories(): array
    {
        return ['teams' => ['teams'], 'matches' => ['matches'], 'contracts' => ['contracts']];
    }

    #[DataProvider('categories')]
    public function test_cross_file_same_identity_distinguishes_duplicate_from_conflict(string $category): void
    {
        $document = $this->document($category);
        $path = $this->bundle($category, $document, true);
        $second = $document;
        $second['records'][0]['source_key'] = 'SECOND-PRIVATE-KEY';
        $second['records'][0]['data'] = array_reverse($second['records'][0]['data'], true);
        Storage::disk('structured-data')->put('second.json', json_encode($second, JSON_THROW_ON_ERROR));
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(1, $report['issue_counts']['DUPLICATE_RECORD']);
        $second['records'][0]['data'][$category === 'teams' ? 'name' : 'title'] = 'FICTIF different private title';
        Storage::disk('structured-data')->put('second.json', json_encode($second, JSON_THROW_ON_ERROR));
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(1, $report['issue_counts']['CONFLICTING_RECORD']);
        $this->assertArrayNotHasKey('DUPLICATE_RECORD', $report['issue_counts']);
    }

    /** @return array<string,array{string}> */
    public static function invalidDocuments(): array
    {
        return ['foreign organization' => ['foreign'], 'unknown root key' => ['root'], 'duplicate source key' => ['key'], 'too many rows' => ['limit'], 'no rows' => ['empty'], 'bad JSON' => ['json'], 'too deep' => ['depth']];
    }

    #[DataProvider('invalidDocuments')]
    public function test_document_scope_shape_and_resource_limits_fail_closed(string $variant): void
    {
        $document = $this->document('teams');
        match ($variant) {
            'foreign' => $document['organization_id'] = '20000000-0000-4000-8000-000000000002',
            'root' => $document['PRIVATE_UNKNOWN'] = true,
            'key' => $document['records'][] = $document['records'][0],
            'limit' => $document['records'] = array_fill(0, 101, $document['records'][0]),
            'empty' => $document['records'] = [],
            default => null,
        };
        $path = $this->bundle('teams', $document);
        if ($variant === 'json' || $variant === 'depth') {
            Storage::disk('structured-data')->put('source.json', $variant === 'json' ? '{"bad":' : str_repeat('[', 33).str_repeat(']', 33));
        }
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame('blocked', $report['status']);
        $this->assertSame(1, $report['issue_counts']['JSON_DATASET_INVALID']);
    }

    public function test_unsupported_formats_and_person_mapping_are_not_accepted_for_structured_sources(): void
    {
        $document = $this->document('teams');
        $path = $this->bundle('teams', $document);
        $manifest = json_decode(File::get(Storage::disk('structured-data')->path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $manifest['sources'][0]['mapping'] = ['name' => 0];
        Storage::disk('structured-data')->put('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(1, $report['issue_counts']['MAPPING_INVALID']);
        unset($manifest['sources'][0]['mapping']);
        $manifest['sources'][0]['file'] = 'source.xlsx';
        Storage::disk('structured-data')->put('source.xlsx', 'FICTIF private workbook');
        Storage::disk('structured-data')->put('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $report = app(DataPreparationService::class)->audit($path, self::ORGANIZATION)->data;
        $this->assertSame(1, $report['issue_counts']['FORMAT_REQUIRES_DEDICATED_AUDIT']);
    }

    public function test_formula_like_structured_text_requires_review_without_exposing_the_cell(): void
    {
        $document = $this->document('contracts');
        $document['records'][0]['data']['parties'][0]['name'] = '=FICTIF_PRIVATE_FORMULA()';
        $report = $this->auditDocument('contracts', $document);
        $this->assertSame('ready_for_business_review', $report['status']);
        $this->assertSame(1, $report['issue_counts']['SPREADSHEET_FORMULA_RISK']);
        $this->assertStringNotContainsString('FICTIF_PRIVATE_FORMULA', json_encode($report, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function document(string $category): array
    {
        return json_decode(File::get(base_path('tests/Fixtures/data-preparation/'.$category.'-clean.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $document
     * @return array<string,mixed> */
    private function auditDocument(string $category, array $document): array
    {
        return app(DataPreparationService::class)->audit($this->bundle($category, $document), self::ORGANIZATION)->data;
    }

    /** @param array<string,mixed> $document */
    private function bundle(string $category, array $document, bool $second = false): string
    {
        Storage::fake('structured-data');
        $manifest = json_decode(File::get(base_path('tests/Fixtures/data-preparation/structured-clean.manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $source = array_values(array_filter($manifest['sources'], static fn (array $source): bool => $source['category'] === $category))[0];
        $source['file'] = 'source.json';
        $manifest['sources'] = [$source];
        if ($second) {
            $manifest['sources'][] = [...$source, 'id' => 'SECOND-SOURCE', 'file' => 'second.json'];
        }
        Storage::disk('structured-data')->put('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        Storage::disk('structured-data')->put('source.json', json_encode($document, JSON_THROW_ON_ERROR));

        return Storage::disk('structured-data')->path('manifest.json');
    }
}
