<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Tests\Fixtures\PostgreSqlTestSupport;
use PHPUnit\Framework\TestCase;

abstract class FunctionalTestCase extends TestCase
{
    use PostgreSqlTestSupport;
}
