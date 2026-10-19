<?php

namespace App\Modules\Competition\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Validation\CompetitionRegistrationInput as Input;
use App\Modules\Competition\Domain\Contracts\CompetitionRegistrationRepository;
use App\Modules\Competition\Domain\Contracts\ExternalCompetitionRepository;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationReminder;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationRevision;
use App\Modules\Competition\Domain\RegistrationDeadline;
use App\Modules\People\Application\Contracts\CompetitionRegistrationPeople;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CompetitionRegistrationService
{
    public const PERMISSIONS = ['sports.read', 'sports.competitions.read', 'sports.registrations.read'];

    public function __construct(private CompetitionRegistrationRepository $records, private ExternalCompetitionRepository $competitions, private ExternalCompetitionService $audit, private CompetitionRegistrationPeople $people, private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private ConnectionInterface $database, private Factory $validator) {}

    public function authorize(TenantContext $context, bool $write = false): void
    {
        foreach ([...self::PERMISSIONS, ...($write ? ['sports.registrations.write'] : [])] as $permission) {
            $this->authorization->authorize($context, $permission);
        }
    }

    public function read(TenantContext $context, string $competition): ?CompetitionRegistrationRevision
    {
        $this->authorize($context);
        $this->competitions->find($context->organizationId, $competition);

        return $this->records->latest($context->organizationId, $competition);
    }

    /**
     * @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,CompetitionRegistrationRevision> */
    public function history(TenantContext $context, string $competition, array $input): LengthAwarePaginator
    {
        $this->read($context, $competition);
        $v = Input::validate($this->validator, $input, 'history');

        return $this->records->history($context->organizationId, $competition, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 10));
    }

    /**
     * @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function people(TenantContext $context, string $competition, array $input): LengthAwarePaginator
    {
        $this->authorize($context, true);
        $this->competitions->find($context->organizationId, $competition);
        $v = Input::validate($this->validator, $input, 'people');

        return $this->people->choices($context, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 10));
    }

    /**
     * @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,CompetitionRegistrationReminder> */
    public function reminders(TenantContext $context, string $competition, array $input): LengthAwarePaginator
    {
        $this->read($context, $competition);
        $v = Input::validate($this->validator, $input, 'reminders');

        return $this->records->reminders($context->organizationId, $competition, $context->actorUserId, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 10));
    }

    /**
     * @param array<string,mixed> $input */
    public function save(TenantContext $context, string $competition, array $input, ?string $ip): CompetitionRegistrationRevision
    {
        $this->authorize($context, true);
        $v = Input::validate($this->validator, $input, 'save');
        if (mb_strlen(trim($v['reason'])) < 10) {
            throw ValidationException::withMessages(['reason' => __('competition_registrations.reason')]);
        }

        return $this->database->transaction(function () use ($context, $competition, $v, $ip): CompetitionRegistrationRevision {
            $this->guard->lock($context);
            $this->authorize($context, true);
            $parent = $this->competitions->find($context->organizationId, $competition, true);
            if ($parent->status === 'archived') {
                $this->conflict('archived', 'COMPETITION_REGISTRATION_ARCHIVED');
            }
            $old = $this->records->latest($context->organizationId, $parent->id);
            if ($parent->version !== strtolower($v['competition_version']) || ($old?->id) !== ($v['expected_version'] === null ? null : strtolower($v['expected_version']))) {
                $this->conflict('stale', 'COMPETITION_REGISTRATION_STALE');
            }
            if (($old->number ?? 0) >= Input::MAX_REVISIONS) {
                $this->conflict('limit', 'COMPETITION_REGISTRATION_LIMIT');
            }
            $payload = $this->normalize($context, $parent->id, $v, $old->payload ?? []);
            if ($old !== null && $payload === $old->payload) {
                throw ValidationException::withMessages(['registration' => __('competition_registrations.empty')]);
            }
            $dates = array_values(array_filter(array_map(fn (array $i): ?string => $i['status'] === 'pending' ? $i['due_at'] : null, $payload['items'])));
            sort($dates);
            $row = $this->records->append(['id' => (string) Str::uuid(), 'organization_id' => $context->organizationId, 'competition_id' => $parent->id, 'number' => ($old->number ?? 0) + 1, 'payload' => $payload, 'reason' => trim($v['reason']), 'actor_user_id' => $context->actorUserId, 'next_due_at' => $dates[0] ?? null]);
            $this->audit->journal($context, $parent->id, null, 'registration_updated', $row->id, $ip);

            return $row;
        });
    }

    /**
     * @param array<string,mixed> $v
     * @param array<string,mixed> $old
     * @return array<string,mixed> */
    private function normalize(TenantContext $context, string $competition, array $v, array $old): array
    {
        $roster = array_map(fn (array $p): array => ['person_id' => strtolower($p['person_id']), 'role' => $p['role']], $v['roster']);
        if ($roster === [] && in_array($v['roster_status'], ['submitted', 'approved'], true)) {
            throw ValidationException::withMessages(['roster' => __('competition_registrations.submitted')]);
        }
        $oldRoster = array_map(fn (array $p): array => ['person_id' => $p['person_id'], 'role' => $p['role']], $old['roster'] ?? []);
        if (($old['roster_status'] ?? null) === 'approved' && $oldRoster !== $roster && $v['roster_status'] === 'approved') {
            throw ValidationException::withMessages(['roster_status' => __('competition_registrations.roster_changed')]);
        }
        $known = [];
        foreach ($old['roster'] ?? [] as $p) {
            $known[$p['person_id']] = $p['label'];
        }
        foreach ($old['items'] ?? [] as $i) {
            if ($i['responsible_person_id'] !== null) {
                $known[$i['responsible_person_id']] = $i['responsible_label'];
            }
        }
        $previousRoster = array_column($old['roster'] ?? [], 'role', 'person_id');
        $previousItems = array_column($old['items'] ?? [], null, 'id');
        $ids = [];
        foreach ($roster as $entry) {
            if (($previousRoster[$entry['person_id']] ?? null) !== $entry['role']) {
                $ids[] = $entry['person_id'];
            }
        }
        foreach ($v['items'] as $entry) {
            $person = $entry['responsible_person_id'] === null ? null : strtolower($entry['responsible_person_id']);
            if ($person !== null && ($previousItems[strtolower($entry['id'])]['responsible_person_id'] ?? null) !== $person) {
                $ids[] = $person;
            }
        }
        $newIds = array_values(array_unique($ids));
        $labels = $newIds === [] ? [] : $this->people->snapshots($context, $newIds);
        foreach ($labels as $id => $data) {
            $known[$id] = $data['label'];
        }
        $roster = array_map(fn (array $p): array => [...$p, 'label' => $known[$p['person_id']]], $roster);
        $versions = array_values(array_unique(array_map('strtolower', array_filter(array_column($v['items'], 'document_version_id')))));
        if (count($this->records->documentVersions($context->organizationId, $competition, $versions)) !== count($versions)) {
            throw (new ModelNotFoundException)->setModel(CompetitionRegistrationRevision::class);
        }
        $items = [];
        foreach ($v['items'] as $i) {
            $person = $i['responsible_person_id'] === null ? null : strtolower($i['responsible_person_id']);
            if ($i['document_version_id'] !== null && $i['kind'] !== 'document') {
                throw ValidationException::withMessages(['items' => __('competition_registrations.document')]);
            }
            $items[] = ['id' => strtolower($i['id']), 'kind' => $i['kind'], 'title' => trim($i['title']), 'status' => $i['status'], 'due_at' => $i['due_at'] === null ? null : CarbonImmutable::parse($i['due_at'])->utc()->format('Y-m-d\TH:i:sP'),
                'responsible_person_id' => $person, 'responsible_label' => $person === null ? null : $known[$person], 'note' => $i['note'] === null ? null : trim($i['note']), 'document_version_id' => $i['document_version_id'] === null ? null : strtolower($i['document_version_id'])];
        }

        return ['registration_status' => $v['registration_status'], 'payment_status' => $v['payment_status'], 'roster_status' => $v['roster_status'], 'checkin_status' => $v['checkin_status'],
            'external_reference' => $v['external_reference'] === null ? null : trim($v['external_reference']), 'source_url' => $v['source_url'] ?? null, 'notes' => $v['notes'] === null ? null : trim($v['notes']),
            'requirements' => array_combine(['age', 'residence', 'rank'], array_map(fn (string $key): ?string => $v['requirements'][$key] === null ? null : trim($v['requirements'][$key]), ['age', 'residence', 'rank'])), 'roster' => $roster, 'items' => $items];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,int> */
    public static function summary(array $payload): array
    {
        $counts = ['pending' => 0, 'due_soon' => 0, 'overdue' => 0];
        foreach ($payload['items'] as $item) {
            if ($item['status'] === 'pending') {
                $counts['pending']++;
                $stage = RegistrationDeadline::stage($item, CarbonImmutable::now());
                if ($stage === 'overdue') {
                    $counts['overdue']++;
                } elseif ($stage !== null) {
                    $counts['due_soon']++;
                }
            }
        }

        return $counts;
    }

    private function conflict(string $key, string $code): never
    {
        throw new DomainConflictException(__('competition_registrations.'.$key), $code);
    }
}
