<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use RuntimeException;

/**
 * The registry answered 422: the schema itself is invalid/unparseable.
 * PERMANENT — the schema text must be fixed; retrying will not help. Distinct
 * from RegistryUnavailable (transient outage).
 */
final class InvalidSchema extends RuntimeException {}
