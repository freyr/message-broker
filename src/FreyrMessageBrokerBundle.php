<?php

declare(strict_types=1);

namespace Freyr\MessageBroker;

use Freyr\MessageBroker\DependencyInjection\FreyrMessageBrokerExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class FreyrMessageBrokerBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new FreyrMessageBrokerExtension();
    }
}
