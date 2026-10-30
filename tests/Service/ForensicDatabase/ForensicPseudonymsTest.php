<?php

declare(strict_types=1);

namespace App\Tests\Service\ForensicDatabase;

use App\Service\ForensicDatabase\ForensicPseudonyms;
use PHPUnit\Framework\TestCase;

/** Identifiers become keyed hashes whose key lives for one copy only. */
final class ForensicPseudonymsTest extends TestCase
{
    private const VISITOR = '6f1c2b8e-4d0a-4c3e-9a51-2f7d8e9b0c1a';

    public function testTheSameIdentifierGetsTheSameHashWithinOneCopy(): void
    {
        $copy = new ForensicPseudonyms();

        $hash = $copy->hash('visitor', self::VISITOR);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $hash);
        self::assertSame($hash, $copy->hash('visitor', self::VISITOR));
        self::assertNotSame($hash, $copy->hash('visitor', '0b7e3a11-0000-4000-8000-000000000000'));
        self::assertStringNotContainsString(self::VISITOR, (string) $hash);
    }

    public function testEveryCopyHashesDifferentlySoHashesCannotBeMatchedAcrossCopies(): void
    {
        self::assertNotSame((new ForensicPseudonyms())->hash('visitor', self::VISITOR), (new ForensicPseudonyms())->hash('visitor', self::VISITOR));
    }

    public function testAHashCannotBeRecomputedFromTheIdentifierAlone(): void
    {
        $hash = (new ForensicPseudonyms())->hash('visitor', self::VISITOR);

        // Without the copy's random key, no plain or salted digest of the identifier matches.
        foreach (['md5', 'sha1', 'sha256', 'sha512'] as $algorithm) {
            self::assertNotSame($hash, hash($algorithm, self::VISITOR));
            self::assertNotSame($hash, hash($algorithm, "visitor\0".self::VISITOR));
            self::assertNotSame($hash, hash_hmac($algorithm, "visitor\0".self::VISITOR, ''));
        }
    }

    public function testVisitorAndSessionIdentifiersHaveSeparateDomains(): void
    {
        $copy = new ForensicPseudonyms();

        self::assertNotSame($copy->hash('visitor', self::VISITOR), $copy->hash('session', self::VISITOR));
    }

    public function testMissingIdentifiersStayMissing(): void
    {
        $copy = new ForensicPseudonyms();

        self::assertNull($copy->hash('visitor', null));
        self::assertNull($copy->hash('visitor', ''));
        self::assertNull($copy->hash('visitor', ['not', 'scalar']));
    }

    public function testTheKeyIsGoneAfterTheCopyAndNeverLeavesTheObject(): void
    {
        $copy = new ForensicPseudonyms();
        // var_dump() and debuggers show this, not the key's bytes.
        self::assertSame(['key' => 'held in memory'], $copy->__debugInfo());

        try {
            serialize($copy);
            self::fail('A copy’s key must not be serializable.');
        } catch (\LogicException) {
        }
        try {
            clone $copy;
            self::fail('A copy’s key must not be cloned.');
        } catch (\LogicException) {
        }

        $copy->forget();
        self::assertSame(['key' => 'forgotten'], $copy->__debugInfo());
        $this->expectException(\LogicException::class);
        $copy->hash('visitor', self::VISITOR);
    }
}
