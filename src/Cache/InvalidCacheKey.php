<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Cache;

use Psr\Cache\InvalidArgumentException;

/** PSR-6 reserved-character / empty-key violation. */
final class InvalidCacheKey extends \InvalidArgumentException implements InvalidArgumentException {}
