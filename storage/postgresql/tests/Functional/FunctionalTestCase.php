<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use Freyr\MessageBroker\Storage\PostgreSql\Testing\PostgreSqlTestSupport;
use PHPUnit\Framework\TestCase;

abstract class FunctionalTestCase extends TestCase
{
    use PostgreSqlTestSupport;
}
