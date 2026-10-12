<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\PersonImportQueue;
use App\Modules\People\Application\Validation\PersonImportInput as Input;
use App\Modules\People\Application\Validation\PersonInput;
use App\Modules\People\Domain\Contracts\PersonImportRepository;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\People\Domain\Models\PersonImportRow;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantResourceLimits;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class PersonImportService
{
    public function __construct(private PersonImportRepository $imports, private PersonCsvParser $parser,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantArtifacts $artifacts, private TenantResourceLimits $limits,
        private ConnectionInterface $database, private Factory $validator, private Encrypter $crypt,
        private PersonImportQueue $queue, private TenantAuditJournal $audit) {}

    public function authorize(TenantContext $context): void
    {
        foreach (Input::PERMISSIONS as $permission) {
            $this->authorization->authorize($context, $permission);
        }
    }

    public function upload(TenantContext $context, string $contents, string $delimiter): PersonImport
    {
        $this->authorize($context);
        if (strlen($contents) > (int) config('person_imports.max_bytes', 1048576)) {
            throw ValidationException::withMessages(['file' => __('people_import.invalid_csv')]);
        }
        $this->limits->assertImport($context, strlen($contents), 0);
        $parsed = $this->parser->parse($contents, $delimiter, min((int) config('person_imports.max_rows', 1000), $this->limits->value($context, 'import_rows')));
        $this->limits->assertImport($context, strlen($contents), count($parsed['rows']));
        $fingerprint = hash_hmac('sha256', $context->organizationId.$delimiter.$contents, $this->crypt->getKey());
        $existing = $this->imports->byFingerprint($context->organizationId, $fingerprint);
        if ($existing !== null) {
            return $existing;
        }
        $artifact = $this->artifacts->store($context, 'people-import.csv.enc', $this->crypt->encryptString($contents));
        try {
            $run = $this->database->transaction(function () use ($context, $artifact, $fingerprint, $parsed, $delimiter): PersonImport {
                $this->guard->lock($context);
                $this->authorize($context);
                $existing = $this->imports->byFingerprint($context->organizationId, $fingerprint);
                if ($existing !== null) {
                    return $existing;
                }
                $run = $this->imports->create($context->organizationId, [
                    'actor_user_id' => $context->actorUserId, 'artifact_id' => $artifact->id, 'fingerprint' => $fingerprint,
                    'headers' => $parsed['headers'], 'row_count' => count($parsed['rows']), 'delimiter' => $delimiter,
                    'mapping' => [], 'defaults' => [], 'status' => 'uploaded', 'version' => (string) Str::uuid()]);
                $this->record($context, $run);

                return $run;
            });
        } catch (Throwable $exception) {
            $this->cleanup($context, $artifact->id);
            throw $exception;
        }
        if ($run->artifact_id !== $artifact->id) {
            $this->cleanup($context, $artifact->id);
        }

        return $run;
    }

    /** @param array<string,mixed> $payload */
    public function preview(TenantContext $context, string $id, array $payload): PersonImport
    {
        $this->authorize($context);
        $initial = $this->imports->find($context->organizationId, $id);
        $data = Input::mapping($this->validator, $payload, count($initial->headers));
        $contents = $this->crypt->decryptString($this->artifacts->read($context, $initial->artifact_id));
        $this->limits->assertImport($context, strlen($contents), $initial->row_count);
        $parsed = $this->parser->parse($contents, $initial->delimiter, (int) config('person_imports.max_rows', 1000));

        return $this->database->transaction(function () use ($context, $id, $data, $parsed): PersonImport {
            $this->guard->lock($context);
            $this->authorize($context);
            $run = $this->imports->find($context->organizationId, $id, true);
            if (! in_array($run->status, ['uploaded', 'previewed'], true)) {
                $this->conflict('locked');
            }
            if ($run->version !== $data['version']) {
                $this->conflict('stale');
            }
            $rows = [];
            $seen = [];
            $pseudonyms = [];
            foreach ($parsed['rows'] as $record) {
                $values = $data['defaults'];
                $errors = [];
                if (count($record['cells']) !== count($parsed['headers'])) {
                    $errors[] = 'COLUMN_COUNT';
                }
                foreach ($data['mapping'] as $field => $column) {
                    $value = trim($record['cells'][$column] ?? '');
                    $values[$field] = $value === '' && ! in_array($field, ['pseudonym', 'language', 'timezone', 'status'], true) ? null : $value;
                }
                $validation = $this->validator->make($values, PersonInput::rules(true), PersonInput::messages());
                if ($validation->fails()) {
                    foreach (array_keys($validation->errors()->messages()) as $field) {
                        $errors[] = 'INVALID_'.strtoupper($field);
                    }
                }
                $key = mb_strtolower(trim((string) ($values['pseudonym'] ?? '')));
                $status = $errors === [] ? 'ready' : 'invalid';
                if ($key !== '' && isset($seen[$key]) && $status === 'ready') {
                    $status = 'duplicate';
                    $errors[] = 'DUPLICATE_IN_FILE';
                }
                if ($status === 'ready') {
                    $seen[$key] = true;
                    if (is_string($values['pseudonym'])) {
                        $pseudonyms[] = $values['pseudonym'];
                    }
                }
                $rows[] = ['line' => $record['line'], 'values' => $values, 'errors' => $errors, 'status' => $status];
            }
            $existing = array_fill_keys($this->imports->existingPseudonyms($context->organizationId, $pseudonyms), true);
            foreach ($rows as &$row) {
                if ($row['status'] === 'ready' && isset($existing[mb_strtolower(trim((string) $row['values']['pseudonym']))])) {
                    $row['status'] = 'duplicate';
                    $row['errors'] = ['EXISTING_PERSON'];
                }
            }
            unset($row);
            $this->imports->replaceRows($run, $rows);
            $run = $this->imports->save($run, ['mapping' => $data['mapping'], 'defaults' => $data['defaults'],
                'status' => 'previewed', 'version' => (string) Str::uuid(), 'failure_code' => null]);
            $this->record($context, $run);

            return $run;
        });
    }

    /** @param array<string,mixed> $payload */
    public function confirm(TenantContext $context, string $id, array $payload): PersonImport
    {
        $this->authorize($context);
        if (array_diff(array_keys($payload), ['version', 'confirm', 'acknowledge_rejected']) !== []) {
            throw ValidationException::withMessages(['import' => __('people.validation.immutable')]);
        }
        $data = $this->validator->make($payload, ['version' => ['required', 'uuid'], 'confirm' => ['required', 'accepted'],
            'acknowledge_rejected' => ['sometimes', 'boolean']], PersonInput::messages())->validate();
        $run = $this->database->transaction(function () use ($context, $id, $data): PersonImport {
            $this->guard->lock($context);
            $this->authorize($context);
            $run = $this->imports->find($context->organizationId, $id, true);
            if ($run->version !== $data['version'] && $run->confirmed_version !== $data['version']) {
                $this->conflict('stale');
            }
            if ($run->status === 'completed') {
                return $run;
            }
            if (! in_array($run->status, ['previewed', 'queued', 'processing', 'failed'], true)) {
                $this->conflict('not_confirmed');
            }
            $counts = $this->imports->counts($context->organizationId, [$run->id])[$run->id] ?? [];
            if (($counts['ready'] ?? 0) === 0 && $run->status === 'previewed') {
                $this->conflict('empty');
            }
            if ((($counts['invalid'] ?? 0) + ($counts['duplicate'] ?? 0)) > 0 && ! ($data['acknowledge_rejected'] ?? false)) {
                $this->conflict('rejected');
            }
            $this->imports->save($run, ['status' => 'queued', 'execution_actor_id' => $context->actorUserId,
                'confirmed_version' => $run->confirmed_version ?? $run->version, 'version' => (string) Str::uuid(), 'failure_code' => null]);
            $this->record($context, $run);

            return $run;
        });
        if ($run->status !== 'completed') {
            // The durable queued state allows explicit resume if dispatch itself fails.
            $this->queue->dispatch($context->toJobContext(), $run->id);
        }

        return $this->imports->find($context->organizationId, $id);
    }

    /** @return LengthAwarePaginator<int,array<string,mixed>> */
    public function list(TenantContext $context, int $page): LengthAwarePaginator
    {
        $this->authorize($context);
        $this->validator->make(['page' => $page], ['page' => ['integer', 'between:1,10000']])->validate();
        $runs = $this->imports->paginate($context->organizationId, $page);
        $counts = $this->imports->counts($context->organizationId, array_map(fn (PersonImport $run) => $run->id, array_values($runs->items())));
        $runs->setCollection(collect(array_map(fn (PersonImport $run) => $this->project($run, $counts[$run->id] ?? []), $runs->items())));

        return $runs;
    }

    /** @return array<string,mixed> */
    public function show(TenantContext $context, string $id, int $page = 1): array
    {
        $this->authorize($context);
        $this->validator->make(['page' => $page], ['page' => ['integer', 'between:1,10000']])->validate();
        $run = $this->imports->find($context->organizationId, $id);
        $rows = $this->imports->rows($run, $page);

        return [...$this->project($run, $this->imports->counts($context->organizationId, [$id])[$id] ?? []),
            'rows' => array_map(fn (PersonImportRow $row) => ['id' => $row->id, 'line' => $row->line,
                'status' => $row->status, 'values' => $row->values, 'errors' => $row->errors, 'person_id' => $row->person_id], $rows->items()),
            'pagination' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]];
    }

    public function report(TenantContext $context, string $id): string
    {
        $this->authorize($context);
        $run = $this->imports->find($context->organizationId, $id);
        if (in_array($run->status, ['queued', 'processing'], true)) {
            $this->conflict('report_busy');
        }
        // Only closed codes and server-generated identifiers: no spreadsheet formulas or private cell values.
        $csv = "line,status,errors,person_id\r\n";
        foreach ($this->imports->reportRows($run) as $row) {
            $csv .= $row->line.','.$row->status.','.implode('|', $row->errors).','.($row->person_id ?? '')."\r\n";
        }

        return $csv;
    }

    /** @param array<string,int> $counts
     * @return array<string,mixed> */
    private function project(PersonImport $run, array $counts): array
    {
        return ['id' => $run->id, 'status' => $run->status, 'version' => $run->version, 'headers' => $run->headers,
            'mapping' => $run->mapping, 'defaults' => $run->defaults, 'row_count' => $run->row_count, 'counts' => $counts,
            'failure_code' => $run->failure_code];
    }

    private function record(TenantContext $context, PersonImport $run): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId,
            AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId, AuditReason::Domain,
            $context->requestId, null, [], ['person_import_id' => $run->id, 'person_import_status' => $run->status, 'version' => $run->version]));
    }

    private function cleanup(TenantContext $context, string $artifactId): void
    {
        try {
            $this->artifacts->delete($context, $artifactId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function conflict(string $reason): never
    {
        throw new DomainConflictException(__('people_import.'.$reason), 'PERSON_IMPORT_'.strtoupper($reason));
    }
}
