<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Serializer\InboxSerializer;
use Freyr\MessageBroker\Stamp\MessageIdStamp;

/**
 * Tests InboxSerializer X-Message-Id header handling.
 */
final class InboxSerializerDebugTest extends FunctionalTestCase
{
    public function testSemanticMessageIdHeaderIsDeserialisedCorrectly(): void
    {
        // Given: An encoded envelope with the semantic header
        $messageId = Id::new()->__toString();
        $testId = Id::new();

        $encodedEnvelope = [
            'body' => json_encode([
                'id' => $testId->__toString(),
                'name' => 'semantic-header-test',
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ]),
            'headers' => [
                'type' => 'test.event.sent',
                'X-Message-Id' => $messageId,
            ],
        ];

        // When: We decode it with InboxSerializer
        /** @var InboxSerializer $serializer */
        $serializer = $this->getContainer()
            ->get('Freyr\MessageBroker\Serializer\InboxSerializer');
        $envelope = $serializer->decode($encodedEnvelope);

        // Then: MessageIdStamp exists (created from X-Message-Id header)
        $messageIdStamp = $envelope->last(MessageIdStamp::class);

        $this->assertNotNull($messageIdStamp, 'MessageIdStamp should be created from X-Message-Id header');
        $this->assertEquals($messageId, $messageIdStamp->messageId);
    }
}
