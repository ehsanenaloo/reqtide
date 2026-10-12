<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle\Transport;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use Enaxon\Reqtide\Execution\BoundedMetadata;
use Enaxon\Reqtide\Execution\OperationMetadata;
use Enaxon\Reqtide\Execution\OperationSafety;
use Psr\Http\Message\RequestInterface;

/**
 * The only request information that crosses into Core: the normalized method, a
 * fingerprint of the origin (scheme, host, port), a bounded operation group, a keyed fingerprint of the credentials the request carries, and
 * the replay safety. It never holds or accepts a URI, a path, a query, a header or a body, so none of them can reach an
 * event, a Problem or a summary.
 */
final readonly class GuzzleOperationDescriptor
{
    private const TOKEN = '~^[A-Za-z0-9_.:-]{1,64}\z~';

    private const FINGERPRINT = '~^[0-9a-f]{32}\z~';

    public function __construct(
        public string $normalizedMethod,
        public string $originFingerprint,
        public string $operationGroup,
        public ?string $credentialFingerprint,
        public OperationSafety $safety,
    ) {
        if (preg_match('~^[A-Z]{1,16}\z~', $normalizedMethod) !== 1) {
            throw new InvalidConfigurationException('The HTTP method must be 1 to 16 upper-case letters.');
        }
        if (preg_match(self::FINGERPRINT, $originFingerprint) !== 1 || ($credentialFingerprint !== null && preg_match(self::FINGERPRINT, $credentialFingerprint) !== 1)) {
            throw new InvalidConfigurationException('Fingerprints must be 32 lower-case hexadecimal characters.');
        }
        if (preg_match(self::TOKEN, $operationGroup) !== 1) {
            throw new InvalidConfigurationException('The operation group must be 1 to 64 letters, digits, dots, colons, underscores or hyphens.');
        }
    }

    /**
     * Derives the descriptor from an absolute request. The credential fingerprint is an HMAC (SHA-256) of the
     * Authorization and Proxy-Authorization header values of `$request` under `$credentialKey`; without a key a random
     * key of this process is used, so the fingerprint then only compares requests within one process. It describes the
     * request as given; {@see resolvedFor()} adds what the client defaults contribute.
     *
     * @throws InvalidConfigurationException for a relative URI, a scheme other than http or https, or an invalid group
     */
    public static function fromRequest(RequestInterface $request, string $operationGroup, OperationSafety $safety, #[\SensitiveParameter] ?string $credentialKey = null): self
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());
        if ($scheme === '' || $host === '') {
            throw new InvalidConfigurationException('The request URI must be absolute (scheme and host).');
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            // The transport is HTTP only. This is not a destination filter: a caller that accepts URLs from users still
            // has to decide which hosts may be reached.
            throw new InvalidConfigurationException('The request URI must use http or https.');
        }
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        return new self(
            strtoupper($request->getMethod()),
            substr(hash('sha256', 'reqtide-origin-v1|' . $scheme . '|' . $host . '|' . $port), 0, 32),
            $operationGroup,
            self::fingerprint(EffectiveCredentials::material($request), $credentialKey),
            $safety,
        );
    }

    /**
     * The same descriptor with the credential fingerprint of the request as the client sends it: the client defaults
     * `headers`, `auth` and `proxy` are applied the way Guzzle applies them.
     *
     * @param array<string, mixed> $clientDefaults
     * @param list<string> $credentialHeaders
     */
    public function resolvedFor(RequestInterface $request, array $clientDefaults, #[\SensitiveParameter] ?string $credentialKey, array $credentialHeaders = []): self
    {
        return new self(
            $this->normalizedMethod,
            $this->originFingerprint,
            $this->operationGroup,
            self::fingerprint(EffectiveCredentials::material($request, $clientDefaults, $credentialHeaders), $credentialKey),
            $this->safety,
        );
    }

    /** @param list<string> $material */
    private static function fingerprint(array $material, #[\SensitiveParameter] ?string $credentialKey): ?string
    {
        return $material === [] ? null : substr(hash_hmac('sha256', implode("\0", $material), $credentialKey ?? self::processKey()), 0, 32);
    }

    public function toCoreMetadata(): OperationMetadata
    {
        return new OperationMetadata(
            $this->safety,
            new BoundedMetadata(array_filter([
                'http_method' => $this->normalizedMethod,
                'origin_fingerprint' => $this->originFingerprint,
                'operation_group' => $this->operationGroup,
                'credential_fingerprint' => $this->credentialFingerprint,
            ], static fn(mixed $value): bool => $value !== null)),
        );
    }

    private static function processKey(): string
    {
        static $key = null;

        return $key ??= random_bytes(32);
    }
}
