<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Http\Tests\Unit;

use Freyr\MessageBroker\Adapter\Http\HttpDeliverySender;
use Freyr\MessageBroker\Adapter\Http\HttpSenderConfig;
use Freyr\MessageBroker\Adapter\Http\StandardWebhooksSigner;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Webhook\ClaimedDelivery;
use Freyr\MessageBroker\Webhook\DeliveryPair;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryResultKind;
use Freyr\MessageBroker\Webhook\Subscription;
use Generator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpDeliverySenderTest extends TestCase
{
    private const string SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const string SECOND_SECRET = 'whsec_KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKio=';
    // A public IP literal: the private network check passes it with no DNS lookup.
    private const string URL = 'https://93.184.215.14/hooks/path-x?token=t0k3n';
    private const string BODY = '{"order":"o-1","total":125}';
    private const int CREATED_AT = 1_750_000_000_123;
    private const string NOW = '2026-06-15 12:00:00.250 UTC';
    private const int NOW_SECONDS = 1_781_524_800;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        Clock::set(new \Symfony\Component\Clock\NativeClock());
    }

    public function testEachRequestHasTheHeadersOfTheSpecAndNoProduceTimeHeader(): void
    {
        $response = new MockResponse('', [
            'http_code' => 204,
        ]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(userAgent: 'acme-hooks/2'), new MockHttpClient($response));

        $sender->send([self::pair('d-1', secrets: [self::SECRET, self::SECOND_SECRET])]);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::URL, $response->getRequestUrl());
        $options = $response->getRequestOptions();
        self::assertSame(self::BODY, $options['body']);
        $expected = new StandardWebhooksSigner()->headers(
            'msg-d-1',
            self::NOW_SECONDS,
            self::BODY,
            [self::SECRET, self::SECOND_SECRET],
        );
        self::assertSame([
            'content-type' => ['content-type: application/json'],
            'webhook-id' => ['webhook-id: msg-d-1'],
            'webhook-timestamp' => ['webhook-timestamp: '.self::NOW_SECONDS],
            'webhook-signature' => ['webhook-signature: '.$expected['webhook-signature']],
            'x-message-name' => ['x-message-name: order.placed'],
            'x-created-at' => ['x-created-at: '.self::CREATED_AT],
            'user-agent' => ['user-agent: acme-hooks/2'],
        ], self::withoutHeadersOfTheClient($options['normalized_headers']));
        self::assertStringContainsString(' ', $expected['webhook-signature'], 'Two secrets give two signatures');
    }

    public function testEachRequestFollowsNoRedirectKeepsTheCertificateChecksAndHasTheTimeouts(): void
    {
        $response = new MockResponse('', [
            'http_code' => 200,
        ]);
        // With the private network check off, the mock sees the options of the adapter itself:
        // the decorator gives its own max_redirects and buffer to the client that it decorates.
        $config = new HttpSenderConfig(totalTimeoutMs: 8_500, connectTimeoutMs: 1_250, blockPrivateNetworks: false);
        $sender = new HttpDeliverySender($config, new MockHttpClient($response));

        $sender->send([self::pair('d-1')]);

        $options = $response->getRequestOptions();
        self::assertSame(0, $options['max_redirects']);
        self::assertTrue($options['verify_peer']);
        self::assertTrue($options['verify_host']);
        self::assertSame(8.5, $options['timeout']);
        self::assertSame(8.5, $options['max_duration']);
        self::assertSame(1.25, $options['max_connect_duration']);
        self::assertFalse($options['buffer']);
    }

    public function testWithNoProxyConfiguredTheRequestUsesNoProxyAtAll(): void
    {
        $response = new MockResponse('', [
            'http_code' => 200,
        ]);
        // A proxy in the client of the application is not used.
        $client = new MockHttpClient($response)->withOptions([
            'proxy' => 'http://app-proxy.internal:3128',
        ]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(), $client);

        $sender->send([self::pair('d-1')]);

        $options = $response->getRequestOptions();
        self::assertNull($options['proxy']);
        self::assertSame('*', $options['no_proxy'], 'The proxy environment variables are ignored');
    }

    public function testAConfiguredProxyIsSetOnEachRequest(): void
    {
        $response = new MockResponse('', [
            'http_code' => 200,
        ]);
        $sender = new HttpDeliverySender(
            new HttpSenderConfig(proxy: 'http://egress.internal:3128'),
            new MockHttpClient($response),
        );

        $sender->send([self::pair('d-1')]);

        $options = $response->getRequestOptions();
        self::assertSame('http://egress.internal:3128', $options['proxy']);
        self::assertSame('', $options['no_proxy'], 'The NO_PROXY environment variable is ignored');
    }

    public function testMapsEachResponseToItsResultKeyedByTheDeliveryId(): void
    {
        $client = new MockHttpClient([
            new MockResponse('accepted', [
                'http_code' => 200,
                'total_time' => 1.2346,
            ]),
            new MockResponse('', [
                'http_code' => 410,
                'total_time' => 0.002,
            ]),
            new MockResponse('slow down', [
                'http_code' => 429,
                'total_time' => 0.003,
                'response_headers' => ['Retry-After: 30'],
            ]),
            new MockResponse('', [
                'http_code' => 302,
                'response_headers' => ['Location: http://10.0.0.1/'],
            ]),
        ]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(), $client);

        $results = $sender->send([self::pair('d-1'), self::pair('d-2'), self::pair('d-3'), self::pair('d-4')]);

        self::assertSame(['d-1', 'd-2', 'd-3', 'd-4'], array_keys($results));
        self::assertSame(DeliveryResultKind::Delivered, $results['d-1']->kind);
        self::assertSame(1_235, $results['d-1']->requestMs);
        self::assertSame('HTTP 200: accepted', $results['d-1']->text);
        self::assertSame(DeliveryResultKind::Refused, $results['d-2']->kind);
        self::assertSame('gone', $results['d-2']->reasonCode);
        self::assertSame(2, $results['d-2']->requestMs);
        self::assertSame(DeliveryResultKind::Failed, $results['d-3']->kind);
        self::assertSame(1_781_524_800_250 + 30_000, $results['d-3']->retryAt);
        self::assertSame('HTTP 429: slow down', $results['d-3']->text);
        self::assertSame(DeliveryResultKind::Failed, $results['d-4']->kind);
        self::assertSame('HTTP 302', $results['d-4']->text);
        self::assertSame(4, $client->getRequestsCount(), 'The redirect is not followed');
    }

    public function testStartsAllRequestsBeforeItReadsAResponse(): void
    {
        $events = [];
        $factory = static function (string $method, string $url, array $options) use (&$events): MockResponse {
            $id = $options['user_data'];
            $events[] = 'request '.$id;
            $body = (static function () use (&$events, $id): Generator {
                $events[] = 'read '.$id;
                yield 'ok';
            })();

            return new MockResponse($body, [
                'http_code' => 200,
            ]);
        };
        $sender = new HttpDeliverySender(new HttpSenderConfig(), new MockHttpClient($factory));

        $results = $sender->send([self::pair('d-1'), self::pair('d-2'), self::pair('d-3')]);

        self::assertSame(['request d-1', 'request d-2', 'request d-3'], \array_slice($events, 0, 3));
        self::assertCount(6, $events);
        self::assertCount(3, $results);
    }

    public function testARequestThatIsStillOpenAtTheDeadlineFailsWithTheCategoryTimeout(): void
    {
        $clock = $this->clock;
        // MockResponse reads the whole generator when the request starts: the clock is past the deadline
        // before the first chunk. The two data chunks let the fast response end before the timeout chunk.
        $slow = (static function () use ($clock): Generator {
            yield 'a';
            yield 'b';
            $clock->sleep(10.0); // exactly the total limit
            yield ''; // the client reports no activity
            yield 'after the deadline';
        })();
        $client = new RecordingHttpClient(new MockHttpClient([
            new MockResponse('fast', [
                'http_code' => 200,
            ]),
            new MockResponse($slow, [
                'http_code' => 200,
            ]),
        ]));
        $logger = new RecordingLogger();
        $sender = new HttpDeliverySender(new HttpSenderConfig(blockPrivateNetworks: false), $client, $logger);

        $results = $sender->send([self::pair('d-1'), self::pair('d-2')]);

        self::assertSame(DeliveryResultKind::Delivered, $results['d-1']->kind);
        self::assertSame(DeliveryResultKind::Failed, $results['d-2']->kind);
        self::assertSame('deadline (timeout)', $results['d-2']->text);
        self::assertSame(10_000, $results['d-2']->requestMs);
        self::assertSame('timeout', $logger->records[1]['context']['status']);
        self::assertSame([10.0], $client->streamTimeouts);
        self::assertNotTrue($client->responses[0]->getInfo('canceled'), 'A complete response is not cancelled');
        self::assertTrue($client->responses[1]->getInfo('canceled'), 'The open request is cancelled at the deadline');
    }

    public function testNoActivityBeforeTheDeadlineDoesNotEndTheRequest(): void
    {
        $clock = $this->clock;
        $body = (static function () use ($clock): Generator {
            $clock->sleep(9.999);
            yield ''; // no activity, but the deadline is not reached
            yield 'late but in time';
        })();
        $client = new RecordingHttpClient(new MockHttpClient(new MockResponse($body, [
            'http_code' => 200,
        ])));
        $sender = new HttpDeliverySender(new HttpSenderConfig(blockPrivateNetworks: false), $client);

        $results = $sender->send([self::pair('d-1')]);

        self::assertSame(DeliveryResultKind::Delivered, $results['d-1']->kind);
        self::assertSame('HTTP 200: late but in time', $results['d-1']->text);
        self::assertSame([10.0, 0.001], $client->streamTimeouts, 'The second stream waits for the time that is left');
    }

    public function testATransportErrorOfTheResponseIsAFailedResult(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'error' => 'Could not resolve host: hooks.example.com for "'.self::URL.'".',
            ]),
            new MockResponse('ok', [
                'http_code' => 200,
            ]),
        ]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(), $client);

        $results = $sender->send([self::pair('d-1'), self::pair('d-2')]);

        self::assertSame(DeliveryResultKind::Failed, $results['d-1']->kind);
        self::assertSame(TransportException::class.' (dns)', $results['d-1']->text);
        self::assertSame(DeliveryResultKind::Delivered, $results['d-2']->kind);
    }

    public function testAnErrorInTheBodyIsAFailedResult(): void
    {
        $body = (static function (): Generator {
            yield 'part';
            throw new TransportException('Recv failure: Connection reset by peer for "'.self::URL.'".');
        })();
        $sender = new HttpDeliverySender(new HttpSenderConfig(), new MockHttpClient(new MockResponse($body, [
            'http_code' => 200,
        ])));

        $results = $sender->send([self::pair('d-1')]);

        self::assertSame(DeliveryResultKind::Failed, $results['d-1']->kind);
        self::assertSame(TransportException::class.' (connect)', $results['d-1']->text);
    }

    public function testReadsAtMost500BytesOfTheBody(): void
    {
        $body = (static function (): Generator {
            yield str_repeat('a', 300);
            yield str_repeat('b', 300);
            yield str_repeat('c', 300);
        })();
        $client = new RecordingHttpClient(new MockHttpClient(new MockResponse($body, [
            'http_code' => 500,
        ])));
        $sender = new HttpDeliverySender(new HttpSenderConfig(blockPrivateNetworks: false), $client);

        $results = $sender->send([self::pair('d-1')]);

        self::assertSame('HTTP 500: '.str_repeat('a', 300).str_repeat('b', 200), $results['d-1']->text);
        self::assertTrue($client->responses[0]->getInfo('canceled'), 'The rest of the body is not read');
    }

    public function testTheResultComesAt500BytesWithNoMoreRead(): void
    {
        $body = (static function (): Generator {
            yield str_repeat('a', 500);
            throw new TransportException('Recv failure: Connection reset by peer for "'.self::URL.'".');
        })();
        $sender = new HttpDeliverySender(new HttpSenderConfig(), new MockHttpClient(new MockResponse($body, [
            'http_code' => 200,
        ])));

        $results = $sender->send([self::pair('d-1')]);

        self::assertSame(DeliveryResultKind::Delivered, $results['d-1']->kind);
        self::assertSame('HTTP 200: '.str_repeat('a', 500), $results['d-1']->text);
    }

    public function testASubscriptionThatTheChecksRefuseSendsNoRequest(): void
    {
        $client = new MockHttpClient([
            new MockResponse('ok', [
                'http_code' => 200,
            ])]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(), $client);

        $results = $sender->send([
            self::pair('d-1', url: 'http://93.184.215.14/'),
            self::pair('d-2', secrets: ['not-a-secret']),
            self::pair('d-3'),
        ]);

        self::assertSame('invalid-destination', $results['d-1']->reasonCode);
        self::assertSame('invalid-secret', $results['d-2']->reasonCode);
        self::assertSame(DeliveryResultKind::Delivered, $results['d-3']->kind);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testAnHttpUrlIsSentWhenTheConfigurationPermitsIt(): void
    {
        $client = new MockHttpClient([
            new MockResponse('ok', [
                'http_code' => 200,
            ])]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(allowHttp: true), $client);

        $results = $sender->send([self::pair('d-1', url: 'http://93.184.215.14/')]);

        self::assertSame(DeliveryResultKind::Delivered, $results['d-1']->kind);
    }

    public function testAUrlThatTheClientRefusesIsRefusedForAllTime(): void
    {
        $client = new MockHttpClient([
            new MockResponse('ok', [
                'http_code' => 200,
            ])]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(), $client);

        $results = $sender->send([self::pair('d-1', url: 'https://93.184.215.14/hooks\\x'), self::pair('d-2')]);

        self::assertSame(DeliveryResultKind::Refused, $results['d-1']->kind);
        self::assertSame('invalid-destination', $results['d-1']->reasonCode);
        self::assertSame('The URL is not valid', $results['d-1']->text);
        self::assertSame(0, $results['d-1']->requestMs);
        self::assertSame(DeliveryResultKind::Delivered, $results['d-2']->kind);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testThePrivateNetworkCheckBlocksAHostNameThatResolvesToAPrivateAddress(): void
    {
        $client = new MockHttpClient([
            new MockResponse('ok', [
                'http_code' => 200,
            ])]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(), $client);

        $results = $sender->send([self::pair('d-1', url: 'https://localhost/hook'), self::pair('d-2')]);

        self::assertSame(DeliveryResultKind::Failed, $results['d-1']->kind);
        self::assertSame(TransportException::class.' (blocked)', $results['d-1']->text);
        self::assertSame(0, $results['d-1']->requestMs);
        self::assertSame(DeliveryResultKind::Delivered, $results['d-2']->kind);
        self::assertSame(1, $client->getRequestsCount(), 'The blocked host gets no request');
    }

    public function testThePrivateNetworkCheckCanBeSwitchedOff(): void
    {
        $client = new MockHttpClient([
            new MockResponse('ok', [
                'http_code' => 200,
            ]),
            new MockResponse('ok', [
                'http_code' => 200,
            ]),
        ]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(blockPrivateNetworks: false), $client);

        $results = $sender->send([self::pair('d-1', url: 'https://localhost/hook'), self::pair('d-2', url: 'https://10.0.0.7/hook')]);

        self::assertSame(DeliveryResultKind::Delivered, $results['d-1']->kind);
        self::assertSame(DeliveryResultKind::Delivered, $results['d-2']->kind);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testThePrivateNetworkCheckIsOffWithAProxy(): void
    {
        $client = new MockHttpClient([
            new MockResponse('ok', [
                'http_code' => 200,
            ]),
            new MockResponse('ok', [
                'http_code' => 200,
            ]),
        ]);
        $sender = new HttpDeliverySender(new HttpSenderConfig(proxy: 'http://egress.internal:3128'), $client);

        $results = $sender->send([self::pair('d-1', url: 'https://localhost/hook'), self::pair('d-2', url: 'https://10.0.0.7/hook')]);

        self::assertSame(DeliveryResultKind::Delivered, $results['d-1']->kind);
        self::assertSame(DeliveryResultKind::Delivered, $results['d-2']->kind);
    }

    public function testResetsTheClientAtTheInterval(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('ok', [
            'http_code' => 200,
        ]));
        $client->request('GET', 'https://93.184.215.14/'); // a request before the sender exists
        $sender = new HttpDeliverySender(new HttpSenderConfig(clientResetIntervalMs: 60_000), $client);

        $sender->send([self::pair('d-1')]);
        $this->clock->sleep(59.999);
        $sender->send([self::pair('d-2')]);
        self::assertSame(3, $client->getRequestsCount(), 'No reset before the interval');

        $this->clock->sleep(0.001);
        $sender->send([self::pair('d-3')]);
        self::assertSame(1, $client->getRequestsCount(), 'The reset clears the client (MockHttpClient counts from 0)');

        $this->clock->sleep(59.999);
        $sender->send([self::pair('d-4')]);
        self::assertSame(2, $client->getRequestsCount(), 'The next interval starts at the reset');
    }

    public function testMaxSendMsIsTheTotalLimitPlusTheDnsAllowance(): void
    {
        self::assertSame(15_000, new HttpDeliverySender(new HttpSenderConfig(), new MockHttpClient())->maxSendMs());
        self::assertSame(
            2_700,
            new HttpDeliverySender(new HttpSenderConfig(totalTimeoutMs: 2_000, connectTimeoutMs: 500, dnsAllowanceMs: 700), new MockHttpClient())->maxSendMs(),
        );
    }

    public function testCreatesItsOwnClientWhenNoneIsGiven(): void
    {
        $sender = new HttpDeliverySender();

        self::assertSame(15_000, $sender->maxSendMs());
    }

    public function testADebugLogLineForEachRequestHasTheLaneTheStatusAndTheTime(): void
    {
        $client = new MockHttpClient([
            new MockResponse('echo '.self::BODY, [
                'http_code' => 200,
                'total_time' => 0.0421,
            ]),
            new MockResponse('', [
                'error' => 'Failed to connect to 93.184.215.14 port 443 for "'.self::URL.'".',
                'total_time' => 0.0031,
            ]),
        ]);
        $logger = new RecordingLogger();
        $sender = new HttpDeliverySender(new HttpSenderConfig(), $client, $logger);

        $sender->send([
            self::pair('d-1', lane: 'lane-a'),
            self::pair('d-2', lane: 'lane-b'),
            self::pair('d-3', lane: 'lane-c', secrets: ['bad']),
        ]);

        self::assertSame([
            [
                'level' => LogLevel::DEBUG,
                'message' => 'Webhook request finished',
                'context' => [
                    'lane' => 'lane-c',
                    'delivery_id' => 'd-3',
                    'status' => 'invalid-secret',
                    'result' => 'refused',
                    'request_ms' => 0,
                ],
            ],
            [
                'level' => LogLevel::DEBUG,
                'message' => 'Webhook request finished',
                'context' => [
                    'lane' => 'lane-a',
                    'delivery_id' => 'd-1',
                    'status' => 200,
                    'result' => 'delivered',
                    'request_ms' => 42,
                ],
            ],
            [
                'level' => LogLevel::DEBUG,
                'message' => 'Webhook request finished',
                'context' => [
                    'lane' => 'lane-b',
                    'delivery_id' => 'd-2',
                    'status' => 'connect',
                    'result' => 'failed',
                    'request_ms' => 3,
                ],
            ],
        ], self::sortedByLaneOrder($logger->records, ['lane-c', 'lane-a', 'lane-b']));

        $logged = json_encode($logger->records, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        foreach (['93.184.215.14', 'path-x', 't0k3n', 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 'bad"', '"order"', 'echo'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $logged);
        }
    }

    /** @param list<string> $secrets */
    private static function pair(
        string $id,
        string $url = self::URL,
        array $secrets = [self::SECRET],
        string $lane = 'lane-a',
    ): DeliveryPair {
        return new DeliveryPair(
            new ClaimedDelivery(
                new DeliveryRecord(
                    id: $id,
                    messageId: 'msg-'.$id,
                    lane: $lane,
                    messageName: 'order.placed',
                    body: self::BODY,
                    headers: [
                        'x-tenant' => 'tenant-7',
                        'traceparent' => '00-abc-def-01',
                        'x-message-id' => 'produce-time',
                    ],
                    createdAt: self::CREATED_AT,
                ),
                lease: 1_781_524_860_250,
            ),
            new Subscription($lane, $url, $secrets),
        );
    }

    /**
     * Symfony adds `accept` and `content-length` to each request; the adapter does not set them.
     *
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    private static function withoutHeadersOfTheClient(array $headers): array
    {
        unset($headers['accept'], $headers['content-length']);

        return $headers;
    }

    /**
     * @param list<array{level: mixed, message: string, context: array<mixed>}> $records
     * @param list<string> $lanes
     *
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    private static function sortedByLaneOrder(array $records, array $lanes): array
    {
        usort($records, static fn (array $a, array $b): int => array_search($a['context']['lane'], $lanes, true) <=> array_search($b['context']['lane'], $lanes, true));

        return $records;
    }
}
