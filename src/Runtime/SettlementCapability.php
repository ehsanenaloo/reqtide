<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Runtime;

enum SettlementCapability: string
{
    case InlineReturnOrThrow = 'inline_return_or_throw';
    case TerminalPromiseSettlement = 'terminal_promise_settlement';
}
