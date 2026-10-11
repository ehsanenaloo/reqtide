<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Tests\Pipeline\Support;

use Enaxon\Reqtide\Telemetry\Event;
use Enaxon\Reqtide\Telemetry\EventSink;
use Enaxon\Reqtide\Telemetry\OperationCompleted;

final class RecordingSink implements EventSink
{
    /** @var list<Event> */
    public array $events = [];

    public int $emitAttempts = 0;

    public bool $throwOnEvery = false;

    public bool $throwOnCompletion = false;

    public function __construct(private readonly ?CallLog $log = null) {}

    public function emit(Event $event): void
    {
        ++$this->emitAttempts;
        $this->events[] = $event;
        $this->log?->add('event.' . $event::class);
        if ($this->throwOnEvery || ($this->throwOnCompletion && $event instanceof OperationCompleted)) {
            throw new \RuntimeException('sink failure');
        }
    }

    /**
     * @template E of Event
     * @param class-string<E> $class
     * @return list<E>
     */
    public function of(string $class): array
    {
        $found = [];
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                $found[] = $event;
            }
        }

        return $found;
    }
}
