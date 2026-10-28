<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\PersonSourceAudit;
use App\Modules\People\Application\Validation\PersonImportInput;
use App\Modules\People\Application\Validation\PersonInput;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

/** @phpstan-import-type SourceIssue from PersonSourceAudit */
final readonly class PersonSourceAuditService implements PersonSourceAudit
{
    public function __construct(private PersonCsvParser $parser, private Factory $validator) {}

    public function analyze(string $contents, string $delimiter, array $mapping, array $defaults, array $ignored, int $maximumRows = 1000): array
    {
        if ($maximumRows < 1 || strlen($contents) > min(1048576, max(1, (int) config('person_imports.max_bytes', 1048576)))) {
            return ['columns' => 0, 'row_count' => 0, 'issues' => [$this->issue('FILE_LIMIT')], 'rows' => []];
        }
        try {
            $parsed = $this->parser->parse($contents, $delimiter, min($maximumRows, 1000, max(1, (int) config('person_imports.max_rows', 1000))));
        } catch (ValidationException) {
            return ['columns' => 0, 'row_count' => 0, 'issues' => [$this->issue('CSV_FORMAT_INVALID')], 'rows' => []];
        }
        $columns = count($parsed['headers']);
        $rules = PersonImportInput::mappingRules();
        unset($rules['version']);
        $validation = $this->validator->make(['mapping' => $mapping, 'defaults' => $defaults], $rules, PersonInput::messages());
        $ignoredColumns = array_column($ignored, 'column');
        if ($validation->fails() || count(array_unique($mapping)) !== count($mapping)
            || count(array_unique($ignoredColumns)) !== count($ignoredColumns)
            || array_intersect(array_values($mapping), $ignoredColumns) !== []
            || count(array_filter([...array_values($mapping), ...$ignoredColumns], static fn (int $column): bool => $column < 0 || $column >= $columns)) > 0) {
            return ['columns' => $columns, 'row_count' => count($parsed['rows']), 'issues' => [$this->issue('MAPPING_INVALID')], 'rows' => []];
        }
        $issues = [];
        foreach ($parsed['headers'] as $column => $header) {
            if (! in_array($column, $mapping, true) && ! in_array($column, $ignoredColumns, true)) {
                $issues[] = $this->issue('UNMAPPED_COLUMN', null, $column);
            }
            if ($this->formula($header)) {
                $issues[] = $this->issue('SPREADSHEET_FORMULA_RISK', null, $column, 'review');
            }
        }
        foreach ($ignoredColumns as $column) {
            $issues[] = $this->issue('IGNORED_COLUMN_REQUIRES_REVIEW', null, $column, 'review');
        }
        $rows = [];
        foreach ($parsed['rows'] as $record) {
            $values = $defaults;
            $rowIssues = count($record['cells']) === $columns ? [] : [$this->issue('COLUMN_COUNT')];
            foreach ($mapping as $field => $column) {
                $value = trim($record['cells'][$column] ?? '');
                $values[$field] = $value === '' && ! in_array($field, ['pseudonym', 'language', 'timezone', 'status'], true) ? null : $value;
            }
            $validation = $this->validator->make($values, PersonInput::rules(true), PersonInput::messages());
            foreach (array_keys($validation->errors()->messages()) as $field) {
                $rowIssues[] = $this->issue('INVALID_FIELD', $field);
            }
            foreach ($record['cells'] as $column => $value) {
                $field = array_search($column, $mapping, true);
                if ($this->formula($value) && ! ($field === 'contact_phone' && preg_match('/^\+?[0-9 ()-]{3,32}$/D', trim($value)) === 1)) {
                    $rowIssues[] = $this->issue('SPREADSHEET_FORMULA_RISK', $field === false ? null : $field, $column, 'review');
                }
            }
            $keys = [];
            foreach (['pseudonym' => 'pseudonym', 'contact_email' => 'email', 'contact_phone' => 'phone'] as $field => $key) {
                if (is_string($values[$field] ?? null) && trim($values[$field]) !== '' && ! $validation->errors()->has($field)) {
                    $keys[$key] = mb_strtolower(trim($values[$field]));
                    if ($key === 'phone') {
                        $keys[$key] = str_replace([' ', '(', ')', '-'], '', $keys[$key]);
                    }
                }
            }
            if (is_string($values['first_name'] ?? null) && is_string($values['last_name'] ?? null)
                && trim($values['first_name']) !== '' && trim($values['last_name']) !== ''
                && ! $validation->errors()->has('first_name') && ! $validation->errors()->has('last_name')) {
                $keys['name'] = mb_strtolower(trim($values['first_name']))."\0".mb_strtolower(trim($values['last_name']));
            }
            $rows[] = ['line' => $record['line'], 'issues' => $rowIssues, 'keys' => $keys];
        }

        return ['columns' => $columns, 'row_count' => count($parsed['rows']), 'issues' => $issues, 'rows' => $rows];
    }

    private function formula(string $value): bool
    {
        return preg_match('/^[\s\p{Z}]*[=+@-]/u', $value) === 1;
    }

    /** @return SourceIssue */
    private function issue(string $code, ?string $field = null, ?int $column = null, string $severity = 'blocking'): array
    {
        return compact('code', 'field', 'column', 'severity');
    }
}
