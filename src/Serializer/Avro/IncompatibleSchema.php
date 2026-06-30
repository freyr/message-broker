<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use RuntimeException;

/**
 * The registry answered 409: the schema is well-formed but violates the
 * subject's compatibility policy. PERMANENT — retrying will not help; this is
 * the signal a CI registration gate must surface and fail on. Distinct from
 * RegistryUnavailable (transient outage) so callers can branch.
 */
final class IncompatibleSchema extends RuntimeException {}
