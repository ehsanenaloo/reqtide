<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Telemetry;

interface EventSink
{
    public function emit(Event $event): void;
}
