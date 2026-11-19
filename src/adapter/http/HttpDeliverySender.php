<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Http;

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
 *  - One deadline (the total limit of one request) covers the transfers of
 *    the call; a request that is still open then fails with the category
 *    timeout. maxSendMs() adds the DNS allowance of the private network check,
 *    which resolves each new host before the transfers start.
 *  - No redirect is followed. The certificate checks and the proxy options
 *    are set on each request, so a client of the application cannot change
 *    them; the proxy environment variables are ignored.
 *  - NoPrivateNetworkHttpClient decorates the client unless the configuration
 *    switches the check off or names a proxy. The client is reset at an
 *    interval, which clears the DNS cache of that check and closes the kept
 *    connections.
 *  - Each request has `content-type`, the three `webhook-` headers,
 *    `x-message-name`, `x-created-at` and `User-Agent`; the produce-time
 *    headers of the delivery are not sent.
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
    private int $nextResetMs;

    public function __construct(
        private readonly HttpSenderConfig $config = new HttpSenderConfig(),
        ?HttpClientInterface $client = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $client ??= HttpClient::create([], $config->maxHostConnections);
        $this->client = $config->checksPrivateNetworks() ? new NoPrivateNetworkHttpClient($client) : $client;
        $this->classifier = new ResponseClassifier();
        $this->signer = new StandardWebhooksSigner();
        $this->nextResetMs = EpochMillis::now() + $config->clientResetIntervalMs;
    }

    public function maxSendMs(): int
    {
        return $this->config->totalTimeoutMs + $this->config->dnsAllowanceMs;
    }

    public function send(array $pairs): array
    {
        $this->resetWhenDue();
        $timestamp = intdiv(EpochMillis::now(), 1000);

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
            try {
                $response = $this->client->request('POST', $pair->subscription->url, $this->options($pair, $timestamp));
            } catch (InvalidArgumentException) {
                // The client refuses a URL that parse_url() accepts. Symfony's InvalidArgumentException is
                // also a TransportExceptionInterface, so this catch comes first.
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

        $results += $this->transfer($open);

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
    private function transfer(array $open): array
    {
        $results = [];
        $startMs = EpochMillis::now();
        $deadlineMs = $startMs + $this->config->totalTimeoutMs;

        $waitMs = $this->config->totalTimeoutMs;

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

    /** @return array<string, mixed> */
    private function options(DeliveryPair $pair, int $timestamp): array
    {
        $delivery = $pair->claimed->delivery;
        $totalSeconds = $this->config->totalTimeoutMs / 1000;

        return [
            'headers' => [
                'content-type' => 'application/json',
                ...$this->signer->headers($delivery->messageId, $timestamp, $delivery->body, $pair->subscription->secrets),
                MetadataHeader::MESSAGE_NAME => $delivery->messageName,
                MetadataHeader::CREATED_AT => $delivery->createdAt,
                'user-agent' => $this->config->userAgent,
            ],
            'body' => $delivery->body,
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
