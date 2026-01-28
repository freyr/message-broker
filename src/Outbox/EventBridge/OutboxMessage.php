<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Outbox\EventBridge;

/**
 * Allows for symfony/messenger to correctly handle all outbox messages via a single
 * custom handler.
 */
interface OutboxMessage {}
