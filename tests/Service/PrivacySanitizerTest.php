<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PrivacySanitizer;
use PHPUnit\Framework\TestCase;

final class PrivacySanitizerTest extends TestCase
{
    private PrivacySanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new PrivacySanitizer();
    }

    public function testPagePathDropsOriginQueryFragmentAndCommonIdentifiers(): void
    {
        self::assertSame(
            '/orders/_redacted/profile/_redacted',
            $this->sanitizer->sanitizePagePath(
                'https://shop.example/orders/123/profile/alice%40example.com?token=secret#details',
            ),
        );
        self::assertSame(
            '/users/_redacted',
            $this->sanitizer->sanitizePagePath('/users/550e8400-e29b-41d4-a716-446655440000;source=private'),
        );
        self::assertSame('/orders/_redacted', $this->sanitizer->sanitizePagePath('/orders/customer-123456'));
        self::assertSame(
            '/sessions/_redacted',
            $this->sanitizer->sanitizePagePath('/sessions/id-01890f3e-7b4a-7cc2-98c4-dc0c0c07398f'),
        );
        self::assertSame(
            '/profiles/_redacted',
            $this->sanitizer->sanitizePagePath('/profiles/alice%2540example.com'),
        );
        self::assertSame(
            '/profiles/_redacted',
            $this->sanitizer->sanitizePagePath('/profiles/alice%252540example.com'),
        );
    }

    public function testPagePathRejectsNonPathInput(): void
    {
        self::assertNull($this->sanitizer->sanitizePagePath('not/a/path'));
        self::assertNull($this->sanitizer->sanitizePagePath(null));
    }

    public function testPagePathCanonicalizesEncodedSeparatorsAndDotSegments(): void
    {
        self::assertSame('/patients/_redacted', $this->sanitizer->sanitizePagePath('/patients%2F123'));
        self::assertSame('/patients/_redacted', $this->sanitizer->sanitizePagePath('/patients%252F123'));
        self::assertSame(
            '/patients/_redacted',
            $this->sanitizer->sanitizePagePath('/public/%252e%252e/patients%252F123'),
        );
        self::assertSame('/patients/_redacted', $this->sanitizer->sanitizePagePath('/patients%5C123'));
        self::assertSame('/_redacted', $this->sanitizer->sanitizePagePath('/patients%25252F123'));
    }

    public function testSafeNamedEventsUseABoundedNonIdentifyingGrammar(): void
    {
        self::assertSame('nav:click', $this->sanitizer->sanitizeEventName(' nav:click '));
        self::assertSame('checkout.completed', $this->sanitizer->sanitizeEventName('checkout.completed'));
        self::assertSame('view', $this->sanitizer->sanitizeEventName(null));
        self::assertNull($this->sanitizer->sanitizeEventName('person@example.com'));
        self::assertNull($this->sanitizer->sanitizeEventName('order_123456'));
        self::assertNull($this->sanitizer->sanitizeEventName(str_repeat('a', 101)));
        self::assertNull($this->sanitizer->sanitizeEventName(['click']));
    }

    public function testReferrerIsReducedToAnAllowlistedChannel(): void
    {
        self::assertSame('search', $this->sanitizer->sanitizeReferrerChannel(null, 'https://www.google.com/search?q=private', 'example.com'));
        self::assertSame('internal', $this->sanitizer->sanitizeReferrerChannel(null, 'https://docs.example.com/account/12', 'example.com'));
        self::assertSame('referral', $this->sanitizer->sanitizeReferrerChannel('untrusted-value', 'https://news.example.net/story', 'example.com'));
        self::assertSame('direct', $this->sanitizer->sanitizeReferrerChannel(null, null, 'example.com'));
    }

    public function testAnonymousDimensionsAreCoarseAllowlistedValues(): void
    {
        self::assertSame('mobile', $this->sanitizer->sanitizeDeviceClass('phone with fingerprint', 'Mozilla/5.0 (iPhone)'));
        self::assertSame('unknown', $this->sanitizer->sanitizeDeviceClass('unknown', 'Mozilla/5.0 (iPhone)'));
        self::assertSame('small', $this->sanitizer->sanitizeViewportBucket(null, 639));
        self::assertSame('medium', $this->sanitizer->sanitizeViewportBucket(null, 640));
        self::assertSame('large', $this->sanitizer->sanitizeViewportBucket(null, 1024));
    }

    public function testDetailedIdentifiersAndPropertiesAreBoundedBeforeQueueing(): void
    {
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $this->sanitizer->sanitizeIdentifier('550e8400-e29b-41d4-a716-446655440000'));
        self::assertNull($this->sanitizer->sanitizeIdentifier('person@example.com'));

        self::assertSame([
            'plan' => 'pro',
            'active' => true,
        ], $this->sanitizer->sanitizeEventData([
            0 => 'ignored without ending sanitization',
            'plan' => "pro\0",
            'active' => true,
            'nested' => ['not' => 'accepted'],
            'unsafe key' => 'ignored',
        ]));
    }

    public function testRawUserAgentBecomesAStableGeneralCategory(): void
    {
        self::assertSame(
            'Edge / desktop',
            $this->sanitizer->generalizeUserAgent('Mozilla/5.0 Windows Chrome/126.0 Safari/537.36 Edg/126.0'),
        );
    }
}
