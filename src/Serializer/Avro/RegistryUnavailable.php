<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use RuntimeException;

/**
 * Transient registry failure (network, 5xx, unparseable response).
 * NOT a MalformedMessage: consumers must let this propagate so the
 * delivery is requeued — a registry outage must never mass-DLQ valid
 * messages (spec A10). On the relay it backs off the lane head (D17).
 */
final class RegistryUnavailable extends RuntimeException {}
