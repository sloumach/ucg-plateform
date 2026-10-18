<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Validation\MatchNotebookInput as Input;
use App\Modules\Competition\Domain\Contracts\MatchNotebookRepository;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Models\MatchNotebookRevision;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MatchNotebookService
{
    public function __construct(private MatchRepository $matches, private MatchNotebookRepository $records,
        private MatchReferenceDirectory $references, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string,mixed> $input
     * @return array{entries:LengthAwarePaginator<int,MatchNotebookRevision>,writable_audiences:list<string>} */
    public function index(TenantContext $context, string $match, array $input): array
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = Input::validate($this->validator, $input, 'index');
        $record = $this->parent($context, $match, (bool) ($values['management'] ?? false));
        $audiences = $this->audiences($context);
        if (isset($values['audience'])) {
            $this->authorizeAudience($context, $values['audience'], false);
        }

        $canWrite = $this->authorization->allows($context, 'sports.write');

        return ['entries' => $this->records->paginate($context->organizationId, $record->id, $audiences, $values),
            'writable_audiences' => array_values(array_filter($audiences, fn (string $a): bool => $canWrite && $this->writable($context, $a)))];
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,MatchNotebookRevision> */
    public function history(TenantContext $context, string $match, string $entry, array $input): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = Input::validate($this->validator, $input, 'history');
        $record = $this->parent($context, $match, (bool) ($values['management'] ?? false));
        $current = $this->records->latest($context->organizationId, $record->id, strtolower($entry), $this->audiences($context));

        return $this->records->history($context->organizationId, $record->id, $current->entry_id, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input */
    public function change(TenantContext $context, string $match, ?string $entry, array $input, ?string $ip): MatchNotebookRevision
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'sports.write');
        $values = Input::validate($this->validator, $input, $entry === null ? 'store' : 'update');

        return $this->database->transaction(function () use ($context, $match, $entry, $values, $ip): MatchNotebookRevision {
            $this->guard->lock($context);
            $this->authorization->authorize($context, 'sports.read');
            $this->authorization->authorize($context, 'sports.write');
            $record = $this->parent($context, $match, true, true);
            $previous = $entry === null ? null : $this->records->latest($context->organizationId, $record->id, strtolower($entry), $this->audiences($context));
            $audience = $previous->audience ?? $values['audience'];
            $this->authorizeAudience($context, $audience, true);
            if ($previous !== null && $previous->id !== strtolower($values['version'])) {
                throw new DomainConflictException(__('match_notebook.stale'), 'MATCH_NOTEBOOK_VERSION_STALE');
            }
            if (($previous->number ?? 0) >= Input::MAX_REVISIONS || ($previous === null && $this->records->count($context->organizationId, $record->id) >= Input::MAX_ENTRIES)) {
                throw new DomainConflictException(__('match_notebook.limit'), 'MATCH_NOTEBOOK_LIMIT');
            }
            if ($audience === 'shared' && ! ($values['confirm_sharing'] ?? false)) {
                throw ValidationException::withMessages(['confirm_sharing' => __('match_notebook.sharing')]);
            }
            $kind = $previous->kind ?? $values['kind'];
            $payload = $previous === null ? ['vod_url' => null, 'timestamp_seconds' => null, 'state' => $kind === 'action' ? 'open' : null, 'due_at' => null] :
                ['title' => $previous->title, 'body' => $previous->body, 'vod_url' => $previous->vod_url, 'timestamp_seconds' => $previous->timestamp_seconds, 'state' => $previous->state, 'due_at' => $previous->due_at?->format('Y-m-d\TH:i:sP')];
            $payload = array_replace($payload, array_intersect_key($values, array_flip(['title', 'body', 'vod_url', 'timestamp_seconds', 'state', 'due_at'])));
            $this->validateContent($kind, $payload, $values);
            foreach (['title', 'body'] as $field) {
                $payload[$field] = trim($payload[$field]);
            }
            $id = (string) Str::uuid();
            // Notebook revisions have their own version: a debrief must not rewrite a sealed sporting result.
            $revision = $this->records->append([...$payload, 'id' => $id, 'entry_id' => $previous->entry_id ?? $id, 'organization_id' => $context->organizationId,
                'match_id' => $record->id, 'number' => ($previous->number ?? 0) + 1, 'kind' => $kind, 'audience' => $audience, 'actor_user_id' => $context->actorUserId]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
                $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['match_id' => $record->id, 'match_action' => 'notebook', 'match_notebook_entry_id' => $revision->entry_id, 'version' => $id]));

            return $revision;
        });
    }

    private function parent(TenantContext $context, string $match, bool $management, bool $lock = false): MatchRecord
    {
        if ($management) {
            $this->authorization->authorize($context, 'sports.write');
        }

        return $this->matches->find($context->organizationId, strtolower($match), $context->actorUserId, $this->references->viewer($context), $management, $lock);
    }

    /** @return list<string> */
    private function audiences(TenantContext $context): array
    {
        $audiences = ['shared'];
        foreach (['internal' => 'sports.notes.read', 'strategy' => 'sports.strategies.read'] as $audience => $permission) {
            if ($this->authorization->allows($context, $permission)) {
                $audiences[] = $audience;
            }
        }

        return $audiences;
    }

    private function writable(TenantContext $context, string $audience): bool
    {
        return $this->authorization->allows($context, match ($audience) {
            'internal' => 'sports.notes.write','strategy' => 'sports.strategies.write',default => 'sports.publish'
        });
    }

    private function authorizeAudience(TenantContext $context, string $audience, bool $write): void
    {
        $this->authorization->authorize($context, 'sports.read');
        if ($audience !== 'shared') {
            $this->authorization->authorize($context, $audience === 'internal' ? 'sports.notes.read' : 'sports.strategies.read');
        }
        if ($write) {
            $this->authorization->authorize($context, 'sports.write');
            $this->authorization->authorize($context, match ($audience) {
                'internal' => 'sports.notes.write','strategy' => 'sports.strategies.write',default => 'sports.publish'
            });
        }
    }

    /** @param array<string,mixed> $payload
     * @param array<string,mixed> $values */
    private function validateContent(string $kind, array $payload, array $values): void
    {
        if ($kind === 'vod') {
            $url = $payload['vod_url'];
            if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                throw ValidationException::withMessages(['vod_url' => __('match_notebook.url')]);
            }
        } elseif (array_intersect(['vod_url', 'timestamp_seconds'], array_keys($values)) !== []) {
            throw ValidationException::withMessages(['vod_url' => __('match_notebook.vod_only')]);
        }
        if ($kind !== 'action' && array_intersect(['state', 'due_at'], array_keys($values)) !== []) {
            throw ValidationException::withMessages(['state' => __('match_notebook.action_only')]);
        }
    }
}
