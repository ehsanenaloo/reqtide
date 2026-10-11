<?php

declare(strict_types=1);

namespace Comparison;

use Ackintosh\Ganesha\Storage\Adapter\ApcuStore;

/**
 * In-process stand-in for the apcu_* functions. ext-apcu (and Redis/Memcached/MongoDB) are not in the
 * Docker image. Ganesha's real Ackintosh\Ganesha\Storage\Adapter\Apcu adapter and both strategies run
 * unmodified on top of this; only the five apcu_* calls are replaced. TTL arguments are ignored because
 * the Apcu adapter never passes one.
 */
final class ArrayApcuStore extends ApcuStore
{
    /** @var array<string,int|string> */
    private array $d = [];

    public function dec($key, $step = 1, &$success = null, $ttl = 0)
    {
        $success = true;

        return $this->d[$key] = (int) ($this->d[$key] ?? 0) - $step;
    }

    public function delete($key)
    {
        unset($this->d[$key]);

        return true;
    }

    public function exists($keys)
    {
        return isset($this->d[$keys]);
    }

    public function fetch($key, &$success = null)
    {
        $success = isset($this->d[$key]);

        return $this->d[$key] ?? false;
    }

    public function inc($key, $step = 1, &$success = null, $ttl = 0)
    {
        $success = true;

        return $this->d[$key] = (int) ($this->d[$key] ?? 0) + $step;
    }

    public function store($key, $var = null, $ttl = 0)
    {
        $this->d[$key] = $var;

        return true;
    }
}
