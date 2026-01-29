<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Inbox\MessageIdStamp;

/**
 * Debug test to inspect InboxSerializer deserialization.
 */
final class InboxSerializerDebugTest extends FunctionalTestCase
{
    public function testInspectEnvelopeBeforeAndAfterDecode(): void
    {
        // Given: An encoded envelope like AMQP transport provides
        $messageId = Id::new()->__toString();
        $testId = Id::new();

        $encodedEnvelope = [
            'body' => json_encode([
                'id' => $testId->__toString(),
                'name' => 'serializer-debug',
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ]),
            'headers' => [
                'type' => 'test.event.sent',
                'X-Message-Stamp-Freyr\MessageBroker\Inbox\MessageIdStamp' => json_encode([
                    ['messageId' => $messageId],
                ]),
            ],
        ];

        // When: We decode it with InboxSerializer
        $serializer = $this->getContainer()->get('Freyr\MessageBroker\Serializer\InboxSerializer');
        $envelope = $serializer->decode($encodedEnvelope);

        // Then: Verify MessageIdStamp exists
        $messageIdStamp = $envelope->last(MessageIdStamp::class);

        $this->assertNotNull($messageIdStamp, 'MessageIdStamp was not deserialized from headers');
        $this->assertEquals($messageId, $messageIdStamp->messageId);
    }
}
