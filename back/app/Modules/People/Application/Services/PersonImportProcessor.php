<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Data\PersonData;
use App\Modules\People\Application\Validation\PersonImportInput;
use App\Modules\People\Domain\Contracts\PersonImportRepository;
use App\Modules\People\Domain\Models\PersonImportRow;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final readonly class PersonImportProcessor
{
    public function __construct(private PersonImportRepository $imports, private PersonImportService $service,
        private PersonService $people, private TenantBusinessGuard $guard, private ConnectionInterface $database) {}

    /** Returns true only when another bounded chunk remains. */
    public function process(TenantContext $context, string $id): bool
    {
        $this->service->authorize($context);

        return $this->database->transaction(function () use ($context, $id): bool {
            $this->guard->lock($context);
            $this->service->authorize($context);
            $run = $this->imports->find($context->organizationId, $id, true);
            if ($run->execution_actor_id !== $context->actorUserId || ! in_array($run->status, ['queued', 'processing'], true)) {
                return false;
            }
            $rows = $this->imports->ready($run, (int) config('person_imports.chunk_size', 25));
            $existing = array_fill_keys($this->imports->existingPseudonyms($context->organizationId,
                array_map(fn (PersonImportRow $row) => (string) $row->values['pseudonym'], $rows)), true);
            $this->imports->save($run, ['status' => 'processing']);
            foreach ($rows as $row) {
                $values = $row->values;
                if (array_diff(array_keys($values), PersonImportInput::FIELDS) !== []) {
                    throw ValidationException::withMessages(['import' => __('people.validation.immutable')]);
                }
                $key = mb_strtolower(trim((string) $values['pseudonym']));
                if (isset($existing[$key])) {
                    $this->imports->saveRow($row, ['status' => 'skipped', 'errors' => ['EXISTING_PERSON_AT_EXECUTION']]);

                    continue;
                }
                $person = $this->people->create($context, new PersonData($values));
                $this->imports->saveRow($row, ['status' => 'created', 'person_id' => $person->fields['id']]);
                $existing[$key] = true;
            }
            $counts = $this->imports->counts($context->organizationId, [$id])[$id] ?? [];
            $more = ($counts['ready'] ?? 0) > 0;
            $this->imports->save($run, ['status' => $more ? 'queued' : 'completed', 'failure_code' => null]);

            return $more;
        }, 3);
    }

    /** Operational failure metadata only; never creates a person or copies exception data. */
    public function failed(string $organizationId, string $id, int $actorUserId): void
    {
        try {
            $this->database->transaction(function () use ($organizationId, $id, $actorUserId): void {
                $run = $this->imports->find($organizationId, $id, true);
                if ($run->execution_actor_id === $actorUserId && in_array($run->status, ['queued', 'processing'], true)) {
                    $this->imports->save($run, ['status' => 'failed', 'failure_code' => 'PROCESSING_INTERRUPTED']);
                }
            });
        } catch (ModelNotFoundException) {
            // A removed maintenance target does not need an operational status update.
        }
    }
}
