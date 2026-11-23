<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use RuntimeException;

/**
 * The error class of an imported dead letter that the transport moved for a
 * reason other than its delivery limit (for example 'rejected' or 'maxlen'),
 * or that has no move at all. DeadLetterImporter uses only the class name; it
 * never throws it.
 */
final class MovedByTransport extends RuntimeException {}
