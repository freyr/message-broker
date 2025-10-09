<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Application;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Freyr\MessageBroker\FreyrMessageBrokerBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new FreyrMessageBrokerBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $confDir = $this->getProjectDir() . '/config';

        $loader->load($confDir . '/packages/*.yaml', 'glob');
        $loader->load($confDir . '/services.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // No routes needed for messaging tests
    }
}
