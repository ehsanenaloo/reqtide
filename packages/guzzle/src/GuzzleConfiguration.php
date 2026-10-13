<?php

declare(strict_types=1);

namespace Enaxon\Reqtide\Guzzle;

use Enaxon\Reqtide\Exception\InvalidConfigurationException;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

/**
 * The options of {@see ReqtideClient}. Redirects are not followed and a sized body is sent with an empty `Expect` header
 *; neither can be changed here, so a response is always the value the classifier sees.
 *
 * `$clientDefaults` are Guzzle client options, limited to {@see ALLOWED_CLIENT_DEFAULTS}: those that describe the connection,
 * the identity of the caller or the wire format, and are the same for every attempt. Every other option is refused by
 * name, because a client default applies to every transfer outside the replay and identity checks and outside the
 * deadline: `delay` would sleep beyond it, `curl` could replace the timeouts and the connection rules of the transport,
 * `query`, `body`, `json`, `form_params`, `multipart` or `cookies` would change or add to what a request sends, `sink`,
 * `on_headers`, `on_stats` and `progress` would hook into the transfer, and `http_errors`, `allow_redirects`, `timeout`,
 * `connect_timeout`, `read_timeout` and `handler` belong to the client.
 */
final readonly class GuzzleConfiguration
{
    /**
     * Guzzle options that may be set as client defaults. `headers` only fill in headers a request does not have, so they
     * cannot change the identity header that is checked per attempt. The others are connection and TLS settings
     * (`proxy`, `verify`, `cert`, `ssl_key`, `crypto_method`, `force_ip_resolve`), the credentials of the caller (`auth`) and
     * wire-format switches (`version`, `decode_content`).
     *
     * @var list<string>
     */
    public const ALLOWED_CLIENT_DEFAULTS = ['headers', 'auth', 'proxy', 'verify', 'cert', 'ssl_key', 'crypto_method', 'force_ip_resolve', 'version', 'decode_content'];

    public HttpOutcomeClassifier $classifier;

    public ?UriInterface $baseUri;

    /**
     * @param ?HttpOutcomeClassifier $classifier the statuses and cURL errors that are retried, count against a circuit, fall back or set a cooldown; the documented starter set when null
     * @param array<string, mixed> $clientDefaults
     * @param UriInterface|string|null $baseUri an absolute http or https URI that {@see ReqtideClient::uri()} resolves references against
     * @param list<string> $credentialHeaders names of headers that carry a credential besides Authorization and Proxy-Authorization (for example `X-Api-Key`); they become part of the credential fingerprint, never of any output
     * @throws InvalidConfigurationException
     */
    public function __construct(
        ?HttpOutcomeClassifier $classifier = null,
        #[\SensitiveParameter]
        public array $clientDefaults = [],
        UriInterface|string|null $baseUri = null,
        public array $credentialHeaders = [],
    ) {
        foreach ($credentialHeaders as $name) {
            if (!is_string($name) || preg_match('~^[A-Za-z0-9-]{1,64}\z~', $name) !== 1) {
                throw new InvalidConfigurationException('A credential header must be a header name of at most 64 letters, digits and hyphens.');
            }
        }
        if (array_key_exists('handler', $clientDefaults)) {
            throw new InvalidConfigurationException('The handler is owned by the client; remove "handler" from the client defaults.');
        }
        foreach (array_keys($clientDefaults) as $option) {
            if (!in_array($option, self::ALLOWED_CLIENT_DEFAULTS, true)) {
                throw new InvalidConfigurationException(sprintf(
                    'The client default "%s" is not allowed; allowed defaults are: %s.',
                    $option,
                    implode(', ', self::ALLOWED_CLIENT_DEFAULTS),
                ));
            }
        }
        if ($baseUri !== null) {
            try {
                $baseUri = is_string($baseUri) ? new Uri($baseUri) : $baseUri;
            } catch (\InvalidArgumentException $malformed) {
                throw new InvalidConfigurationException('The base URI is malformed.', 0, $malformed);
            }
            if (!in_array($baseUri->getScheme(), ['http', 'https'], true) || $baseUri->getHost() === '') {
                throw new InvalidConfigurationException('The base URI must be an absolute http or https URI.');
            }
        }
        $this->baseUri = $baseUri;
        $this->classifier = $classifier ?? HttpOutcomeClassifier::starter();
    }

    /**
     * The client defaults can hold credentials (Basic and Digest auth, proxy user information, key passphrases, default
     * Authorization headers). A dump shows only the names of the options, never their values.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'classifier' => $this->classifier,
            'clientDefaults' => array_map(static fn(mixed $value): string => '***', $this->clientDefaults),
            'baseUri' => $this->baseUri === null ? null : (string) $this->baseUri->withUserInfo(''),
            'credentialHeaders' => $this->credentialHeaders,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('The client configuration can carry credentials and is not serializable.');
    }
}
