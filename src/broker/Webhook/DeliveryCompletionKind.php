<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Webhook;

/** What {@see DeliveryStore::complete()} writes for one claimed delivery. */
enum DeliveryCompletionKind: string
{
    /** Final state delivered. */
    case Delivered = 'delivered';

    /** Not final: a new available_at. */
    case Retry = 'retry';

    /** Final state dead, with a dead letter. */
    case Dead = 'dead';
}
