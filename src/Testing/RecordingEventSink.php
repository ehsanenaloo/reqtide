<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Testing;

use Enaxon\Reqtide\Telemetry\Event;
use Enaxon\Reqtide\Telemetry\EventSink;

/**
 * EventSink that stores every event it receives. Optionally throws after recording, to prove that
 * observer failure never replaces a business result.
 *
 * @api-for-tests
 */
final class RecordingEventSink implements EventSink
{
    /** @var list<Event> */
    private array $events = [];
    private ?\Throwable $failure = null;
    private ?int $failuresRemaining = null;

    public function emit(Event $event): void
    {
        $this->events[] = $event;
        if ($this->failure === null) {
            return;
        }
        if ($this->failuresRemaining !== null) {
            if ($this->failuresRemaining <= 0) {
                return;
            }
            --$this->failuresRemaining;
        }

        throw $this->failure;
    }

    /** Throw $failure from emit() after recording the event: on every emit, or only the next $times. */
    public function throwOnEmit(\Throwable $failure, ?int $times = null): void
    {
        if ($times !== null && $times < 0) {
            throw new \InvalidArgumentException('The failure count must not be negative.');
        }
        $this->failure = $failure;
        $this->failuresRemaining = $times;
    }

    public function stopThrowing(): void
    {
        $this->failure = null;
        $this->failuresRemaining = null;
    }

    /** @return list<Event> */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @template E of Event
     * @param class-string<E> $class
     * @return list<E>
     */
    public function ofType(string $class): array
    {
        $matches = [];
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                $matches[] = $event;
            }
        }

        return $matches;
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
