<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the controllers a server role does not route to, once the service
 * files are loaded, so their dependencies are never built on that server.
 */
final class RemoveControllersPass implements CompilerPassInterface
{
    /** @param list<string> $serviceIds */
    public function __construct(private readonly array $serviceIds)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->serviceIds as $serviceId) {
            if ($container->hasDefinition($serviceId)) {
                $container->removeDefinition($serviceId);
            }
        }
    }
}
