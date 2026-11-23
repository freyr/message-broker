<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook\Http;

use InvalidArgumentException;

/**
 * The configuration of one {@see HttpDeliverySender}.
 *
 * The proxy is never part of an exception message: its URL can hold
 * credentials.
 */
final readonly class HttpSenderConfig
{
    /**
     * @param int $totalTimeoutMs the total limit of one request, and the deadline of the transfers of one send() call
     * @param int $connectTimeoutMs the limit of the DNS lookup, the TCP connect and the TLS handshake of one request
     * @param int $dnsAllowanceMs the time that the private network check can use for the DNS lookups before
     *                            the transfers start; maxSendMs() is the total limit plus this value; 1 or
     *                            more when the check is on, 0 or more when it is off or a proxy is set
     * @param int $maxHostConnections the parallel connections for each host, for the client that the sender
     *                                creates; use the batch size of the delivery loop or more
     * @param int $clientResetIntervalMs the interval of the reset of the client: the reset clears the DNS cache
     *                                   of the private network check and closes the kept connections
     * @param string|null $proxy an http or https proxy URL; null = no proxy (the proxy environment variables
     *                           are ignored). With a proxy the private network check is off, and the proxy
     *                           must filter internal addresses.
     * @param bool $blockPrivateNetworks refuse a destination in a private network (the IP check of
     *                                   NoPrivateNetworkHttpClient and the pre-check of an IP literal)
     * @param bool $allowHttp permit `http` URLs; by default only `https` URLs are sent
     * @param string $userAgent the value of the User-Agent header
     */
    public function __construct(
        public int $totalTimeoutMs = 10_000,
        public int $connectTimeoutMs = 3_000,
        public int $dnsAllowanceMs = 5_000,
        public int $maxHostConnections = 100,
        public int $clientResetIntervalMs = 300_000,
        public ?string $proxy = null,
        public bool $blockPrivateNetworks = true,
        public bool $allowHttp = false,
        public string $userAgent = 'freyr-message-broker-webhook-http',
    ) {
        foreach ([
            'totalTimeoutMs' => $totalTimeoutMs,
            'connectTimeoutMs' => $connectTimeoutMs,
            'maxHostConnections' => $maxHostConnections,
            'clientResetIntervalMs' => $clientResetIntervalMs,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("\${$name} must be 1 or more, {$value} given");
            }
        }
        if ($dnsAllowanceMs < 0) {
            throw new InvalidArgumentException("\$dnsAllowanceMs must be 0 or more, {$dnsAllowanceMs} given");
        }
        // With the check on, the sender's lookups must end inside the allowance: 0 would fail each host name.
        if ($dnsAllowanceMs < 1 && $blockPrivateNetworks && $proxy === null) {
            throw new InvalidArgumentException(
                "\$dnsAllowanceMs must be 1 or more when the private network check is on, {$dnsAllowanceMs} given",
            );
        }
        if ($connectTimeoutMs > $totalTimeoutMs) {
            throw new InvalidArgumentException('$connectTimeoutMs must not be longer than $totalTimeoutMs');
        }
        if ($proxy !== null && !self::isProxyUrl($proxy)) {
            throw new InvalidArgumentException('$proxy must be an http or https URL with a host');
        }
        if (preg_match('/^[^\x00-\x1f\x7f]+\z/', $userAgent) !== 1) {
            throw new InvalidArgumentException('$userAgent must be a non-empty header value');
        }
    }

    /** The private network check is on when it is switched on and no proxy is configured. */
    public function checksPrivateNetworks(): bool
    {
        return $this->blockPrivateNetworks && $this->proxy === null;
    }

    private static function isProxyUrl(string $proxy): bool
    {
        $url = parse_url($proxy);

        return \is_array($url)
            && \in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            && isset($url['host']);
    }
}
