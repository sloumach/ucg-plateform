<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Data\ManualErasureReport;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Domain\Contracts\ErasureJournal;
use Illuminate\Contracts\Config\Repository;

final readonly class ErasureReconciliationService
{
    public function __construct(private ErasureJournal $journal, private ManualErasureService $erasures, private Repository $config) {}

    public function run(string $org, OrganizationAuditData $audit, ?string $expectedHead = null, ?string $confirmation = null): ManualErasureReport
    {
        // Reuse the same fresh server authorization without exposing another tenant's journal.
        $this->erasures->plan($org, $audit, 'organization', $org);
        $head = $this->journal->head($org);
        $intents = $this->journal->intents($org, 0, 1000);
        if ($expectedHead === null) {
            return new ManualErasureReport(['journal_head' => $head, 'intents' => $intents, 'production_qualified' => false]);
        }
        $epoch = $this->config->get('retention.erasure.restore_epoch');
        if ($confirmation !== $org || ! hash_equals($head, $expectedHead) || ! is_string($epoch)) {
            throw new DomainConflictException(__('retention.restore_blocked'), 'ERASURE_RESTORE_BLOCKED');
        }
        $imported = $this->erasures->importPreservations($org, $audit);
        if ($imported > 0) {
            return new ManualErasureReport(['preservation_holds_imported' => $imported, 'manual_hold_review_required' => true, 'production_qualified' => false]);
        }
        foreach ($intents as $intent) {
            $result = $this->erasures->replayIntent($org, $audit, $intent, $epoch, $head);
            if ($result->data['operation']['status'] !== 'completed') {
                return $result;
            }
            if ($intent['scope'] === 'organization') {
                return new ManualErasureReport(['organization_permanently_quarantined' => true, 'production_qualified' => false]);
            }
        }

        return $this->erasures->finishReconciliation($org, $audit, $epoch, $head);
    }
}
