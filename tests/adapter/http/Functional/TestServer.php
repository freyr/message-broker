<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Http\Tests\Functional;

use RuntimeException;

/**
 * The client side of the test HTTP server (tools/webhook-test-server/server.mjs).
 *
 * Each instance has its own random path prefix, so parallel test workers that
 * share one server read only the requests of their own test. The base URL
 * comes from WEBHOOK_SERVER_URL (compose: the service webhook-server; CI:
 * localhost). Its host is a name that resolves to a private address.
 */
final readonly class TestServer
{
    public string $baseUrl;
    public string $prefix;

    public function __construct()
    {
        $this->baseUrl = rtrim(getenv('WEBHOOK_SERVER_URL') ?: 'http://webhook-server:8080', '/');
        $this->prefix = '/t-'.bin2hex(random_bytes(8));
    }

    /**
     * The URL of an endpoint of this test.
     *
     * @param array<string, int|string> $answer status, delay (ms), location, retry_after, body
     */
    public function url(string $path, array $answer = []): string
    {
        $query = $answer === [] ? '' : '?'.http_build_query($answer);

        return $this->baseUrl.$this->prefix.'/'.$path.$query;
    }

    public function host(): string
    {
        return (string) parse_url($this->baseUrl, \PHP_URL_HOST);
    }

    /**
     * The recorded requests of this test, in the order of arrival.
     *
     * @return list<array{time_ms: int, method: string, path: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $json = file_get_contents($this->baseUrl.'/_requests?prefix='.rawurlencode($this->prefix));
        if ($json === false) {
            throw new RuntimeException('The test HTTP server does not answer at '.$this->baseUrl);
        }

        /** @var list<array{time_ms: int, method: string, path: string, headers: array<string, string>, body: string}> */
        return json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Polls the request log until it holds $count requests of this test, or
     * the time limit ends; returns what the log holds then.
     *
     * @return list<array{time_ms: int, method: string, path: string, headers: array<string, string>, body: string}>
     */
    public function waitForRequests(int $count, int $limitMs = 5_000): array
    {
        $end = hrtime(true) + $limitMs * 1_000_000;
        do {
            $requests = $this->requests();
            if (\count($requests) >= $count) {
                return $requests;
            }
            usleep(10_000);
        } while (hrtime(true) < $end);

        return $requests;
    }
}
