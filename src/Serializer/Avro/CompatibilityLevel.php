<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

/** The Confluent-compat `/config` compatibility levels (design §8). */
enum CompatibilityLevel: string
{
    case Backward = 'BACKWARD';
    case BackwardTransitive = 'BACKWARD_TRANSITIVE';
    case Forward = 'FORWARD';
    case ForwardTransitive = 'FORWARD_TRANSITIVE';
    case Full = 'FULL';
    case FullTransitive = 'FULL_TRANSITIVE';
    case None = 'NONE';
}
