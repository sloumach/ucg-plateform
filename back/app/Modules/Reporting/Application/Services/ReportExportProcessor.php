<?php

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Reporting\Domain\Contracts\DashboardIndicatorRepository;
use App\Modules\Reporting\Domain\Contracts\ReportExportRepository;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantResourceLimits;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final readonly class ReportExportProcessor
{
    public function __construct(private ReportExportService $service, private DashboardService $dashboards,
        private ReportExportRepository $records, private DashboardIndicatorRepository $sources,
        private TenantArtifacts $artifacts, private TenantResourceLimits $limits, private TenantCache $cache,
        private TenantBusinessGuard $guard, private ConnectionInterface $database, private Encrypter $crypt) {}

    public function process(TenantContext $context, string $id): void
    {
        $this->cache->synchronized($context, 'report-export:'.$id, 120, function () use ($context, $id): void {
            $row = $this->records->find($context->organizationId, $context->actorUserId, $id);
            $this->service->authorize($context, $row);
            if ($row->status !== 'queued') {
                return;
            }
            $this->service->assertLive($row, $context);
            if (! $this->service->enabled()) {
                $this->service->conflict('disabled');
            }
            $version = $this->sources->generation($context->organizationId, $row->card);
            $started = hrtime(true);
            $generated = now()->toISOString();
            $stream = fopen('php://temp/maxmemory:1048576', 'w+');
            if ($stream === false) {
                throw new RuntimeException('Cannot prepare export buffer.');
            }
            $count = 0;
            $seen = [];
            try {
                $this->write($stream, ['organization_id', 'report', 'view', 'period_from', 'period_to', 'timezone', 'generated_at', 'id', 'label', 'state', 'at', 'value']);
                $page = 1;
                do {
                    $this->service->assertLive($row, $context);
                    if (hrtime(true) - $started > 40_000_000_000) {
                        $this->service->conflict('limit');
                    }
                    $feed = $this->dashboards->show($context, $row->card, ['view' => $row->view, 'from' => $row->from_date, 'to' => $row->to_date, 'page' => $page]);
                    $total = $feed['total'];
                    $this->limits->assertExport($context, $total);
                    if ($total > ReportExportService::MAX_ROWS) {
                        $this->service->conflict('limit');
                    }
                    foreach ($feed['items'] as $item) {
                        if (isset($seen[$item['id']]) || ++$count > ReportExportService::MAX_ROWS) {
                            $this->service->conflict('stale');
                        }
                        $seen[$item['id']] = true;
                        $this->write($stream, [$context->organizationId, $row->card, $row->view, $feed['period']['from'], $feed['period']['to'], $row->timezone, $generated,
                            $item['id'], $item['label'], $item['state'], $item['at'], $item['value']]);
                        if (ftell($stream) > ReportExportService::MAX_BYTES) {
                            $this->service->conflict('limit');
                        }
                    }
                    $lastPage = $feed['meta']['last_page'];
                    $page++;
                } while ($page <= $lastPage && $page <= ReportExportService::MAX_ROWS);
                $this->limits->assertExport($context, $count);
                if ($count !== $total || $page <= $lastPage || $version !== $this->sources->generation($context->organizationId, $row->card)) {
                    $this->service->conflict('stale');
                }
                rewind($stream);
                $contents = stream_get_contents($stream);
                if ($contents === false) {
                    throw new RuntimeException('Cannot read export buffer.');
                }
            } finally {
                fclose($stream);
            }
            // Reservation commits outside the report transaction; expiry also cleans crash leftovers.
            $artifact = $this->artifacts->store($context, 'report-'.$id.'.csv.enc', $this->crypt->encryptString($contents), $row->expires_at);
            $this->database->transaction(function () use ($context, $id, $version, $count, $contents, $artifact, $generated): void {
                $this->guard->lock($context);
                $row = $this->records->find($context->organizationId, $context->actorUserId, $id, true);
                $this->service->authorize($context, $row);
                $this->service->assertLive($row, $context);
                if ($row->status !== 'queued' || ! $this->service->enabled()
                    || $version !== $this->sources->generation($context->organizationId, $row->card, true)) {
                    $this->service->conflict('stale');
                }
                $this->records->save($row, ['status' => 'completed', 'artifact_id' => $artifact->id, 'source_version' => $version,
                    'row_count' => $count, 'digest' => hash('sha256', $contents), 'generated_at' => $generated]);
                $this->service->record($context, $row, 'generated');
            }, 3);
        });
    }

    /** @param resource $stream
     * @param list<string|int|float|null> $values */
    private function write($stream, array $values): void
    {
        // Quoting alone does not prevent spreadsheet formulas, including after leading whitespace.
        $safe = array_map(function ($value): string {
            $text = str_replace(["\0", "\r"], '', (string) $value);

            return preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $text) ? "'".$text : $text;
        }, $values);
        if (fputcsv($stream, $safe, ';', '"', '', "\r\n") === false) {
            throw new RuntimeException('Cannot write export row.');
        }
    }
}
