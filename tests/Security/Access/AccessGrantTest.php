<?php

declare(strict_types=1);

namespace App\Tests\Security\Access;

use App\Security\Access\AccessGrant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessGrantTest extends TestCase
{
    public function testNothingIsNoAccess(): void
    {
        foreach ([null, []] as $empty) {
            $grant = AccessGrant::normalize($empty);
            self::assertSame(AccessGrant::empty(), $grant);
            self::assertTrue(AccessGrant::isEmpty($grant));
        }
    }

    public function testAGrantIsReturnedInOneShapeAndPageOrder(): void
    {
        $grant = AccessGrant::normalize([
            'pages' => ['tag_manager' => 'edit', 'websites' => 'view'],
            'websites' => ['sites' => ['token-a', 'token-a', '123'], 'groups' => ['brand-a']],
        ]);

        self::assertSame([
            'websites' => ['all' => false, 'groups' => ['brand-a'], 'sites' => ['token-a', '123']],
            'pages' => ['websites' => 'view', 'tag_manager' => 'edit'],
        ], $grant);
        self::assertFalse(AccessGrant::isEmpty($grant));
        self::assertSame('123', $grant['websites']['sites'][1], 'a numeric token stays a string');
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('invalidGrants')]
    public function testAnythingNotUnderstoodIsRefusedRatherThanWidened(mixed $raw, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        AccessGrant::normalize($raw);
    }

    public static function invalidGrants(): iterable
    {
        yield 'a list' => [['tag_manager'], 'mapping'];
        yield 'unknown key' => [['roles' => ['admin']], 'Unknown access setting "roles"'];
        yield 'users cannot be granted' => [['pages' => ['users' => 'edit']], 'Unknown page "users"'];
        yield 'unknown level' => [['pages' => ['tag_manager' => 'admin']], 'Choose view or edit for Tag manager lite'];
        yield 'boolean level' => [['pages' => ['tag_manager' => true]], 'Choose view or edit'];
        yield 'edit on a view-only page' => [['pages' => ['event_examples' => 'edit']], 'Choose view for Event examples'];
        yield 'all as a string' => [['websites' => ['all' => 'yes']], 'true or false'];
        yield 'unknown website key' => [['websites' => ['domains' => ['a.test']]], 'Unknown website access setting "domains"'];
        yield 'group id with a slash' => [['websites' => ['groups' => ['../etc']]], 'not a valid website group id'];
        yield 'token with a space' => [['websites' => ['sites' => ['a token']]], 'not a valid website token'];
        yield 'sites as a mapping' => [['websites' => ['sites' => ['a' => 'b']]], 'must be in a list'];
    }

    public function testAUsersAccessAddsPermissionGroups(): void
    {
        self::assertSame(['permission_groups' => ['editors']] + AccessGrant::empty(), AccessGrant::normalizeUser(['permission_groups' => ['editors', 'editors']]));
        self::assertSame(['permission_groups' => []] + AccessGrant::empty(), AccessGrant::normalizeUser(null));
        $this->expectException(\InvalidArgumentException::class);
        AccessGrant::normalizeUser(['permission_groups' => ['Not A Slug']]);
    }

    public function testTheSharedFormFieldsBecomeAGrant(): void
    {
        self::assertSame([
            'websites' => ['all' => false, 'groups' => ['brand-a'], 'sites' => ['token-a']],
            'pages' => ['setup' => 'view', 'consent_manager' => 'edit'],
        ], AccessGrant::fromForm([
            'website_mode' => 'selected',
            'website_groups' => ['brand-a'],
            'websites' => ['token-a'],
            'pages' => ['consent_manager' => 'edit', 'setup' => 'view', 'branding' => 'none', 'updates' => ''],
        ]));
        // Every website replaces the selected ones.
        self::assertSame(['all' => true, 'groups' => [], 'sites' => []], AccessGrant::fromForm([
            'website_mode' => 'all', 'websites' => ['token-a'], 'pages' => [],
        ])['websites']);

        $this->expectException(\InvalidArgumentException::class);
        AccessGrant::fromForm(['website_mode' => 'some', 'pages' => []]);
    }
}
