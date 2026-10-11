<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

final class NullEventSink implements EventSink
{
    public function emit(Event $event): void {}
}
