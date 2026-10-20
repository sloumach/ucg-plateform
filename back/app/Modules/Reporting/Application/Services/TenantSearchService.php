<?php

namespace App\Modules\Reporting\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Competition\Application\Contracts\CompetitionSearch;
use App\Modules\Contracts\Application\Contracts\ContractDocumentSearch;
use App\Modules\People\Application\Contracts\PeopleSearch;
use App\Modules\Reporting\Application\Validation\TenantSearchInput;
use App\Modules\Teams\Application\Contracts\TeamSearch;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\SearchPage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Psr\Log\LoggerInterface;

final readonly class TenantSearchService
{
    public const PERMISSIONS = ['people' => ['sports.read'], 'teams' => ['sports.read'], 'matches' => ['sports.read'],
        'competitions' => ['sports.read', 'sports.competitions.read'], 'competition_documents' => ['sports.read', 'sports.competitions.read'],
        'contract_documents' => ['administrative.read']];

    public function __construct(private PeopleSearch $people, private TeamSearch $teams, private CompetitionSearch $competition,
        private ContractDocumentSearch $documents, private TenantAuthorization $authorization, private Encrypter $crypt, private Factory $validator, private LoggerInterface $logger) {}

    /** @return list<string> */
    public function types(TenantContext $context): array
    {
        $types = [];
        foreach (self::PERMISSIONS as $type => $permissions) {
            $allowed = true;
            foreach ($permissions as $permission) {
                if (! $this->authorization->allows($context, $permission)) {
                    $allowed = false;
                    break;
                }
            }
            if ($allowed) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function search(TenantContext $context, array $input): array
    {
        $unknown = array_diff(array_keys($input), array_keys(TenantSearchInput::rules()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('search.closed')));
        }
        $v = $this->validator->make($input, TenantSearchInput::rules())->validate();
        $type = $v['type'];
        if (! in_array($type, $this->types($context), true)) {
            throw new AuthorizationException;
        }
        $term = trim($v['q']);
        if (mb_strlen($term) < 2) {
            throw ValidationException::withMessages(['q' => __('search.term')]);
        }
        $size = min((int) ($v['per_page'] ?? 10), ($v['mode'] ?? 'search') === 'suggest' ? 5 : 20);
        $binding = [$context->organizationId, $context->actorUserId, $type, mb_strtolower($term), $size];
        $after = null;
        if (isset($v['cursor'])) {
            try {
                $cursor = json_decode($this->crypt->decryptString($v['cursor']), true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($cursor) || ($cursor['binding'] ?? null) !== $binding || ! is_int($cursor['expires'] ?? null) || $cursor['expires'] <= now()->timestamp
                    || ! is_string($cursor['after'] ?? null) || ! Str::isUuid($cursor['after'])) {
                    throw ValidationException::withMessages(['cursor' => __('search.cursor')]);
                }
                $after = $cursor['after'];
            } catch (DecryptException|JsonException) {
                throw ValidationException::withMessages(['cursor' => __('search.cursor')]);
            }
        }
        try {
            $page = match ($type) {
                'people' => $this->people->search($context, $term, $after, $size), 'teams' => $this->teams->search($context, $term, $after, $size),
                'contract_documents' => $this->documents->search($context, $term, $after, $size),
                default => $this->competition->search($context, $type, $term, $after, $size),
            };
        } catch (QueryException|DecryptException $exception) {
            $this->logger->warning('reporting.search_unavailable', ['tenant_id' => $context->organizationId, 'request_id' => $context->requestId, 'source' => $type, 'exception_type' => $exception::class]);
            throw new DomainConflictException(__('search.unavailable'), 'SEARCH_SOURCE_UNAVAILABLE');
        }

        return ['type' => $type, 'items' => $page->items, 'meta' => ['per_page' => $size, 'examined' => $page->examined, 'scan_limit' => SearchPage::SCAN_LIMIT,
            'has_more' => $page->after !== null, 'next_cursor' => $page->after === null ? null : $this->crypt->encryptString(json_encode(['binding' => $binding, 'after' => $page->after, 'expires' => now()->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR))]];
    }
}
