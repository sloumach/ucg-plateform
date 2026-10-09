<?php

namespace App\Support\Queue;

enum QueueName: string
{
    case Critical = 'critical';
    case Default = 'default';
    case Notifications = 'notifications';
    case Broadcasts = 'broadcasts';
    case Reports = 'reports';

    /** @return list<string> */
    public static function orderedValues(): array
    {
        return array_map(
            static fn (self $queue): string => $queue->value,
            self::cases(),
        );
    }
}
