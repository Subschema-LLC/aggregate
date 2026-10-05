<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TrackingAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackingAttributesTest extends TestCase
{
    /** tests/JavaScript/aggregate-attributes.test.js checks the tracker derives the same names. */
    #[DataProvider('namespaces')]
    public function testAttributeNamesFollowTheNamespaceAsTheTrackerDerivesThem(mixed $namespace, string $prefix): void
    {
        self::assertSame($prefix, TrackingAttributes::prefix($namespace));
        self::assertSame(['event' => $prefix.'event', 'goal' => $prefix.'goal', 'prop' => $prefix.'prop-'], TrackingAttributes::names($namespace));
    }

    public static function namespaces(): iterable
    {
        yield 'default' => ['Aggregate', 'data-aggregate-'];
        yield 'white label' => ['AcmeStats', 'data-acmestats-'];
        yield 'symbols' => ['$_Shop$', 'data-_shop-'];
        yield 'non-ASCII letters' => ["Stat\u{130}stik", 'data-statstik-'];
        yield 'nothing usable' => ['$$', 'data-aggregate-'];
        yield 'not text' => [null, 'data-aggregate-'];
    }
}
