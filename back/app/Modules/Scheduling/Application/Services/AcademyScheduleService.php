<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\Scheduling\Application\Contracts\AcademyActivity;
use App\Modules\Scheduling\Application\Contracts\AcademySchedule;
use App\Modules\Scheduling\Domain\CalendarTime;
use App\Modules\Scheduling\Domain\Contracts\AcademyScheduleRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

final readonly class AcademyScheduleService implements AcademySchedule
{
    public function __construct(private AcademyScheduleRepository $records, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    public function choices(TenantContext $context, string $from, string $to, string $timezone, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'academy.read');
        $this->authorization->authorize($context, 'calendar.read');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Academy calendar choices must be paginated.');
        }
        try {
            [$start, $end] = CalendarTime::window($from, $to, $timezone);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['to_date' => __('calendar.window')]);
        }

        $result = $this->records->choices($context->organizationId, $start, $end, $page, $perPage);

        return new \Illuminate\Pagination\LengthAwarePaginator(array_map(fn (CalendarActivity $record): AcademyActivity => $this->snapshot($record), array_values($result->items())), $result->total(), $perPage, $page);
    }

    public function available(TenantContext $context, array $ids, bool $lock = false): array
    {
        $this->authorization->authorize($context, 'academy.read');
        if (count($ids) > 100 || ($lock && $this->database->transactionLevel() < 1)) {
            throw new LogicException('Academy calendar resolution requires a bounded page and transactional locks.');
        }
        if (! $this->authorization->allows($context, 'calendar.read')) {
            return [];
        }
        $result = [];
        foreach ($this->records->available($context->organizationId, $ids, $lock) as $record) {
            $result[$record->id] = $this->snapshot($record);
        }

        return $result;
    }

    private function snapshot(CalendarActivity $record): AcademyActivity
    {
        return new AcademyActivity($record->id, $record->version, $record->title, $record->starts_at, $record->ends_at, $record->origin_timezone);
    }
}
