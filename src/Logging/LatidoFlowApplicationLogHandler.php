<?php

namespace LatidoFlow\Laravel\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

final class LatidoFlowApplicationLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly ApplicationLogBuffer $buffer,
        Level|int|string $level = Level::Warning,
        private readonly bool $enabled = false,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->enabled && parent::isHandling($record);
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer->add(
            strtolower($record->level->getName()),
            $record->message,
            $record->context,
            $record->datetime->format(DATE_ATOM),
        );
    }
}
