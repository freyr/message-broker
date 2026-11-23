<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Transport\Amqp\Tests\Functional;

use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
use PHPUnit\Framework\TestCase;

abstract class AmqpFunctionalTestCase extends TestCase
{
    use PostgreSqlTestSupport;
}
