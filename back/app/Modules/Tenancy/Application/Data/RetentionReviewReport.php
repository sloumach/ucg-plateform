<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Modules\Tenancy\Domain\RetentionCatalog;
use App\Support\Data\DataTransferObject;
use JsonSerializable;

final readonly class RetentionReviewReport extends DataTransferObject implements JsonSerializable
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public string $organizationId, public RetentionReviewQuery $query,
        public string $policyStatus, public ?string $policyVersion, public array $items, public int $total,
        public string $requestId, public ?string $policyFingerprint) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['organization_id' => $this->organizationId, 'mode' => 'review_only', 'section' => $this->query->section,
            'as_of' => $this->query->asOf->toDateString(), 'policy_status' => $this->policyStatus, 'policy_version' => $this->policyVersion,
            'request_id' => $this->requestId, 'policy_fingerprint' => $this->policyFingerprint,
            'consistency' => 'bounded_review_not_execution_snapshot',
            'deletion_supported' => false, 'data' => $this->items,
            'pagination' => ['page' => $this->query->page, 'per_page' => $this->query->perPage, 'total' => $this->total,
                'last_page' => max(1, (int) ceil($this->total / $this->query->perPage))],
            'unverified_copies' => RetentionCatalog::UNVERIFIED_COPIES,
            'protected' => ['global_accounts', 'other_organizations', 'immutable_histories'],
            'notice' => $this->items === [] ? __('retention.empty') : __('retention.review_only')];
    }
}
