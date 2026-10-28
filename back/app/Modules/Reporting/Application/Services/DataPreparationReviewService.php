<?php

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Reporting\Application\Validation\DataPreparationInput;
use App\Modules\Reporting\Domain\Contracts\DataSourceBundle;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class DataPreparationReviewService
{
    public function __construct(private DataPreparationService $audit, private DataSourceBundle $files, private Factory $validator) {}

    /** Re-audit current bytes; a declaration is never an authenticated business signature.
     * @return array<string,mixed>
     */
    public function review(string $manifest, string $organization, string $reviewFile): array
    {
        $report = $this->audit->audit($manifest, $organization)->data;
        $bundle = $this->files->manifest($manifest);
        try {
            $review = json_decode($this->files->read($bundle['root'], $reviewFile, 65536), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['review' => __('data_audit.invalid_review')]);
        }
        $reference = ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._:-]{1,63}$/D'];
        $data = $this->validator->make(['review' => $review, 'organization' => $organization, 'digest' => $report['plan_digest']], [
            'review' => ['required', 'array:version,organization_id,plan_digest,reviewer_reference,reviewed_on,categories,source_reviews,checks,decision'],
            'review.version' => ['required', 'integer', 'in:1'],
            'review.organization_id' => ['required', 'uuid', 'same:organization'],
            'review.plan_digest' => ['required', 'string', 'same:digest'],
            'review.reviewer_reference' => $reference,
            'review.reviewed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'review.categories' => ['required', 'array', 'list', 'size:'.count(DataPreparationInput::CATEGORIES)],
            'review.categories.*' => ['required', 'array:category,state,evidence_reference'],
            'review.categories.*.category' => ['required', Rule::in(DataPreparationInput::CATEGORIES), 'distinct:strict'],
            'review.categories.*.state' => ['required', Rule::in(['included', 'no_source', 'deferred'])],
            'review.categories.*.evidence_reference' => $reference,
            'review.source_reviews' => ['required', 'array', 'list', 'size:'.count($report['sources'])],
            'review.source_reviews.*' => ['required', 'array:source_id,owner_reference,purpose_reference,retention_reference,decision'],
            'review.source_reviews.*.source_id' => ['required', Rule::in(array_column($report['sources'], 'source_id')), 'distinct:strict'],
            'review.source_reviews.*.owner_reference' => $reference,
            'review.source_reviews.*.purpose_reference' => $reference,
            'review.source_reviews.*.retention_reference' => $reference,
            'review.source_reviews.*.decision' => ['required', Rule::in(['pending', 'approved'])],
            'review.checks' => ['required', 'array:references,duplicates,quotas,unnecessary_data,retention,inventory_complete', 'required_array_keys:references,duplicates,quotas,unnecessary_data,retention,inventory_complete'],
            'review.checks.*' => ['required', 'array:state,evidence_reference'],
            'review.checks.*.state' => ['required', Rule::in(['pending', 'reviewed'])],
            'review.checks.*.evidence_reference' => $reference,
            'review.decision' => ['required', Rule::in(['pending', 'approved'])],
        ])->validate()['review'];
        $present = array_column($report['sources'], 'category');
        $pending = [];
        foreach ($data['categories'] as $category) {
            $included = in_array($category['category'], $present, true);
            if (($category['state'] === 'included' && ! $included) || ($category['state'] === 'no_source' && $included)) {
                throw ValidationException::withMessages(['review' => __('data_audit.invalid_review')]);
            }
            if ($category['state'] === 'deferred') {
                $pending[] = 'category:'.$category['category'];
            }
        }
        foreach ($data['source_reviews'] as $source) {
            if ($source['decision'] !== 'approved') {
                $pending[] = 'source:'.$source['source_id'];
            }
        }
        foreach ($data['checks'] as $name => $check) {
            if ($check['state'] !== 'reviewed') {
                $pending[] = 'check:'.$name;
            }
        }
        if ($data['decision'] !== 'approved') {
            $pending[] = 'business_signoff';
        }
        $real = $report['dataset_kind_declared'] === 'real';
        $blocked = $report['status'] === 'blocked';
        $status = $blocked ? 'blocked' : (! $real ? 'awaiting_real_sources' : ($pending === [] ? 'business_approval_declared' : 'review_incomplete'));

        return ['version' => 1, 'objective' => 'UCG-DATA-001', 'mode' => 'read_only', 'organization_id' => $organization,
            'plan_digest' => $report['plan_digest'], 'review_sha256' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
            'status' => $status, 'audit' => $report, 'pending' => $pending, 'business_approval_declared' => $data['decision'] === 'approved',
            'business_signature_verified' => false, 'database_checked' => false, 'import_authorized' => false,
            'review_scope' => 'operator_declarations', 'source_completeness_verified' => false,
            'data001_closed' => false, 'files_modified' => false];
    }
}
