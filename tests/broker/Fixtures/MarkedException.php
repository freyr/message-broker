<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Fixtures;

use RuntimeException;

final class MarkedException extends RuntimeException implements MarkerThrowable {}
