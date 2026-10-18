<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Validation\ExternalCompetitionInput as Input;
use App\Modules\Competition\Domain\Contracts\ExternalCompetitionRepository;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Competition\Domain\Models\ExternalCompetitionRevision;
use App\Modules\Teams\Application\Contracts\ExternalCompetitionGames;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ExternalCompetitionService
{
    public function __construct(private ExternalCompetitionRepository $records, private ExternalCompetitionGames $games, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    public function authorize(TenantContext $context, bool $write = false): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'sports.competitions.read');
        if ($write) {
            $this->authorization->authorize($context, 'sports.competitions.write');
        }
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,ExternalCompetition> */
    public function paginate(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->authorize($context);
        $values = Input::validate($this->validator, $input, 'index');
        if (isset($values['game_id'])) {
            $values['game_id'] = strtolower($values['game_id']);
        }

        return $this->records->paginate($context->organizationId, $values);
    }

    public function find(TenantContext $context, string $id): ExternalCompetition
    {
        $this->authorize($context);

        return $this->records->find($context->organizationId, $id);
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function games(TenantContext $context, array $input): LengthAwarePaginator
    {
        $this->authorize($context, true);
        $v = Input::validate($this->validator, $input, 'games');

        return $this->games->choices($context, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,ExternalCompetitionRevision> */
    public function history(TenantContext $context, string $id, array $input): LengthAwarePaginator
    {
        $this->find($context, $id);
        $v = Input::validate($this->validator, $input, 'history');

        return $this->records->history($context->organizationId, $id, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id, ?string $ip): ExternalCompetition
    {
        $this->authorize($context, true);
        $values = Input::validate($this->validator, $input, $id === null ? 'store' : 'update');
        if (mb_strlen(trim($values['reason'])) < 10) {
            throw ValidationException::withMessages(['reason' => __('external_competitions.short')]);
        }
        try {
            return $this->database->transaction(function () use ($context, $values, $id, $ip): ExternalCompetition {
                $this->guard->lock($context);
                $this->authorize($context, true);
                $record = $id === null ? null : $this->records->find($context->organizationId, $id, true);
                if ($record !== null && $record->version !== strtolower($values['version'])) {
                    $this->conflict('stale', 'EXTERNAL_COMPETITION_VERSION_STALE');
                }
                if ($record?->status === 'archived') {
                    $this->conflict('archived', 'EXTERNAL_COMPETITION_ARCHIVED');
                }
                if ($record === null && $this->records->count($context->organizationId) >= Input::MAX_COMPETITIONS) {
                    $this->conflict('limit', 'EXTERNAL_COMPETITION_LIMIT');
                }
                $latest = $record === null ? null : $this->records->latestRevision($context->organizationId, $record->id);
                if (($latest->number ?? 0) >= Input::MAX_REVISIONS) {
                    $this->conflict('limit', 'EXTERNAL_COMPETITION_REVISION_LIMIT');
                }
                $attributes = $values;
                unset($attributes['version'],$attributes['reason'],$attributes['archive']);
                if (isset($values['archive'])) {
                    if ($record === null || $attributes !== []) {
                        throw ValidationException::withMessages(['archive' => __('external_competitions.archive_only')]);
                    }
                    $attributes = ['status' => 'archived'];
                } else {
                    $game = $this->games->available($context, $record->game_id ?? strtolower($values['game_id']));
                    if ($record === null) {
                        $attributes = [...$attributes, 'organization_id' => $context->organizationId, 'game_id' => $game['id'], 'game_key' => $game['key'], 'game_name' => $game['name'], 'contacts' => $values['contacts'] ?? [], 'website_url' => $values['website_url'] ?? null, 'status' => 'active', 'created_by' => $context->actorUserId];
                    }
                }
                foreach (['name', 'organizer', 'region', 'season', 'website_url'] as $field) {
                    if (isset($attributes[$field])) {
                        $attributes[$field] = trim($attributes[$field]);
                    }
                }
                if (isset($attributes['contacts'])) {
                    $attributes['contacts'] = array_values(array_map(fn (array $contact): array => ['name' => trim($contact['name']), 'role' => $contact['role'] === null ? null : trim($contact['role']), 'email' => $contact['email'] === null ? null : trim($contact['email']), 'phone' => $contact['phone'] === null ? null : trim($contact['phone'])], $attributes['contacts']));
                }
                $before = $record === null ? [] : $this->state($record);
                $changed = array_keys(array_filter($attributes, fn ($value, string $field): bool => ! array_key_exists($field, $before) || $before[$field] !== $value, ARRAY_FILTER_USE_BOTH));
                if ($record !== null && $changed === []) {
                    throw ValidationException::withMessages(['record' => __('external_competitions.empty')]);
                }
                $base = $record?->version;
                $version = (string) Str::uuid();
                if ($record === null) {
                    $record = $this->records->save(null, [...$attributes, 'version' => $version]);
                }
                $snapshot = [...$this->state($record), ...$attributes, 'version' => $version];
                unset($snapshot['organization_id'],$snapshot['created_by']);
                // Append the immutable authorization before the SQL-protected parent update.
                $this->records->revision(['id' => (string) Str::uuid(), 'organization_id' => $context->organizationId, 'competition_id' => $record->id, 'number' => ($latest->number ?? 0) + 1,
                    'base_version' => $base, 'version' => $version, 'snapshot' => $snapshot, 'changed_fields' => $changed, 'reason' => trim($values['reason']), 'actor_user_id' => $context->actorUserId]);
                if ($base !== null) {
                    $record = $this->records->save($record, [...$attributes, 'version' => $version]);
                }
                $this->journal($context, $record->id, null, isset($values['archive']) ? 'archived' : ($base === null ? 'created' : 'updated'), $version, $ip);

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            $this->conflict('duplicate', 'EXTERNAL_COMPETITION_REFERENCE_EXISTS');
        }
    }

    /**
     * @return array<string,mixed> */
    public function state(ExternalCompetition $record): array
    {
        return ['id' => $record->id, 'reference' => $record->reference, 'game_id' => $record->game_id, 'game_key' => $record->game_key, 'game_name' => $record->game_name, 'name' => $record->name,
            'organizer' => $record->organizer, 'region' => $record->region, 'season' => $record->season, 'website_url' => $record->website_url, 'contacts' => $record->contacts, 'status' => $record->status, 'version' => $record->version];
    }

    public function journal(TenantContext $context, string $competition, ?string $document, string $action, string $version, ?string $ip): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [],
            ['external_competition_id' => $competition, 'external_competition_action' => $action, 'version' => $version, ...($document === null ? [] : ['external_competition_document_id' => $document])]));
    }

    private function conflict(string $key, string $code): never
    {
        throw new DomainConflictException(__('external_competitions.'.$key), $code);
    }
}
