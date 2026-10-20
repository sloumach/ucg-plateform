<?php

namespace App\Modules\Competition\Application\Services;

use App\Modules\Competition\Application\Contracts\CompetitionSearch;
use App\Modules\Competition\Domain\Contracts\ExternalCompetitionRepository;
use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Teams\Application\Contracts\MatchReferenceDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\SearchPage;
use InvalidArgumentException;

final readonly class CompetitionSearchService implements CompetitionSearch
{
    public function __construct(private MatchRepository $matches, private ExternalCompetitionRepository $competitions, private MatchReferenceDirectory $references, private TenantAuthorization $authorization) {}

    public function search(TenantContext $context, string $type, string $term, ?string $after, int $size): SearchPage
    {
        $this->authorization->authorize($context, 'sports.read');
        if ($type === 'matches') {
            return $this->matches->search($context->organizationId, $context->actorUserId, $this->references->viewer($context), $term, $after, $size);
        }
        $this->authorization->authorize($context, 'sports.competitions.read');
        if (! in_array($type, ['competitions', 'competition_documents'], true)) {
            throw new InvalidArgumentException('Unknown search source.');
        }

        return $this->competitions->search($context->organizationId, $type === 'competition_documents', $term, $after, $size);
    }
}
