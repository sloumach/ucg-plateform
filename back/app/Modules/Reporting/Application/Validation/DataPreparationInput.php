<?php

namespace App\Modules\Reporting\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class DataPreparationInput
{
    public const int MAX_FILES = 20;

    public const int MAX_FILE_BYTES = 1048576;

    public const int MAX_TOTAL_BYTES = 10485760;

    public const int MAX_ROWS = 10000;

    public const int MAX_ISSUES = 200;

    public const array CATEGORIES = ['organization', 'history', 'people', 'people_imports', 'teams', 'games', 'opponents', 'matches', 'competitions', 'contracts', 'statistics', 'academy', 'calendar', 'communication', 'sponsors', 'media', 'reporting', 'artifacts'];

    public function __construct(private Factory $validator) {}

    /** @return array{version: int, organization_id: string, dataset_kind: string, sources: list<array{id: string, organization_id: string, category: string, file: string, owner_reference: ?string, purpose_reference: ?string, retention_reference: ?string, delimiter: string, mapping: array<string, int>, defaults: array<string, string>, statistics_context: ?array{as_of:string}, ignored_columns: list<array{column: int, reason_reference: string}>}>} */
    public function parse(string $contents, string $organization): array
    {
        try {
            $value = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['manifest' => __('data_audit.invalid_manifest')]);
        }
        if (! is_array($value)) {
            throw ValidationException::withMessages(['manifest' => __('data_audit.invalid_manifest')]);
        }
        $reference = ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._:-]{1,63}$/D'];
        $data = $this->validator->make(['manifest' => $value, 'organization' => $organization], [
            'organization' => ['required', 'uuid'],
            'manifest' => ['required', 'array:version,organization_id,dataset_kind,sources'],
            'manifest.version' => ['required', 'integer', Rule::in([1])],
            'manifest.organization_id' => ['required', 'uuid', 'same:organization'],
            'manifest.dataset_kind' => ['required', Rule::in(['fictitious', 'real'])],
            'manifest.sources' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_FILES],
            'manifest.sources.*' => ['required', 'array:id,organization_id,category,file,owner_reference,purpose_reference,retention_reference,delimiter,mapping,defaults,ignored_columns,statistics_context'],
            'manifest.sources.*.statistics_context' => ['sometimes', 'array:as_of', 'required_array_keys:as_of'],
            'manifest.sources.*.statistics_context.as_of' => ['required_with:manifest.sources.*.statistics_context', 'date_format:Y-m-d'],
            'manifest.sources.*.id' => ['required', 'string', 'max:40', 'regex:/^[A-Z][A-Z0-9_-]{1,39}$/D', 'distinct:strict'],
            'manifest.sources.*.organization_id' => ['required', 'uuid', 'same:organization'],
            'manifest.sources.*.category' => ['required', Rule::in(self::CATEGORIES)],
            'manifest.sources.*.file' => ['required', 'string', 'max:240'],
            'manifest.sources.*.owner_reference' => $reference,
            'manifest.sources.*.purpose_reference' => $reference,
            'manifest.sources.*.retention_reference' => $reference,
            'manifest.sources.*.delimiter' => ['sometimes', Rule::in([',', ';'])],
            'manifest.sources.*.mapping' => ['sometimes', 'array', 'max:32'],
            'manifest.sources.*.mapping.*' => ['integer', 'min:0', 'max:31'],
            'manifest.sources.*.defaults' => ['sometimes', 'array:language,timezone,status'],
            'manifest.sources.*.defaults.*' => ['string', 'max:64'],
            'manifest.sources.*.ignored_columns' => ['sometimes', 'array', 'list', 'max:32'],
            'manifest.sources.*.ignored_columns.*' => ['array:column,reason_reference'],
            'manifest.sources.*.ignored_columns.*.column' => ['required', 'integer', 'min:0', 'max:31'],
            'manifest.sources.*.ignored_columns.*.reason_reference' => ['required', ...$reference],
        ])->validate()['manifest'];
        $sources = [];
        foreach ($data['sources'] as $source) {
            $sources[] = ['id' => $source['id'], 'organization_id' => $source['organization_id'], 'category' => $source['category'],
                'file' => $source['file'], 'owner_reference' => $source['owner_reference'] ?? null,
                'purpose_reference' => $source['purpose_reference'] ?? null, 'retention_reference' => $source['retention_reference'] ?? null,
                'delimiter' => $source['delimiter'] ?? ',',
                'statistics_context' => $source['statistics_context'] ?? null,
                'mapping' => array_map(static fn ($column): int => (int) $column, $source['mapping'] ?? []), 'defaults' => $source['defaults'] ?? [],
                'ignored_columns' => array_values(array_map(static fn (array $ignored): array => ['column' => (int) $ignored['column'], 'reason_reference' => $ignored['reason_reference']], $source['ignored_columns'] ?? []))];
        }

        return ['version' => 1, 'organization_id' => $data['organization_id'], 'dataset_kind' => $data['dataset_kind'], 'sources' => $sources];
    }
}
