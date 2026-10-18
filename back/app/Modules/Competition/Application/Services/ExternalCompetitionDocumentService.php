<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Validation\ExternalCompetitionInput as Input;
use App\Modules\Competition\Domain\Contracts\ExternalCompetitionRepository;
use App\Modules\Competition\Domain\Models\ExternalCompetitionDocument;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ExternalCompetitionDocumentService
{
    public function __construct(private ExternalCompetitionService $competitions, private ExternalCompetitionRepository $records, private TenantBusinessGuard $guard, private ConnectionInterface $database, private Factory $validator) {}

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,ExternalCompetitionDocument> */
    public function paginate(TenantContext $context, string $competition, ?string $document, array $input): LengthAwarePaginator
    {
        $record = $this->competitions->find($context, $competition);
        $v = Input::validate($this->validator, $input, $document === null ? 'documents' : 'documentHistory');
        if ($document !== null) {
            $this->records->document($context->organizationId, $record->id, $document);

            return $this->records->documentHistory($context->organizationId, $record->id, $document, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 10));
        }

        return $this->records->documents($context->organizationId, $record->id, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input */
    public function save(TenantContext $context, string $competition, ?string $document, array $input, ?string $ip): ExternalCompetitionDocument
    {
        $this->competitions->authorize($context, true);
        $values = Input::validate($this->validator, $input, $document === null ? 'documentStore' : 'documentUpdate');
        if (mb_strlen(trim($values['reason'])) < 10) {
            throw ValidationException::withMessages(['reason' => __('external_competitions.short')]);
        }
        try {
            return $this->database->transaction(function () use ($context, $competition, $document, $values, $ip): ExternalCompetitionDocument {
                $this->guard->lock($context);
                $this->competitions->authorize($context, true);
                $record = $this->records->find($context->organizationId, $competition, true);
                if ($record->status === 'archived') {
                    throw new DomainConflictException(__('external_competitions.archived'), 'EXTERNAL_COMPETITION_ARCHIVED');
                }
                if ($record->version !== strtolower($values['competition_version'])) {
                    throw new DomainConflictException(__('external_competitions.stale'), 'EXTERNAL_COMPETITION_VERSION_STALE');
                }
                $latest = $document === null ? null : $this->records->document($context->organizationId, $record->id, $document);
                if ($latest !== null && $latest->id !== strtolower($values['version'])) {
                    throw new DomainConflictException(__('external_competitions.stale'), 'EXTERNAL_COMPETITION_DOCUMENT_STALE');
                }
                if (($latest->number ?? 0) >= Input::MAX_DOCUMENT_REVISIONS || ($latest === null && $this->records->documentCount($context->organizationId, $record->id) >= Input::MAX_DOCUMENTS)) {
                    throw new DomainConflictException(__('external_competitions.limit'), 'EXTERNAL_COMPETITION_DOCUMENT_LIMIT');
                }
                $attributes = ['kind' => $latest->kind ?? $values['kind'], 'title' => trim($values['title'] ?? $latest->title ?? ''), 'body' => trim($values['body'] ?? $latest->body ?? ''),
                    'source_url' => array_key_exists('source_url', $values) ? $values['source_url'] : $latest?->source_url];
                if ($latest !== null && $attributes === ['kind' => $latest->kind, 'title' => $latest->title, 'body' => $latest->body, 'source_url' => $latest->source_url]) {
                    throw ValidationException::withMessages(['document' => __('external_competitions.empty')]);
                }
                $id = (string) Str::uuid();
                $revision = $this->records->appendDocument([...$attributes, 'id' => $id, 'document_id' => $latest->document_id ?? $id, 'organization_id' => $context->organizationId, 'competition_id' => $record->id,
                    'number' => ($latest->number ?? 0) + 1, 'reason' => trim($values['reason']), 'actor_user_id' => $context->actorUserId]);
                $this->competitions->journal($context, $record->id, $revision->document_id, $latest === null ? 'document_created' : 'document_updated', $id, $ip);

                return $revision;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('external_competitions.duplicate'), 'EXTERNAL_COMPETITION_RULES_EXISTS');
        }
    }

    public function download(TenantContext $context, string $competition, string $document, string $version): ExternalCompetitionDocument
    {
        return $this->database->transaction(function () use ($context, $competition, $document, $version): ExternalCompetitionDocument {
            $this->guard->lock($context);
            $this->competitions->find($context, $competition);

            return $this->records->documentVersion($context->organizationId, $competition, $document, $version);
        });
    }
}
