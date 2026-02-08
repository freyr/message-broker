<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Amqp;

use AMQPConnection;
use InvalidArgumentException;

/**
 * Creates ext-amqp connections from DSN strings.
 *
 * Parses AMQP DSN into credentials, creates connections with
 * sensible timeouts, and sanitises DSN for safe error reporting.
 */
final readonly class AmqpConnectionFactory
{
    public function createConnection(string $dsn): AMQPConnection
    {
        return new AMQPConnection($this->parseDsn($dsn));
    }

    /**
     * Extract the vhost from a DSN string.
     */
    public function extractVhost(string $dsn): string
    {
        $parsed = parse_url($dsn);
        if ($parsed === false) {
            return '/';
        }

        if (isset($parsed['path'])) {
            $path = urldecode(ltrim($parsed['path'], '/'));

            return $path !== '' ? $path : '/';
        }

        return '/';
    }

    /**
     * Redact credentials from a DSN for safe use in error messages.
     */
    public function sanitiseDsn(string $dsn): string
    {
        return preg_replace('#://[^@]+@#', '://***:***@', $dsn) ?? $dsn;
    }

    /**
     * Parse an AMQP DSN into ext-amqp connection credentials.
     *
     * @return array<string, string|int>
     */
    private function parseDsn(string $dsn): array
    {
        $parsed = parse_url($dsn);
        if ($parsed === false) {
            throw new InvalidArgumentException(sprintf('Invalid AMQP DSN: "%s"', $this->sanitiseDsn($dsn)));
        }

        $credentials = [];

        if (isset($parsed['host'])) {
            $credentials['host'] = $parsed['host'];
        }
        if (isset($parsed['port'])) {
            $credentials['port'] = $parsed['port'];
        }
        if (isset($parsed['user'])) {
            $credentials['login'] = urldecode($parsed['user']);
        }
        if (isset($parsed['pass'])) {
            $credentials['password'] = urldecode($parsed['pass']);
        }

        $credentials['vhost'] = $this->extractVhost($dsn);
        $credentials['connect_timeout'] = 10;
        $credentials['read_timeout'] = 10;

        return $credentials;
    }
}
