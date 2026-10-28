<?php

namespace App\Modules\Performance\Application\Services;

use App\Modules\Performance\Application\Contracts\StatisticalSourceAudit;
use App\Modules\Performance\Application\Validation\ObservationInput;
use App\Modules\Performance\Domain\ObservationValueRules;
use App\Support\Files\CsvParser;
use DomainException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

/** @phpstan-import-type StatisticalIssue from StatisticalSourceAudit */
final readonly class StatisticalSourceAuditService implements StatisticalSourceAudit
{
    public function __construct(private CsvParser $parser, private Factory $validator, private ObservationValueRules $values) {}

    public function analyze(string $contents, string $delimiter, ?string $asOf, int $maximumRows = 100): array
    {
        $result = ['columns' => 0, 'row_count' => 0, 'issues' => [], 'rows' => []];
        if ($maximumRows < 1 || strlen($contents) > StatisticalImportService::MAX_BYTES) {
            $result['issues'][] = $this->issue('FILE_LIMIT');

            return $result;
        }
        try {
            $parsed = $this->parser->parse($contents, $delimiter, min($maximumRows, StatisticalImportService::MAX_ROWS));
        } catch (ValidationException) {
            $result['issues'][] = $this->issue('CSV_FORMAT_INVALID');

            return $result;
        }
        $headers = $parsed['headers'];
        $rules = ObservationInput::rules('store');
        $result['columns'] = count($headers);
        $result['row_count'] = count($parsed['rows']);
        $required = array_keys(array_filter($rules, static fn (array $rule): bool => in_array('required', $rule, true)));
        if (array_diff($headers, array_keys($rules)) !== [] || array_diff($required, $headers) !== []) {
            $result['issues'][] = $this->issue('CSV_SCHEMA_INVALID');

            return $result;
        }
        if ($asOf === null || $this->validator->make(['as_of' => $asOf], ['as_of' => ['required', 'date_format:Y-m-d']])->fails()) {
            $result['issues'][] = $this->issue('STATISTICS_CONTEXT_REQUIRED');

            return $result;
        }
        $result['issues'][] = $this->issue('STATISTICAL_REFERENCES_UNVERIFIED', severity: 'review');
        foreach ($parsed['rows'] as $record) {
            $values = array_fill_keys(array_keys($rules), null);
            $issues = count($record['cells']) === count($headers) ? [] : [$this->issue('COLUMN_COUNT')];
            foreach ($headers as $column => $field) {
                $cell = trim($record['cells'][$column] ?? '');
                $values[$field] = $cell === '' && in_array('nullable', $rules[$field], true) ? null : $cell;
                if (preg_match('/^[\s\p{Z}]*[=+@-]/u', $cell) === 1
                    && ! ($field === 'value' && preg_match('/\A-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/', $cell) === 1)) {
                    $issues[] = $this->issue('SPREADSHEET_FORMULA_RISK', $field, $column, 'review');
                }
            }
            $validation = $this->validator->make($values, $rules, ObservationInput::messages());
            foreach (array_keys($validation->errors()->messages()) as $field) {
                $issues[] = $this->issue('INVALID_FIELD', $field);
            }
            $normalized = [];
            if (! $validation->fails()) {
                try {
                    $normalized = $this->values->normalize($values);
                } catch (DomainException $exception) {
                    $issues[] = $this->issue('INVALID_FIELD', $exception->getMessage());
                }
                if ($values['status'] !== 'active') {
                    $issues[] = $this->issue('INVALID_FIELD', 'status');
                }
                if ($values['match_game_id'] !== null && $values['match_id'] === null) {
                    $issues[] = $this->issue('INVALID_FIELD', 'match_id');
                }
                if ($values['observed_on'] > $asOf) {
                    $issues[] = $this->issue('INVALID_FIELD', 'observed_on');
                }
            }
            $keys = [];
            if (! array_any($issues, static fn (array $issue): bool => $issue['severity'] === 'blocking') && is_string($values['metric_version'])) {
                $identity = [];
                foreach (['metric_id', 'person_id', 'match_id', 'match_game_id', 'source_kind', 'source_label', 'period_start', 'period_end', 'observed_on',
                    'region', 'season', 'map', 'opponent', 'round_number', 'session_label', 'mode_id', 'role_id', 'release_id'] as $field) {
                    $value = $values[$field];
                    if (is_string($value) && str_ends_with($field, '_id')) {
                        $value = strtolower($value);
                    }
                    $identity[$field] = $field === 'round_number' && $value !== null ? (int) $value : $value;
                }
                // Mirrors receipt identity and payload without resolving references or using floating point.
                $keys['statistical_identity'] = json_encode($identity, JSON_THROW_ON_ERROR);
                $keys['statistical_payload'] = json_encode([strtolower($values['metric_version']), (int) $values['sample_size'], $normalized], JSON_THROW_ON_ERROR);
            }
            $result['rows'][] = ['line' => $record['line'], 'issues' => $issues, 'keys' => $keys];
        }

        return $result;
    }

    /** @return StatisticalIssue */
    private function issue(string $code, ?string $field = null, ?int $column = null, string $severity = 'blocking'): array
    {
        return compact('code', 'field', 'column', 'severity');
    }
}
