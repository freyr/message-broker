<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook\Http\Tests\Unit;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A MockHttpClient that records the responses it returns and the timeout of
 * each stream() call.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];

    /** @var list<float|null> */
    public array $streamTimeouts = [];

    /** @param (\Closure(): mixed)|null $onRequest runs before each request, for example to advance a clock */
    public function __construct(
        private readonly MockHttpClient $client,
        private readonly ?\Closure $onRequest = null,
    ) {}

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->onRequest !== null) {
            ($this->onRequest)();
        }
        $response = $this->client->request($method, $url, $options);
        $this->responses[] = $response;

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $this->streamTimeouts[] = $timeout;

        return $this->client->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return new self($this->client->withOptions($options), $this->onRequest);
    }
}
