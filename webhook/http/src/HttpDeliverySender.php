<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook\Http;

use Closure;
use Freyr\MessageBroker\Serializer\MetadataHeader;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\DeliveryPair;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryResult;
use Freyr\MessageBroker\Webhook\DeliverySender;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sends the deliveries of one claim as parallel, signed `POST` requests with
 * Symfony HttpClient (the {@see DeliverySender} port of the core).
 *
 *  - All requests start first; the responses are read as they arrive, at
 *    most 500 bytes of each body.
 *  - With the private network check on, the sender resolves each new host
 *    name itself before its request, one after the other, and pins the
 *    address for the request (the `resolve` option), so the check tests the
 *    same address that the client connects to. The lookups of one call share
 *    the DNS allowance: a host that does not resolve, or a pair that comes
 *    when the allowance is used up, fails with the category dns and sends no
 *    request. An address is kept until the reset of the client; a failed
 *    lookup is not kept.
 *  - One deadline covers the transfers of the call: the total limit after the
 *    transfers start, and never later than maxSendMs() after the start of
 *    send(). A request that is still open then fails with the category
 *    timeout.
 *  - No redirect is followed. The certificate checks and the proxy options
 *    are set on each request, so a client of the application cannot change
 *    them; the proxy environment variables are ignored.
 *  - NoPrivateNetworkHttpClient decorates the client unless the configuration
 *    switches the check off or names a proxy. The client is reset at an
 *    interval, which clears the DNS cache of that check and closes the kept
 *    connections.
 *  - Each request has `content-type`, the three `webhook-` headers,
 *    `x-message-name`, `x-created-at` and `User-Agent`; the produce-time
 *    headers of the delivery are not sent. A CR, LF or NUL byte in the
 *    message ID or the message name is sent percent-encoded (`%0D`, `%0A`,
 *    `%00`); the signature covers the encoded message ID.
 *  - One debug log line for each request has the lane, the status and the
 *    time; never the URL, a secret, a signature or a body.
 *
 * A client that the application gives keeps its own connection limit for
 * each host; HttpSenderConfig::$maxHostConnections applies to the client that
 * the sender creates.
 */
final class HttpDeliverySender implements DeliverySender
{
    private readonly HttpClientInterface $client;
    private readonly ResponseClassifier $classifier;
    private readonly StandardWebhooksSigner $signer;
    /** @var Closure(string): ?string */
    private readonly Closure $resolver;
    private int $nextResetMs;
    /** @var array<string, string> host name => resolved address, kept until the reset */
    private array $addresses = [];

    /**
     * @param (Closure(string $config): ?string)|null $resolver the DNS lookup of the private network check: the
     *                                                        address of the host, or null when it does not
     *                                                        resolve; null = the lookup of the operating system
     */
    public function __construct(
        private readonly HttpSenderConfig $config = new HttpSenderConfig(),
        ?HttpClientInterface $client = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?Closure $resolver = null,
    ) {
        $client ??= HttpClient::create([], $config->maxHostConnections);
        $this->client = $config->checksPrivateNetworks() ? new NoPrivateNetworkHttpClient($client) : $client;
        $this->classifier = new ResponseClassifier();
        $this->signer = new StandardWebhooksSigner();
        $this->resolver = $resolver ?? self::systemLookup(...);
        $this->nextResetMs = EpochMillis::now() + $config->clientResetIntervalMs;
    }

    /**
     * The total limit plus the DNS allowance. No lookup starts after the
     * allowance, and the transfers end not later than this time after the
     * start of send().
     *
     * One limit remains: PHP cannot interrupt a lookup of the operating
     * system (getaddrinfo() has no timeout). A lookup that starts before the
     * end of the allowance and takes longer than the time that is left of
     * this limit makes send() return late by that excess. With the default
     * lookup the excess is at most two resolver timeouts of the operating
     * system (the A lookup, then the AAAA lookup; each resolv.conf `timeout`
     * times `attempts`). Such a pair fails with the category dns.
     */
    public function maxSendMs(): int
    {
        return $this->config->totalTimeoutMs + $this->config->dnsAllowanceMs;
    }

    public function send(array $pairs): array
    {
        $this->resetWhenDue();
        $sendStartMs = EpochMillis::now();
        $timestamp = intdiv($sendStartMs, 1000);
        $dnsEndMs = $sendStartMs + $this->config->dnsAllowanceMs;

        /** @var array<string, DeliveryResult> $results */
        $results = [];
        /** @var array<int, array{response: ResponseInterface, delivery: DeliveryRecord, body: string}> $open */
        $open = [];
        foreach ($pairs as $pair) {
            $delivery = $pair->claimed->delivery;
            $refusal = $this->classifier->beforeRequest(
                $pair->subscription,
                $this->config->allowHttp,
                $this->config->checksPrivateNetworks(),
            );
            if ($refusal !== null) {
                $results[$delivery->id] = $this->finished($delivery, $refusal, $refusal->reasonCode);
                continue;
            }
            $resolve = $this->config->checksPrivateNetworks() ? $this->lookup($pair->subscription->url, $dnsEndMs) : [];
            if ($resolve instanceof DeliveryResult) {
                $results[$delivery->id] = $this->finished($delivery, $resolve, ResponseClassifier::CATEGORY_DNS);
                continue;
            }
            try {
                $response = $this->client->request('POST', $pair->subscription->url, $this->options($pair, $timestamp, $resolve));
            } catch (InvalidArgumentException) {
                // The client refuses a URL that parse_url() accepts. The options are fixed and the header values
                // have no CR, LF or NUL, so the URL is the only input left that the client can refuse.
                // Symfony's InvalidArgumentException is also a TransportExceptionInterface: this catch comes first.
                $results[$delivery->id] = $this->finished(
                    $delivery,
                    DeliveryResult::refused(ResponseClassifier::REASON_INVALID_DESTINATION, 0, 'The URL is not valid'),
                    ResponseClassifier::REASON_INVALID_DESTINATION,
                );
                continue;
            } catch (TransportExceptionInterface $e) {
                // The private network check refuses a host before the request.
                $results[$delivery->id] = $this->finished(
                    $delivery,
                    $this->classifier->transportError($e, 0),
                    $this->classifier->category($e),
                );
                continue;
            }
            $open[spl_object_id($response)] = [
                'response' => $response,
                'delivery' => $delivery,
                'body' => '',
            ];
        }

        $results += $this->transfer($open, $sendStartMs);

        $ordered = [];
        foreach ($pairs as $pair) {
            $id = $pair->claimed->delivery->id;
            $ordered[$id] = $results[$id];
        }

        return $ordered;
    }

    /**
     * Reads the responses as they arrive, until each has a result or the
     * deadline ends the transfers.
     *
     * @param array<int, array{response: ResponseInterface, delivery: DeliveryRecord, body: string}> $open
     *
     * @return array<string, DeliveryResult>
     */
    private function transfer(array $open, int $sendStartMs): array
    {
        $results = [];
        $startMs = EpochMillis::now();
        $deadlineMs = min($startMs + $this->config->totalTimeoutMs, $sendStartMs + $this->maxSendMs());

        $waitMs = max(0, $deadlineMs - $startMs);

        while ($open !== []) {
            foreach ($this->client->stream(array_column($open, 'response'), $waitMs / 1000) as $response => $chunk) {
                $key = spl_object_id($response);
                if (!isset($open[$key])) {
                    continue; // a response that already has its result
                }
                $delivery = $open[$key]['delivery'];
                try {
                    if ($chunk->isTimeout()) {
                        // No activity: stream again with the time that is left, or end at the deadline.
                        $nowMs = EpochMillis::now();
                        if ($nowMs < $deadlineMs) {
                            $waitMs = $deadlineMs - $nowMs;
                            continue 2;
                        }
                        break 2;
                    }
                    if ($chunk->isFirst()) {
                        // Read the status at the first chunk: a decorated response throws for a 3xx to 5xx status otherwise.
                        $response->getStatusCode();
                    }
                    // The classifier keeps the first 500 bytes; at most one chunk more is read.
                    $body = $open[$key]['body'].$chunk->getContent();
                    $open[$key]['body'] = $body;
                    if (!$chunk->isLast() && \strlen($body) < ResponseClassifier::MAX_BODY_BYTES) {
                        continue;
                    }
                    $status = $response->getStatusCode();
                    $results[$delivery->id] = $this->finished(
                        $delivery,
                        $this->classifier->response(
                            $status,
                            $response->getHeaders(false),
                            $body,
                            self::requestMs($response),
                            EpochMillis::now(),
                        ),
                        $status,
                    );
                    unset($open[$key]);
                    if (!$chunk->isLast()) {
                        $response->cancel(); // the rest of the body is not read
                    }
                } catch (TransportExceptionInterface $e) {
                    $results[$delivery->id] = $this->finished(
                        $delivery,
                        $this->classifier->transportError($e, self::requestMs($response)),
                        $this->classifier->category($e),
                    );
                    unset($open[$key]);
                }
            }
            break;
        }

        // Each request that is still open at the deadline.
        foreach ($open as ['response' => $response, 'delivery' => $delivery]) {
            $response->cancel();
            $results[$delivery->id] = $this->finished(
                $delivery,
                $this->classifier->deadline(EpochMillis::now() - $startMs),
                ResponseClassifier::CATEGORY_TIMEOUT,
            );
        }

        return $results;
    }

    /**
     * The address of the host of the URL, from the kept addresses or from one
     * lookup within the DNS allowance: the `resolve` option of the request
     * (empty for an IP literal), or a failed result with the category dns.
     *
     * @return array<string, string>|DeliveryResult
     */
    private function lookup(string $url, int $dnsEndMs): array|DeliveryResult
    {
        $host = self::asciiHost((string) parse_url($url, \PHP_URL_HOST));
        if (filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP) !== false) {
            return [];
        }
        if (isset($this->addresses[$host])) {
            return [
                $host => $this->addresses[$host],
            ];
        }
        $startMs = EpochMillis::now();
        if ($startMs >= $dnsEndMs) {
            return $this->classifier->dnsAllowanceUsedUp(0);
        }
        $address = ($this->resolver)($host);
        $endMs = EpochMillis::now();
        if ($endMs > $dnsEndMs) {
            return $this->classifier->dnsAllowanceUsedUp($endMs - $startMs);
        }
        if ($address === null) {
            return $this->classifier->noAddress($endMs - $startMs);
        }
        $this->addresses[$host] = $address;

        return [
            $host => $address,
        ];
    }

    /**
     * The host as Symfony HttpClient normalizes it for the request
     * (HttpClientTrait::parseUrl()): the ASCII form of an internationalized
     * name, else the name in lower case. The lookup and the key of the
     * resolve option thus use the name that the client connects to. Without
     * ext-intl or symfony/polyfill-intl-idn a non-ASCII name stays as it is
     * and does not resolve.
     */
    private static function asciiHost(string $host): string
    {
        return \defined('INTL_IDNA_VARIANT_UTS46')
            ? idn_to_ascii($host, \IDNA_DEFAULT | \IDNA_USE_STD3_RULES | \IDNA_CHECK_BIDI | \IDNA_CHECK_CONTEXTJ | \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46) ?: strtolower($host)
            : strtolower($host);
    }

    /**
     * The lookup of NoPrivateNetworkHttpClient: the first IPv4 address, else
     * the first IPv6 address.
     */
    private static function systemLookup(string $host): ?string
    {
        $ipv4 = gethostbynamel($host);
        if ($ipv4 !== false) {
            return $ipv4[0];
        }
        $ipv6 = @dns_get_record($host, \DNS_AAAA);

        return \is_array($ipv6) && isset($ipv6[0]['ipv6']) && \is_string($ipv6[0]['ipv6']) ? $ipv6[0]['ipv6'] : null;
    }

    /** A header value cannot hold CR, LF or NUL: the client refuses it. */
    private static function headerValue(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], ['%0D', '%0A', '%00'], $value);
    }

    /**
     * @param array<string, string> $resolve the pinned address of the host, if any
     *
     * @return array<string, mixed>
     */
    private function options(DeliveryPair $pair, int $timestamp, array $resolve): array
    {
        $delivery = $pair->claimed->delivery;
        $totalSeconds = $this->config->totalTimeoutMs / 1000;

        return [
            'headers' => [
                'content-type' => 'application/json',
                ...$this->signer->headers(
                    self::headerValue($delivery->messageId),
                    $timestamp,
                    $delivery->body,
                    $pair->subscription->secrets,
                ),
                MetadataHeader::MESSAGE_NAME => self::headerValue($delivery->messageName),
                MetadataHeader::CREATED_AT => $delivery->createdAt,
                'user-agent' => $this->config->userAgent,
            ],
            'body' => $delivery->body,
            'resolve' => $resolve,
            'user_data' => $delivery->id,
            'buffer' => false,
            'max_redirects' => 0,
            'timeout' => $totalSeconds,
            'max_duration' => $totalSeconds,
            'max_connect_duration' => $this->config->connectTimeoutMs / 1000,
            'verify_peer' => true,
            'verify_host' => true,
            'proxy' => $this->config->proxy,
            // `*` turns off a proxy of the environment; an empty list ignores NO_PROXY for the configured proxy.
            'no_proxy' => $this->config->proxy === null ? '*' : '',
        ];
    }

    private function resetWhenDue(): void
    {
        $nowMs = EpochMillis::now();
        if ($nowMs < $this->nextResetMs) {
            return;
        }
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
        $this->addresses = [];
        $this->nextResetMs = $nowMs + $this->config->clientResetIntervalMs;
    }

    private function finished(DeliveryRecord $delivery, DeliveryResult $result, int|string|null $status): DeliveryResult
    {
        $this->logger->debug('Webhook request finished', [
            'lane' => $delivery->lane,
            'delivery_id' => $delivery->id,
            'status' => $status,
            'result' => $result->kind->value,
            'request_ms' => $result->requestMs,
        ]);

        return $result;
    }

    private static function requestMs(ResponseInterface $response): int
    {
        /** @var float $totalTime the transfer time in seconds; Symfony HttpClient always sets it */
        $totalTime = $response->getInfo('total_time');

        return (int) round($totalTime * 1000);
    }
}
