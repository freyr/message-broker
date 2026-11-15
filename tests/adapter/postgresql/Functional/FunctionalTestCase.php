<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Adapter\PostgreSql\Testing\PostgreSqlTestSupport;
use PHPUnit\Framework\TestCase;

abstract class FunctionalTestCase extends TestCase
{
    use PostgreSqlTestSupport;
}
