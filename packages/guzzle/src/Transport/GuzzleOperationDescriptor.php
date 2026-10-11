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
 * fingerprint of the origin (scheme, host, port), a bounded operation group, a keyed fingerprint of the credential, and
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
     * Authorization and Proxy-Authorization header values under `$credentialKey`; without a key a random key of this
     * process is used, so the fingerprint then only compares requests within one process.
     *
     * @throws InvalidConfigurationException for a relative URI or an invalid group
     */
    public static function fromRequest(RequestInterface $request, string $operationGroup, OperationSafety $safety, #[\SensitiveParameter] ?string $credentialKey = null): self
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());
        if ($scheme === '' || $host === '') {
            throw new InvalidConfigurationException('The request URI must be absolute (scheme and host).');
        }
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        $credentials = [];
        foreach (['Authorization', 'Proxy-Authorization'] as $name) {
            foreach ($request->getHeader($name) as $value) {
                $credentials[] = $name . ':' . $value;
            }
        }
        $fingerprint = $credentials === [] ? null : substr(hash_hmac('sha256', implode("\0", $credentials), $credentialKey ?? self::processKey()), 0, 32);

        return new self(
            strtoupper($request->getMethod()),
            substr(hash('sha256', 'reqtide-origin-v1|' . $scheme . '|' . $host . '|' . $port), 0, 32),
            $operationGroup,
            $fingerprint,
            $safety,
        );
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
