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

        echo "\n=== ENCODED ENVELOPE (before decode) ===\n";
        echo "Headers:\n";
        foreach ($encodedEnvelope['headers'] as $key => $value) {
            echo sprintf("  %s: %s\n", $key, $value);
        }
        echo "\n";

        // When: We decode it with InboxSerializer
        $serializer = $this->getContainer()->get('Freyr\MessageBroker\Serializer\InboxSerializer');
        $envelope = $serializer->decode($encodedEnvelope);

        // Then: Inspect the resulting envelope
        echo "=== DECODED ENVELOPE (after decode) ===\n";
        echo "Message class: " . get_class($envelope->getMessage()) . "\n";

        echo "\nAll stamps:\n";
        foreach ($envelope->all() as $stampClass => $stamps) {
            echo sprintf("  %s: %d stamp(s)\n", $stampClass, count($stamps));
            foreach ($stamps as $stamp) {
                if ($stamp instanceof MessageIdStamp) {
                    echo sprintf("    - MessageIdStamp(messageId='%s')\n", $stamp->messageId);
                } else {
                    echo sprintf("    - %s\n", json_encode($stamp));
                }
            }
        }

        // Verify MessageIdStamp exists
        $messageIdStamp = $envelope->last(MessageIdStamp::class);

        if ($messageIdStamp === null) {
            echo "\n❌ MessageIdStamp NOT FOUND in envelope!\n";
            $this->fail('MessageIdStamp was not deserialized from headers');
        } else {
            echo "\n✅ MessageIdStamp FOUND!\n";
            echo sprintf("   messageId: %s\n", $messageIdStamp->messageId);
            $this->assertEquals($messageId, $messageIdStamp->messageId);
        }
    }
}
