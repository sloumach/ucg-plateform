<?php

namespace App\Support\Files;

use Closure;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * @phpstan-type DatasetIssue array{code:string,field:?string,column:?int,severity:string}
 * @phpstan-type DatasetAnalysis array{columns:int,row_count:int,issues:list<DatasetIssue>,rows:list<array{line:int,issues:list<DatasetIssue>,keys:array<string,string>}>}
 */
final readonly class JsonDatasetAuditor
{
    public function __construct(private Factory $validator) {}

    /**
     * Record numbers, rather than physical JSON lines, are returned.
     *
     * @param  array<string,mixed>  $rules
     * @param  Closure(array<string,mixed>):array{identity:array<mixed>,payload:array<string,mixed>}  $normalize
     * @return DatasetAnalysis
     */
    public function analyze(string $contents, string $organization, array $rules, Closure $normalize, int $maximumRows): array
    {
        $result = ['columns' => 0, 'row_count' => 0, 'issues' => [], 'rows' => []];
        if (strlen($contents) > 1048576 || $maximumRows < 1) {
            $result['issues'][] = $this->issue('FILE_LIMIT');

            return $result;
        }
        try {
            $document = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $result['issues'][] = $this->issue('JSON_DATASET_INVALID');

            return $result;
        }
        $validation = $this->validator->make(['document' => $document, 'organization' => $organization], [
            'organization' => ['required', 'uuid'],
            'document' => ['required', 'array:version,organization_id,records'],
            'document.version' => ['required', 'integer', 'in:1'],
            'document.organization_id' => ['required', 'uuid', 'same:organization'],
            'document.records' => ['required', 'array', 'list', 'min:1', 'max:'.min(100, $maximumRows)],
            'document.records.*' => ['required', 'array:source_key,data'],
            'document.records.*.source_key' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_-]{1,39}$/D', 'distinct:strict'],
            'document.records.*.data' => ['required', 'array'],
        ]);
        if ($validation->fails()) {
            $result['issues'][] = $this->issue('JSON_DATASET_INVALID');

            return $result;
        }
        $document = $validation->validated()['document'];
        $roots = array_values(array_filter(array_keys($rules), static fn (string $field): bool => ! str_contains($field, '.')));
        $result['row_count'] = count($document['records']);
        $result['issues'][] = $this->issue('APPLICATION_CHECK_REQUIRED', severity: 'review');
        foreach ($document['records'] as $index => $record) {
            $data = $record['data'];
            $issues = [];
            $keys = [];
            if (array_diff(array_keys($data), $roots) !== []) {
                $issues[] = $this->issue('JSON_FIELDS_INVALID');
            } else {
                $rowValidation = $this->validator->make($data, $rules);
                foreach (array_keys($rowValidation->errors()->messages()) as $field) {
                    $root = explode('.', $field)[0];
                    $issues[] = $this->issue('INVALID_FIELD', in_array($root, $roots, true) ? $root : null);
                }
                if (! $rowValidation->fails()) {
                    try {
                        $normalized = $normalize($rowValidation->validated());
                        $keys['record_identity'] = json_encode($this->canonical($normalized['identity']), JSON_THROW_ON_ERROR);
                        $keys['record_payload'] = json_encode($this->canonical($normalized['payload']), JSON_THROW_ON_ERROR);
                    } catch (ValidationException $exception) {
                        foreach (array_keys($exception->errors()) as $field) {
                            $issues[] = $this->issue('INVALID_FIELD', in_array($field, $roots, true) ? $field : null);
                        }
                    }
                }
            }
            $this->formulas($data, $roots, $issues);
            $result['rows'][] = ['line' => $index + 1, 'issues' => $issues, 'keys' => $keys];
        }

        return $result;
    }

    /** @param array<mixed> $values
     * @return array<mixed>
     */
    public function canonical(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonical($value);
            } elseif (is_string($value)) {
                $values[$key] = trim($value);
                if (is_string($key) && (str_ends_with($key, '_id') || str_ends_with($key, '_version'))) {
                    $values[$key] = strtolower($values[$key]);
                }
            }
        }

        return $values;
    }

    /** @param array<string,mixed> $data
     * @param  list<string>  $roots
     * @param  list<DatasetIssue>  $issues
     */
    private function formulas(array $data, array $roots, array &$issues): void
    {
        foreach ($data as $field => $value) {
            $iterator = is_array($value) ? new \RecursiveIteratorIterator(new \RecursiveArrayIterator($value)) : [$value];
            foreach ($iterator as $cell) {
                if (is_string($cell) && preg_match('/^[\s\p{Z}]*[=+@-]/u', $cell) === 1) {
                    $issues[] = $this->issue('SPREADSHEET_FORMULA_RISK', in_array($field, $roots, true) ? $field : null, 'review');
                    break;
                }
            }
        }
    }

    /** @return DatasetIssue */
    private function issue(string $code, ?string $field = null, string $severity = 'blocking'): array
    {
        return ['code' => $code, 'field' => $field, 'column' => null, 'severity' => $severity];
    }
}
