<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp;

use Freyr\MessageBroker\Consumer\Delivery;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * The mapping AMQPMessage → Delivery, so that the consumer and the import of a
 * dead letter queue build a Delivery in the same way.
 *
 * The headers are the application headers as native PHP values (an AMQP
 * timestamp becomes a \DateTime). The attempt is the x-attempt header when it
 * is an int (at least 1), else 1. The delivery count is the x-delivery-count
 * header (quorum queues) when it is an int, else null. The fallback message id
 * is the message_id property when it is a string.
 *
 * @internal
 */
final readonly class AmqpDeliveryMapper
{
    /** @param string $source the queue that the messages come from */
    public function __construct(
        private string $source,
    ) {}

    public function map(AMQPMessage $message): Delivery
    {
        /** @var array<string, mixed> $properties */
        $properties = $message->get_properties();
        $headers = $properties['application_headers'] ?? null;
        /** @var array<string, mixed> $applicationHeaders */
        $applicationHeaders = $headers instanceof AMQPTable ? $headers->getNativeData() : [];

        $attempt = $applicationHeaders[WaitQueuePublisher::ATTEMPT_HEADER] ?? null;
        $deliveryCount = $applicationHeaders[WaitQueuePublisher::DELIVERY_COUNT_HEADER] ?? null;
        $messageId = $properties['message_id'] ?? null;

        return new Delivery(
            body: $message->getBody(),
            headers: $applicationHeaders,
            attempt: is_int($attempt) ? max(1, $attempt) : 1,
            source: $this->source,
            fallbackMessageId: is_string($messageId) ? $messageId : null,
            deliveryCount: is_int($deliveryCount) ? $deliveryCount : null,
        );
    }
}
