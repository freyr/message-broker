<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

/**
 * The global, setup-time wire format (E1). The whole outbox is one uniform
 * format — there is no per-lane or per-message choice. Selects the DDL
 * variant (body JSON vs LONGBLOB) and the wired WireFormat/Deserializer.
 */
enum Format: string
{
    case Json = 'json';
    case Avro = 'avro';
}
