<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer;

use RuntimeException;

/**
 * The received bytes do not form a valid two-section wire document.
 * A malformed message never improves: consumers dead-letter it
 * immediately, without retry.
 */
final class MalformedMessage extends RuntimeException {}
