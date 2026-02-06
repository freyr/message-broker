<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Freyr\MessageBroker\FreyrMessageBrokerBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Test kernel for functional tests.
 *
 * Loads minimal Symfony configuration with:
 * - FrameworkBundle (core Symfony features)
 * - DoctrineBundle (database connectivity)
 * - FreyrMessageBrokerBundle (the bundle under test)
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @return array<\Symfony\Component\HttpKernel\Bundle\BundleInterface> */
    public function registerBundles(): array
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new FreyrMessageBrokerBundle()];
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/config/test.yaml');

        // Doctrine ORM lazy object strategy depends on PHP version:
        // - PHP 8.4+: native lazy objects (symfony/var-exporter 8.0 removed LazyGhost support)
        // - PHP 8.2–8.3: lazy ghost objects via symfony/var-exporter LazyGhostTrait
        if (\PHP_VERSION_ID >= 80400) {
            $container->loadFromExtension('doctrine', [
                'orm' => [
                    'enable_native_lazy_objects' => true,
                ],
            ]);
        } else {
            $container->loadFromExtension('doctrine', [
                'orm' => [
                    'enable_lazy_ghost_objects' => true,
                ],
            ]);
        }
    }
}
