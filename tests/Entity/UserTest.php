<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testAccountTypeKeepsOtherRoles(): void
    {
        $user = (new User())->setUsername('alex')->setRoles(['ROLE_ANALYST', 'ROLE_USER']);
        self::assertFalse($user->isAdmin());
        $user->setAdmin(true);
        self::assertTrue($user->isAdmin());
        self::assertSame(['ROLE_ADMIN', 'ROLE_ANALYST', 'ROLE_USER'], array_values($user->getRoles()));
        $user->setAdmin(false);
        self::assertSame(['ROLE_ANALYST', 'ROLE_USER'], array_values($user->getRoles()));
    }

    public function testDisplayNameAndEmailAreOptionalAndValidated(): void
    {
        $user = (new User())->setUsername('alex');
        self::assertSame('alex', $user->getLabel());
        $user->setDisplayName('  Alex Example ')->setEmail(' alex@example.test ');
        self::assertSame('Alex Example', $user->getLabel());
        self::assertSame('alex@example.test', $user->getEmail());
        $user->setDisplayName('')->setEmail(null);
        self::assertNull($user->getDisplayName());
        self::assertNull($user->getEmail());

        foreach ([static fn () => $user->setDisplayName(str_repeat('a', 121)), static fn () => $user->setDisplayName("Alex\u{0007}"), static fn () => $user->setEmail('alex@')] as $invalid) {
            try {
                $invalid();
                self::fail('An invalid value must be refused.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertNull($user->getDisplayName(), 'a refused value changes nothing');
    }

    public function testAccessIsValidatedWhenSetAndFailsClosedWhenRead(): void
    {
        $user = (new User())->setUsername('alex');
        self::assertSame(['permission_groups' => [], 'websites' => ['all' => false, 'groups' => [], 'sites' => []], 'pages' => []], $user->getAccess());

        $user->setAccess(['permission_groups' => ['editors'], 'pages' => ['setup' => 'view']]);
        self::assertSame(['editors'], $user->getAccess()['permission_groups']);
        self::assertSame(['setup' => 'view'], $user->getAccess()['pages']);

        // No access is stored as null, not an empty document.
        $user->setAccess([]);
        self::assertNull((new \ReflectionProperty($user, 'access'))->getValue($user));

        try {
            $user->setAccess(['pages' => ['setup' => 'owner']]);
            self::fail('An invalid level must be refused.');
        } catch (\InvalidArgumentException) {
        }
        (new \ReflectionProperty($user, 'access'))->setValue($user, ['pages' => ['setup' => 'owner']]);
        self::assertSame([], $user->getAccess()['pages'], 'a damaged stored value grants nothing');
    }
}
