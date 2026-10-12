<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Transport;

use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;

/**
 * The credential material a request carries once the client defaults are applied, in the order and with the precedence
 * that Guzzle uses: the request's own Authorization and Proxy-Authorization headers, default headers for the ones the
 * request lacks, a Basic `auth` default that replaces the Authorization header, Digest and NTLM `auth` defaults (sent by
 * cURL, not as a header) and the user information of a proxy URL that applies to the request. The strings are only ever
 * fed to a keyed hash.
 *
 * @internal
 */
final class EffectiveCredentials
{
    private const NAMES = ['Authorization', 'Proxy-Authorization'];

    /**
     * @param array<string, mixed> $clientDefaults
     * @return list<string>
     */
    public static function material(RequestInterface $request, array $clientDefaults = []): array
    {
        /** @var array<string, list<string>> $headers */
        $headers = [];
        foreach (self::NAMES as $name) {
            $values = $request->getHeader($name);
            if ($values !== []) {
                $headers[$name] = array_values($values);
            } elseif (is_array($clientDefaults['headers'] ?? null)) {
                foreach ($clientDefaults['headers'] as $key => $value) {
                    if (is_string($key) && strcasecmp($key, $name) === 0) {
                        $headers[$name] = array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', (array) $value));
                    }
                }
            }
        }

        $extra = [];
        $auth = $clientDefaults['auth'] ?? null;
        if (is_array($auth) && $auth !== []) {
            $user = is_scalar($auth[0] ?? null) ? (string) $auth[0] : '';
            $secret = is_scalar($auth[1] ?? null) ? (string) $auth[1] : '';
            $type = isset($auth[2]) && is_string($auth[2]) ? strtolower($auth[2]) : 'basic';
            if ($type === 'basic') {
                $headers['Authorization'] = ['Basic ' . base64_encode($user . ':' . $secret)];
            } else {
                $extra[] = 'auth-option:' . $type . ':' . $user . ':' . $secret;
            }
        }
        $userInfo = self::proxyUserInfo($request, $clientDefaults['proxy'] ?? null);
        if ($userInfo !== null) {
            $extra[] = 'proxy-userinfo:' . $userInfo;
        }

        $material = [];
        foreach (self::NAMES as $name) {
            foreach ($headers[$name] ?? [] as $value) {
                $material[] = $name . ':' . $value;
            }
        }

        return [...$material, ...$extra];
    }

    private static function proxyUserInfo(RequestInterface $request, mixed $proxy): ?string
    {
        $uri = $request->getUri();
        if (is_array($proxy)) {
            $no = $proxy['no'] ?? [];
            if (is_array($no) && Utils::isHostInNoProxy($uri->getHost(), array_values(array_filter($no, 'is_string')))) {
                return null;
            }
            $proxy = $proxy[strtolower($uri->getScheme())] ?? null;
        }
        if (!is_string($proxy) || $proxy === '') {
            return null;
        }
        $parts = parse_url(str_contains($proxy, '://') ? $proxy : 'http://' . $proxy);
        if ($parts === false || !isset($parts['user'])) {
            return null;
        }

        return $parts['user'] . ':' . ($parts['pass'] ?? '');
    }
}
