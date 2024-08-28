<?php

declare(strict_types=1);

namespace Freyr\MessageBroker;

use Ramsey\Uuid\UuidInterface;

class Hash
{
    public static function convert(UuidInterface $uuid): int
    {
        // Convert the UUID to a SHA-256 hash
        $hash = hash('sha256', $uuid->toString());

        // Take the first 8 characters of the hash to reduce the size
        $hashPrefix = substr($hash, 0, 8);

        // Convert the hash prefix from hexadecimal to an integer
        $integer = hexdec($hashPrefix);

        // Map the integer to one of the 5 buckets (1-5)
        $bucket = ($integer % 100) + 1;

        return $bucket;
    }
}
