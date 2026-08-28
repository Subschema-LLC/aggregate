<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Service\GoalEventRegistry;
use App\Service\PrivacySanitizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GoalEventsConfigTest extends KernelTestCase
{
    public function testConfiguredGoalsAreValidAndAvailableToTheRegistry(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $definitions = $container->getParameter('app.goal_events');
        self::assertIsArray($definitions);
        self::assertNotSame([], $definitions);

        foreach ($definitions as $name => $definition) {
            self::assertIsString($name);
            self::assertTrue(PrivacySanitizer::isSafeEventName($name));
            self::assertIsArray($definition);
            self::assertIsString($definition['label'] ?? null);
            self::assertNotSame('', trim($definition['label']));
            self::assertIsBool($definition['enabled'] ?? null);
            self::assertIsBool($definition['anonymous'] ?? null);
        }

        $registry = $container->get(GoalEventRegistry::class);
        self::assertInstanceOf(GoalEventRegistry::class, $registry);
        self::assertSame('purchase', $registry->resolve('purchase', anonymousMode: true));
    }
}
