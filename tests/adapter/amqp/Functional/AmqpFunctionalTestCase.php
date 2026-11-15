<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Amqp\Tests\Functional;

use Freyr\MessageBroker\Adapter\PostgreSql\Testing\PostgreSqlTestSupport;
use PHPUnit\Framework\TestCase;

abstract class AmqpFunctionalTestCase extends TestCase
{
    use PostgreSqlTestSupport;
}
