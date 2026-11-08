<?php

namespace App\Modules\Media\Application\Services;

use App\Modules\Media\Application\Contracts\PartnershipMediaDirectory;
use App\Modules\Media\Domain\Contracts\MediaRepository;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

final readonly class PartnershipMediaService implements PartnershipMediaDirectory
{
    public function __construct(private MediaRepository $records, private MediaContexts $contexts, private TenantAuthorization $authorization) {}

    public function visibleIds(TenantContext $context): ?Builder
    {
        return $this->authorization->allows($context, 'partnerships.read') && $this->authorization->allows($context, 'media.read')
            ? $this->records->partnershipIds($context->organizationId, $this->contexts->constraints($context)) : null;
    }

    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'partnerships.read');
        $this->authorization->authorize($context, 'media.read');
        $visible = $this->visibleIds($context) ?? throw new ModelNotFoundException;

        return $this->records->partnershipChoices($context->organizationId, $visible, $page, $perPage);
    }

    public function metadata(TenantContext $context, array $ids): array
    {
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Exposure references require a bounded page.');
        }
        $visible = $this->visibleIds($context);

        return $visible === null || $ids === [] ? [] : $this->records->partnershipMetadata($context->organizationId, $ids, $visible);
    }
}
