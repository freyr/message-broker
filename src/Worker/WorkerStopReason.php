<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Worker;

/** The limit that stopped a worker run. */
enum WorkerStopReason: string
{
    case MessageLimit = 'message limit';
    case TimeLimit = 'time limit';
    case MemoryLimit = 'memory limit';
    case FailureLimit = 'failure limit';
}
