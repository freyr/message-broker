<?php

declare(strict_types=1);

namespace Freyr\MessageBroker;

use Freyr\MessageBroker\Time\EpochMillis;
use Symfony\Component\Uid\Uuid;

/**
 * Producer-side base class. Userland extends it with a named constructor
 * that performs explicit, hand-written normalization:
 *
 *     final class OrderPlaced extends Message
 *     {
 *         public static function create(Order $order): self
 *         {
 *             return new self(
 *                 key: (string) $order->id,
 *                 name: 'order.placed',
 *                 payload: [
 *                     'order_id'    => (string) $order->id,
 *                     'total_cents' => $order->total->cents,
 *                 ],
 *             );
 *         }
 *     }
 *
 * id and createdAt are generated here — userland cannot forget or forge them.
 */
class Message
{
    public readonly string $id;
    public readonly int $createdAt;

    /** @param array<string, mixed> $payload */
    protected function __construct(
        public readonly string $key,
        public readonly string $name,
        private readonly array $payload,
    ) {
        $this->id = Uuid::v7()->toString();
        $this->createdAt = EpochMillis::now();
    }

    /**
     * The canonical two-section wire document. Final: the envelope shape is
     * library-controlled, userland controls only the payload content.
     *
     * @return array{metadata: array<string, mixed>, payload: array<string, mixed>}
     */
    final public function wire(): array
    {
        return [
            'metadata' => [
                'message_name' => $this->name,
                'message_id' => $this->id,
                'created_at' => $this->createdAt,
            ],
            'payload' => $this->payload,
        ];
    }
}
