<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Unit\DeadLetter;

use Freyr\MessageBroker\DeadLetter\ReplayService;
use Freyr\MessageBroker\Observability\BrokerEvents;
use Freyr\MessageBroker\Serializer\JsonWireFormat;
use Freyr\MessageBroker\Tests\Fixtures\DeadLetterFactory;
use Freyr\MessageBroker\Tests\Fixtures\InMemoryDeadLetterStore;
use Freyr\MessageBroker\Tests\Fixtures\RecordingEvents;
use Freyr\MessageBroker\Tests\Fixtures\RecordingOutboxStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReplayServiceTest extends TestCase
{
    private InMemoryDeadLetterStore $store;
    private RecordingOutboxStore $outbox;
    private RecordingEvents $events;
    private ReplayService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryDeadLetterStore();
        $this->outbox = new RecordingOutboxStore();
        $this->events = new RecordingEvents();
        $this->service = new ReplayService($this->store, $this->outbox, new JsonWireFormat(), $this->events);
    }

    public function testReplayRecordsAnEventWithMessageIdNameAndLane(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', messageId: 'm-42', messageName: 'order.placed'));

        $this->service->replay('dl-1', 'orders');

        self::assertSame([[
            'event' => BrokerEvents::REPLAYED,
            'context' => [
                'message_id' => 'm-42',
                'message_name' => 'order.placed',
                'lane' => 'orders',
            ],
        ]], $this->events->records);
    }

    public function testUnknownDeadLetterIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Dead letter 'nope' not found");

        $this->service->replay('nope');
    }

    /** @return iterable<string, array{string, string}> */
    public static function unreplayableBodies(): iterable
    {
        yield 'not json' => ['raw bytes', 'body is not a replayable wire document'];
        yield 'json scalar' => ['123', 'body is not a replayable wire document'];
        yield 'metadata missing' => ['{"payload":{"a":1}}', 'body is not a replayable wire document'];
        yield 'payload missing' => ['{"metadata":{"message_name":"x"}}', 'body is not a replayable wire document'];
        yield 'payload is not an object' => [
            '{"metadata":{"message_name":"x"},"payload":"text"}',
            'document has non-object metadata/payload',
        ];
        yield 'metadata is not an object' => [
            '{"metadata":"text","payload":{"a":1}}',
            'document has non-object metadata/payload',
        ];
        yield 'message name is not a string' => [
            '{"metadata":{"message_name":5},"payload":{"a":1}}',
            'metadata has no string message_name',
        ];
    }

    public function testRawBytesBodyExplainsWhyItIsNotReplayable(): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', body: 'raw bytes'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Dead letter 'dl-1' body is not a replayable wire document "
            .'(stage-1 dead letters store raw bytes and are not replayable)',
        );

        $this->service->replay('dl-1');
    }

    #[DataProvider('unreplayableBodies')]
    public function testUnreplayableBodyIsRefusedAndNothingIsEnqueued(string $body, string $expectedMessage): void
    {
        $this->store->add(DeadLetterFactory::make('dl-1', body: $body));

        try {
            $this->service->replay('dl-1');
            self::fail('Expected a RuntimeException');
        } catch (RuntimeException $error) {
            self::assertStringContainsString("Dead letter 'dl-1' ", $error->getMessage());
            self::assertStringContainsString($expectedMessage, $error->getMessage());
        }

        self::assertSame([], $this->outbox->inserted);
        self::assertSame([], $this->events->records);
        self::assertNull($this->store->find('dl-1')?->replayedAt);
    }
}
