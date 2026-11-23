<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/** The three kinds of the result of one request. */
enum DeliveryResultKind: string
{
    /** The receiver accepted the request. */
    case Delivered = 'delivered';

    /** The request failed; a retry can succeed. */
    case Failed = 'failed';

    /** The request is refused for all time; a retry cannot succeed. */
    case Refused = 'refused';
}
