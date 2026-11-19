<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Http\Tests\Functional;

use Freyr\MessageBroker\Adapter\Http\HttpDeliverySender;
use Freyr\MessageBroker\Adapter\Http\HttpSenderConfig;
use Freyr\MessageBroker\Testing\RecordingLogger;
use Freyr\MessageBroker\Time\EpochMillis;
use Freyr\MessageBroker\Webhook\ClaimedDelivery;
use Freyr\MessageBroker\Webhook\DeliveryPair;
use Freyr\MessageBroker\Webhook\DeliveryRecord;
use Freyr\MessageBroker\Webhook\DeliveryResult;
use Freyr\MessageBroker\Webhook\DeliveryResultKind;
use Freyr\MessageBroker\Webhook\Subscription;
use PHPUnit\Framework\TestCase;

/**
 * HttpDeliverySender against a real HTTP server (tools/webhook-test-server).
 *
 * The test server has a private address, so each test that must reach it
 * turns the private network check off; the blocked test keeps it on. Timing
 * bounds are generous, so that a slow CI runner still passes, and each still
 * proves its property. No test depends on the order of parallel requests.
 */
final class HttpDeliverySenderTest extends TestCase
{
    // Not the secret of a unit test: the test computes the signature itself with this key.
    private const string SECRET = 'whsec_'.self::KEY_BASE64;
    private const string KEY_BASE64 = 'c2VjcmV0LWtleS1vZi10aGUtZnVuY3Rpb25hbC10ZXN0';
    private const string BODY = '{"order_id":"o-1","total_cents":4999}';
    private const int CREATED_AT = 1_750_000_000_123;

    private TestServer $server;

    protected function setUp(): void
    {
        $this->server = new TestServer();
    }

    public function testFiftyDeliveriesOfOneSendCallReachTheServerInParallel(): void
    {
        $delayMs = 500;
        $url = $this->server->url('parallel', [
            'status' => 204,
            'delay' => $delayMs,
        ]);
        $pairs = [];
        for ($i = 1; $i <= 50; ++$i) {
            $pairs[] = self::pair("d-{$i}", $url);
        }

        $startNs = hrtime(true);
        $results = self::sender()->send($pairs);
        $elapsedMs = (hrtime(true) - $startNs) / 1_000_000;

        self::assertCount(50, $results);
        foreach ($results as $result) {
            self::assertSame(DeliveryResultKind::Delivered, $result->kind, $result->text);
        }
        self::assertLessThan(50 * $delayMs / 2, $elapsedMs, 'less than half of the sum of the delays');
        $requests = $this->server->requests();
        self::assertCount(50, $requests);
        $arrivals = array_column($requests, 'time_ms');
        self::assertLessThan(
            $delayMs,
            max($arrivals) - min($arrivals),
            'each request arrived before the first answer: all 50 were open at the same time',
        );
    }

    public function testAnEndpointThatDoesNotAnswerFailsWithTheCategoryTimeoutAtTheDeadline(): void
    {
        // Check off: the DNS allowance is not used for lookups, but it is still part of maxSendMs().
        $sender = self::sender(new HttpSenderConfig(
            totalTimeoutMs: 500,
            connectTimeoutMs: 500,
            dnsAllowanceMs: 1_000,
            blockPrivateNetworks: false,
            allowHttp: true,
        ));

        $startNs = hrtime(true);
        $result = $sender->send([self::pair('d-1', $this->server->url('silent', [
            'delay' => 30_000,
        ]))])['d-1'];
        $elapsedMs = (hrtime(true) - $startNs) / 1_000_000;

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertStringEndsWith('(timeout)', $result->text);
        self::assertGreaterThanOrEqual(400, $elapsedMs, 'the request waited until the total limit of 500 ms');
        self::assertLessThanOrEqual($sender->maxSendMs(), $elapsedMs, 'send() returns not later than maxSendMs()');
        self::assertCount(1, $this->server->waitForRequests(1), 'the request reached the server');
    }

    public function testARedirectIsAFailedRequestAndIsNotFollowed(): void
    {
        $target = $this->server->url('second');
        $result = self::sender()->send([self::pair('d-1', $this->server->url('moved', [
            'status' => 302,
            'location' => $target,
        ]))])['d-1'];

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertSame('HTTP 302', $result->text);
        $requests = $this->server->requests();
        self::assertCount(1, $requests, 'the server sees no second request');
        self::assertStringStartsWith($this->server->prefix.'/moved?', $requests[0]['path']);
    }

    public function testA410ResponseIsRefusedWithTheReasonCodeGone(): void
    {
        $result = self::sender()->send([self::pair('d-1', $this->server->url('gone', [
            'status' => 410,
        ]))])['d-1'];

        self::assertSame(DeliveryResultKind::Refused, $result->kind);
        self::assertSame('gone', $result->reasonCode);
        self::assertSame('HTTP 410', $result->text);
    }

    public function testA429ResponseWithRetryAfterGivesTheRetryTime(): void
    {
        $beforeMs = EpochMillis::now();
        $result = self::sender()->send([self::pair('d-1', $this->server->url('busy', [
            'status' => 429,
            'retry_after' => 120,
        ]))])['d-1'];
        $afterMs = EpochMillis::now();

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertSame('HTTP 429', $result->text);
        self::assertNotNull($result->retryAt);
        self::assertGreaterThanOrEqual($beforeMs + 120_000, $result->retryAt);
        self::assertLessThanOrEqual($afterMs + 120_000, $result->retryAt);
    }

    public function testAHostNameThatResolvesToAPrivateAddressIsBlockedWhenTheCheckIsOn(): void
    {
        $sender = self::sender(new HttpSenderConfig(totalTimeoutMs: 2_000, connectTimeoutMs: 1_000, allowHttp: true));
        $url = $this->server->url('blocked');
        self::assertFalse(filter_var($this->server->host(), \FILTER_VALIDATE_IP), 'the host is a name, not an IP literal');

        $result = $sender->send([self::pair('d-1', $url)])['d-1'];

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertStringEndsWith('(blocked)', $result->text);
        self::assertSame([], $this->server->requests(), 'no request reached the server');
    }

    public function testAHostThatDoesNotResolveFailsWithTheCategoryDns(): void
    {
        $sender = self::sender(new HttpSenderConfig(totalTimeoutMs: 2_000, connectTimeoutMs: 1_000, allowHttp: true));

        $result = $sender->send([self::pair('d-1', 'http://no-such-host.invalid/hook')])['d-1'];

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertStringEndsWith('(dns)', $result->text);
    }

    public function testTheRecordedRequestHasTheWebhookHeadersAndASignatureThatTheSecretVerifies(): void
    {
        $beforeSeconds = intdiv(EpochMillis::now(), 1000);
        self::sender()->send([self::pair('d-1', $this->server->url('signed'))]);
        $afterSeconds = intdiv(EpochMillis::now(), 1000);

        $requests = $this->server->requests();
        self::assertCount(1, $requests);
        $request = $requests[0];
        $headers = $request['headers'];
        self::assertSame('POST', $request['method']);
        self::assertSame(self::BODY, $request['body'], 'the body is the stored bytes');
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('msg-d-1', $headers['webhook-id']);
        self::assertSame('order.placed', $headers['x-message-name']);
        self::assertSame((string) self::CREATED_AT, $headers['x-created-at']);
        $timestamp = (int) $headers['webhook-timestamp'];
        self::assertGreaterThanOrEqual($beforeSeconds, $timestamp);
        self::assertLessThanOrEqual($afterSeconds, $timestamp);

        // The verification of a receiver (Standard Webhooks), computed here and not by the signer under test.
        $key = base64_decode(self::KEY_BASE64, true);
        self::assertIsString($key);
        $signed = $headers['webhook-id'].'.'.$headers['webhook-timestamp'].'.'.$request['body'];
        $expected = 'v1,'.base64_encode(hash_hmac('sha256', $signed, $key, true));
        self::assertSame($expected, $headers['webhook-signature']);
    }

    public function testNoResultTextAndNoLogLineOfTheRunHoldsTheUrlOrTheSecret(): void
    {
        $logger = new RecordingLogger();
        $open = self::sender(logger: $logger);
        $checked = new HttpDeliverySender(
            new HttpSenderConfig(totalTimeoutMs: 1_000, connectTimeoutMs: 500, allowHttp: true),
            logger: $logger,
        );
        $short = new HttpDeliverySender(
            new HttpSenderConfig(totalTimeoutMs: 300, connectTimeoutMs: 300, blockPrivateNetworks: false, allowHttp: true),
            logger: $logger,
        );
        $closedPort = 'http://'.$this->server->host().':1'.$this->server->prefix.'/closed';
        $urls = [
            $this->server->url('ok', [
                'body' => 'thanks',
            ]),
            $this->server->url('error', [
                'status' => 500,
                'body' => 'upstream down',
            ]),
            $this->server->url('moved', [
                'status' => 302,
                'location' => $this->server->url('second'),
            ]),
            $this->server->url('gone', [
                'status' => 410,
            ]),
            $this->server->url('busy', [
                'status' => 503,
                'retry_after' => 5,
            ]),
            $closedPort,
        ];

        /** @var list<DeliveryResult> $results */
        $results = [];
        foreach ($urls as $i => $url) {
            $results[] = $open->send([self::pair("d-{$i}", $url)])["d-{$i}"];
        }
        $results[] = $checked->send([self::pair('d-blocked', $this->server->url('blocked'))])['d-blocked'];
        $results[] = $checked->send([self::pair('d-dns', 'http://no-such-host.invalid'.$this->server->prefix)])['d-dns'];
        $results[] = $short->send([self::pair('d-slow', $this->server->url('slow', [
            'delay' => 30_000,
        ]))])['d-slow'];

        self::assertCount(9, $results);
        self::assertCount(9, $logger->records, 'one log line for each request');
        $texts = array_map(static fn (DeliveryResult $result): string => $result->text, $results);
        $logLines = array_map(
            static fn (array $record): string => $record['message'].' '.json_encode($record['context'], \JSON_THROW_ON_ERROR),
            $logger->records,
        );
        foreach ([...$texts, ...$logLines] as $line) {
            self::assertStringNotContainsString($this->server->prefix, $line, 'no URL path');
            self::assertStringNotContainsString($this->server->host(), $line, 'no host');
            self::assertStringNotContainsString('no-such-host', $line, 'no host');
            self::assertStringNotContainsString('://', $line, 'no URL');
            self::assertStringNotContainsString(self::KEY_BASE64, $line, 'no secret');
        }
        // The run covered the response and each transport error category that the server can give.
        self::assertSame(['HTTP 200: thanks', 'HTTP 500: upstream down', 'HTTP 302', 'HTTP 410', 'HTTP 503'], \array_slice($texts, 0, 5));
        self::assertStringEndsWith('(connect)', $texts[5]);
        self::assertStringEndsWith('(blocked)', $texts[6]);
        self::assertStringEndsWith('(dns)', $texts[7]);
        self::assertStringEndsWith('(timeout)', $texts[8]);
    }

    /** A sender that may reach the test server: the private network check is off and `http` is permitted. */
    private static function sender(?HttpSenderConfig $config = null, ?RecordingLogger $logger = null): HttpDeliverySender
    {
        return new HttpDeliverySender(
            $config ?? new HttpSenderConfig(totalTimeoutMs: 5_000, connectTimeoutMs: 2_000, blockPrivateNetworks: false, allowHttp: true),
            logger: $logger ?? new RecordingLogger(),
        );
    }

    private static function pair(string $id, string $url): DeliveryPair
    {
        return new DeliveryPair(
            new ClaimedDelivery(
                new DeliveryRecord(
                    id: $id,
                    messageId: 'msg-'.$id,
                    lane: 'lane-a',
                    messageName: 'order.placed',
                    body: self::BODY,
                    headers: [],
                    createdAt: self::CREATED_AT,
                ),
                lease: EpochMillis::now() + 60_000,
            ),
            new Subscription('lane-a', $url, [self::SECRET]),
        );
    }
}
