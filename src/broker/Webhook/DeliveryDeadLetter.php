<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

use Freyr\MessageBroker\DeadLetter\DeadLetter;
use Freyr\MessageBroker\Time\EpochMillis;
use Symfony\Component\Uid\Uuid;

/**
 * The dead letter of a webhook delivery row, in one place for the delivery
 * loop and for each {@see DeliveryStore}: the source is `webhook:<lane>`, the
 * message ID, name, body and headers are those of the row, and the trace is
 * empty. The error class is the reason.
 */
final readonly class DeliveryDeadLetter
{
    public const string SOURCE_PREFIX = 'webhook:';
    public const string REASON_FAILED = 'failed';
    public const string REASON_REFUSED = 'refused';
    public const string REASON_ABANDONED = 'abandoned';

    /**
     * @param string $reason the error class: one of the REASON_* values
     * @param int $attempts the attempt count of the dead letter
     * @param int $failedAt epoch milliseconds
     */
    public static function of(DeliveryRecord $record, string $reason, string $message, int $attempts, int $failedAt): DeadLetter
    {
        return new DeadLetter(
            id: Uuid::v7()->toString(),
            source: self::SOURCE_PREFIX.$record->lane,
            messageId: $record->messageId,
            messageName: $record->messageName,
            body: $record->body,
            headers: $record->headers,
            errorClass: $reason,
            errorMessage: $message,
            errorTrace: '',
            attempts: $attempts,
            failedAt: $failedAt,
        );
    }

    /**
     * The dead letter of a row that an operator abandoned with
     * {@see DeliveryStore::abandon()}: the attempts of the row, because an
     * abandon sends no request.
     *
     * @param int $before the date of the abandon, epoch milliseconds
     * @param int $failedAt epoch milliseconds
     */
    public static function abandoned(DeliveryRecord $record, int $before, int $failedAt): DeadLetter
    {
        return self::of(
            $record,
            self::REASON_ABANDONED,
            'Abandoned by an operator: created before '.EpochMillis::toDateTime($before)->format('Y-m-d H:i:s.v').' UTC',
            $record->attempts,
            $failedAt,
        );
    }
}
