<?php

/**
 * Function shadows: PHP resolves an unqualified function call inside a namespace to a function of the
 * same name in that namespace before falling back to the global one. Defining time()/microtime()/usleep()
 * in the namespaces of the libraries under test lets their UNMODIFIED code run on a virtual clock.
 *
 * Only unqualified calls are redirected. A library that calls \time() or \usleep() with a leading
 * backslash is not affected (GuzzleHttp\Handler\MockHandler does this for 'delay'; the harness handles
 * that case separately). Namespaces were chosen by grepping the vendored sources of each package.
 */

declare(strict_types=1);

namespace Symfony\Component\RateLimiter {
    function time(): int { return (int) \floor(\Comparison\VirtualTime::now()); }
    function microtime(bool $f = false): float|string { return $f ? \Comparison\VirtualTime::now() : \sprintf('%.8F %d', \fmod(\Comparison\VirtualTime::now(), 1), (int) \Comparison\VirtualTime::now()); }
    function usleep(int $us): void { \Comparison\VirtualTime::advance($us / 1_000_000); }
}
namespace Symfony\Component\RateLimiter\Policy {
    function time(): int { return (int) \floor(\Comparison\VirtualTime::now()); }
    function microtime(bool $f = false): float|string { return \Comparison\VirtualTime::now(); }
}
namespace Symfony\Component\RateLimiter\Storage {
    function microtime(bool $f = false): float|string { return \Comparison\VirtualTime::now(); }
}
namespace Symfony\Component\RateLimiter\Util {
    function time(): int { return (int) \floor(\Comparison\VirtualTime::now()); }
}
namespace Symfony\Component\HttpClient {
    function time(): int { return (int) \floor(\Comparison\VirtualTime::now()); }
}
namespace Symfony\Component\HttpClient\Response {
    function microtime(bool $f = false): float|string { return \Comparison\VirtualTime::now(); }
}
namespace GuzzleRetry {
    function time(): int { return (int) \floor(\Comparison\VirtualTime::now()); }
}
namespace Ackintosh\Ganesha\Strategy {
    function time(): int { return (int) \floor(\Comparison\VirtualTime::now()); }
}
