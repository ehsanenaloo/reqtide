<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Redis\Tests\Support;

use Enaxon\Reqtide\Redis\Client\PortReply;
use Enaxon\Reqtide\Redis\Client\RedisPort;

/**
 * A port that plays back scripted replies or throwables and records every command it was asked to send.
 */
final class ScriptedPort implements RedisPort
{
    /** @var list<list<string>> */
    public array $sent = [];

    public int $connects = 0;

    /**
     * @param list<PortReply|\Throwable> $script one entry per expected command, in order
     * @param ?\Throwable $connectFailure thrown by connect()
     */
    public function __construct(private array $script, private readonly ?\Throwable $connectFailure = null) {}

    public function connect(): void
    {
        ++$this->connects;
        if ($this->connectFailure !== null) {
            throw $this->connectFailure;
        }
    }

    public function execute(array $arguments): PortReply
    {
        $this->sent[] = $arguments;
        $next = array_shift($this->script);
        if ($next === null) {
            throw new \LogicException('The scripted port was asked for an unscripted command: ' . $arguments[0]);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @return list<string> the command names in the order sent */
    public function names(): array
    {
        return array_map(static fn(array $command): string => $command[0] . (isset($command[1]) && $command[0] === 'SCRIPT' ? ' ' . $command[1] : ''), $this->sent);
    }
}
