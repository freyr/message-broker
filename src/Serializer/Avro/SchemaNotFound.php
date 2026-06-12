<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use RuntimeException;

/**
 * The registry answered 404: subject or schema id is not registered.
 * Operational, not malformed — the fix is registering the schema (the CI
 * step), so consumers propagate (requeue) and relays retry with backoff.
 */
final class SchemaNotFound extends RuntimeException {}
