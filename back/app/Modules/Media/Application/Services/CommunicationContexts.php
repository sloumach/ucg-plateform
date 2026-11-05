<?php

namespace App\Modules\Media\Application\Services;

use App\Modules\Academy\Application\Contracts\CommunicationSessionDirectory;
use App\Modules\Competition\Application\Contracts\CommunicationMatchDirectory;
use App\Modules\Scheduling\Application\Contracts\CommunicationCalendarDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Communication\ContextSource;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final readonly class CommunicationContexts
{
    public const array COLUMNS = ['event' => 'activity_id', 'match' => 'match_id', 'session' => 'session_id'];

    public function __construct(private CommunicationCalendarDirectory $calendar, private CommunicationMatchDirectory $matches,
        private CommunicationSessionDirectory $sessions) {}

    public function source(string $type): ContextSource
    {
        return match ($type) {
            'event' => $this->calendar, 'match' => $this->matches, 'session' => $this->sessions,
            default => throw new InvalidArgumentException('Unsupported communication context.'),
        };
    }

    /** @return array<string, Builder> */
    public function visibleIds(TenantContext $context): array
    {
        $queries = [];
        foreach (self::COLUMNS as $type => $column) {
            $query = $this->source($type)->visibleIds($context);
            if ($query !== null) {
                $queries[$column] = $query;
            }
        }

        return $queries;
    }
}
