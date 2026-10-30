<?php

namespace App\Support\Operations;

use Illuminate\Log\Logger;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

final class SanitizeOperationsLog
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (! $monolog instanceof \Monolog\Logger) {
            throw new \LogicException('OPERATIONS_LOGGER_INVALID');
        }
        $formatter = new class extends JsonFormatter
        {
            public function format(LogRecord $record): string
            {
                $context = [];
                $id = $record->context['request_id'] ?? null;
                if (is_string($id) && Str::isUuid($id)) {
                    $context['request_id'] = $id;
                }
                foreach (['status', 'duration_ms'] as $key) {
                    $value = $record->context[$key] ?? null;
                    if (is_int($value) && $value >= 0) {
                        $context[$key] = $value;
                    }
                }

                return parent::format($record->with(message: 'ops.api.request_review', context: $context, extra: []));
            }
        };
        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter($formatter);
            }
        }
    }
}
