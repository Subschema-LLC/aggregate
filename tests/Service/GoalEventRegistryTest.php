<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GoalEventRegistry;
use App\Service\PrivacySanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoalEventRegistryTest extends TestCase
{
    public function testEnabledGoalRespectsItsAnonymousSetting(): void
    {
        $registry = $this->registry();

        self::assertSame('purchase', $registry->resolve('purchase', anonymousMode: true));
        self::assertSame('subscription', $registry->resolve('subscription', anonymousMode: false));
        self::assertNull($registry->resolve('subscription', anonymousMode: true));
        self::assertNull($registry->resolve('download', anonymousMode: false));
    }

    #[DataProvider('nonExactCandidates')]
    public function testGoalMatchingIsExactAndCaseSensitive(string $candidate): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->wasSubmitted($candidate));
        self::assertNull($registry->resolve($candidate, anonymousMode: true));
    }

    public static function nonExactCandidates(): iterable
    {
        yield 'leading whitespace' => [' purchase'];
        yield 'trailing whitespace' => ['purchase '];
        yield 'case variant' => ['Purchase'];
    }

    #[DataProvider('rejectedCandidates')]
    public function testUnknownOrUnsafeSubmittedGoalIsRejectedWithoutChangingSubmissionSemantics(mixed $candidate): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->wasSubmitted($candidate));
        self::assertNull($registry->resolve($candidate, anonymousMode: true));
    }

    public static function rejectedCandidates(): iterable
    {
        yield 'unknown safe name' => ['custom_goal'];
        yield 'email' => ['person@example.com'];
        yield 'numeric identifier' => ['order_123456'];
        yield 'non-string' => [['purchase']];
    }

    #[DataProvider('absentCandidates')]
    public function testNullAndBlankCandidatesAreAbsentRatherThanRejected(mixed $candidate): void
    {
        $registry = $this->registry();

        self::assertFalse($registry->wasSubmitted($candidate));
        self::assertNull($registry->resolve($candidate, anonymousMode: true));
    }

    public static function absentCandidates(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\n"];
    }

    public function testInvalidConfigurationFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('boolean anonymous option');

        new GoalEventRegistry(new PrivacySanitizer(), [
            'purchase' => [
                'label' => 'Purchase',
                'enabled' => true,
                'anonymous' => 'yes',
            ],
        ]);
    }

    private function registry(): GoalEventRegistry
    {
        return new GoalEventRegistry(new PrivacySanitizer(), [
            'purchase' => [
                'label' => 'Purchase',
                'enabled' => true,
                'anonymous' => true,
            ],
            'subscription' => [
                'label' => 'Subscription',
                'enabled' => true,
                'anonymous' => false,
            ],
            'download' => [
                'label' => 'Download',
                'enabled' => false,
                'anonymous' => true,
            ],
        ]);
    }
}
