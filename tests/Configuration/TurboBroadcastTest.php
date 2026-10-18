<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\Turbo\Doctrine\BroadcastListener;

/**
 * Saving an event must not run Turbo's broadcast listener, which builds Twig
 * and the translator on collection requests that render nothing.
 */
final class TurboBroadcastTest extends KernelTestCase
{
    public function testSavingAnEntityRunsNoBroadcastListener(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        self::assertFalse($container->has('turbo.doctrine.event_listener'));

        $events = $container->get(EntityManagerInterface::class)->getEventManager();
        foreach ([Events::onFlush, Events::postFlush] as $event) {
            foreach ($events->getListeners($event) as $listener) {
                self::assertNotInstanceOf(BroadcastListener::class, $listener, $event);
            }
        }
    }
}
