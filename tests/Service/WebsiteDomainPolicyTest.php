<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WebsiteDomainPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteDomainPolicyTest extends TestCase
{
    public function testNormalizesAndDeduplicatesDomainRules(): void
    {
        self::assertSame([
            'mode' => 'restricted',
            'domains' => ['example.com', '*.shop.example.com', 'localhost', '127.0.0.1', '2001:db8::1', 'xn--bcher-kva.example'],
        ], (new WebsiteDomainPolicy())->normalize([
            'mode' => 'restricted',
            'domains' => [' EXAMPLE.COM. ', 'example.com', '*.SHOP.example.com.', 'localhost', '127.0.0.1', '[2001:0DB8::1]', 'xn--bcher-kva.example'],
        ]));
        self::assertSame(['mode' => 'all', 'domains' => []], (new WebsiteDomainPolicy())->normalize(['mode' => 'all']));
    }

    #[DataProvider('matchingCases')]
    public function testMatchesOnlyTheConfiguredHosts(array $domains, ?string $origin, ?string $referer, bool $expected): void
    {
        self::assertSame($expected, (new WebsiteDomainPolicy())->allows([
            'domain' => 'unused.example',
            'domain_policy' => ['mode' => 'restricted', 'domains' => $domains],
        ], $origin, $referer));
    }

    public static function matchingCases(): iterable
    {
        yield 'exact apex' => [['example.com'], 'https://example.com', null, true];
        yield 'case, DNS trailing dot and port' => [['example.com'], 'HTTP://EXAMPLE.COM.:8080', null, true];
        yield 'exact excludes descendants' => [['example.com'], 'https://shop.example.com', null, false];
        yield 'exact subdomain' => [['shop.example.com'], 'https://shop.example.com', null, true];
        yield 'exact subdomain excludes sibling' => [['shop.example.com'], 'https://docs.example.com', null, false];
        yield 'exact subdomain excludes deeper descendants' => [['shop.example.com'], 'https://a.shop.example.com', null, false];
        yield 'wildcard descendant' => [['*.example.com'], 'https://shop.example.com', null, true];
        yield 'wildcard any descendant depth' => [['*.example.com'], 'https://a.b.shop.example.com', null, true];
        yield 'wildcard excludes apex' => [['*.example.com'], 'https://example.com', null, false];
        yield 'wildcard label boundary' => [['*.example.com'], 'https://notexample.com', null, false];
        yield 'wildcard suffix boundary' => [['*.example.com'], 'https://example.com.attacker.test', null, false];
        yield 'multiple allowed unrelated domains' => [['example.com', 'shop.example.net'], 'https://shop.example.net', null, true];
        yield 'localhost HTTP development port' => [['localhost'], 'http://localhost:9001', null, true];
        yield 'IPv4 exact' => [['127.0.0.1'], 'http://127.0.0.1:8000', null, true];
        yield 'IPv6 normalized' => [['2001:db8::1'], 'https://[2001:0DB8:0:0:0:0:0:1]:443', null, true];
        yield 'punycode exact' => [['xn--bcher-kva.example'], 'https://xn--bcher-kva.example', null, true];
        yield 'valid Referer fallback' => [['example.com'], null, 'https://example.com/a%20page?query=value', true];
        yield 'present empty Origin prevents fallback' => [['example.com'], '', 'https://example.com/page', false];
        yield 'opaque Origin prevents fallback' => [['example.com'], 'null', 'https://example.com/page', false];
        yield 'denied Origin prevents fallback' => [['example.com'], 'https://attacker.test', 'https://example.com/page', false];
        yield 'valid Origin takes precedence' => [['example.com'], 'https://example.com', 'https://attacker.test/page', true];
        yield 'no headers' => [['example.com'], null, null, false];
    }

    public function testMissingPolicyRetainsLegacyDomainAndDescendants(): void
    {
        $policy = new WebsiteDomainPolicy();
        $site = ['domain' => 'Example.COM'];
        self::assertSame(['mode' => 'restricted', 'domains' => ['example.com', '*.example.com']], $policy->resolve($site));
        self::assertTrue($policy->allows($site, 'https://example.com'));
        self::assertTrue($policy->allows($site, 'https://a.b.example.com'));
        self::assertFalse($policy->allows($site, 'https://badexample.com'));
        self::assertFalse($policy->allows($site, null));
        self::assertSame(['mode' => 'restricted', 'domains' => ['::1']], $policy->resolve(['domain' => '[::1]']));
        self::assertFalse($policy->allows([], 'https://example.com'));
    }

    #[DataProvider('invalidPolicies')]
    public function testInvalidPoliciesRejectRequestsWithoutLegacyOrAllowAllFallback(mixed $configuration): void
    {
        $policy = new WebsiteDomainPolicy();
        $site = ['domain' => 'example.com', 'domain_policy' => $configuration];
        self::assertFalse($policy->allows($site, 'https://example.com', 'https://example.com/page'));
        self::assertFalse($policy->allows($site, null));
        $this->expectException(\InvalidArgumentException::class);
        $policy->normalize($configuration);
    }

    public static function invalidPolicies(): iterable
    {
        yield 'null' => [null];
        yield 'boolean' => [true];
        yield 'mode string instead of mapping' => ['all'];
        yield 'empty mapping' => [[]];
        yield 'missing mode' => [['domains' => ['example.com']]];
        yield 'unknown mode' => [['mode' => 'regex', 'domains' => ['example.com']]];
        yield 'wrong mode type' => [['mode' => true, 'domains' => ['example.com']]];
        yield 'unknown field' => [['mode' => 'all', 'domians' => ['example.com']]];
        yield 'restricted empty' => [['mode' => 'restricted', 'domains' => []]];
        yield 'restricted omitted domains' => [['mode' => 'restricted']];
        yield 'null domains even when all' => [['mode' => 'all', 'domains' => null]];
        yield 'string domains even when all' => [['mode' => 'all', 'domains' => 'example.com']];
        yield 'mapping domains' => [['mode' => 'restricted', 'domains' => ['host' => 'example.com']]];
        yield 'too many rules even if duplicates' => [['mode' => 'restricted', 'domains' => array_fill(0, 33, 'example.com')]];
        foreach ([null, true, 42, [], '', '*', '*.127.0.0.1', '*.[::1]', 'https://example.com', 'example.com/path', 'example.com:443', 'foo*.example.com', '*.*.example.com', '/^.*\.example\.com$/', '.example.com', 'example..com', 'example.com..', '-example.com', 'example-.com', 'a_b.example.com', 'bücher.example', '127.1', '1234', str_repeat('a', 64).'.com', str_repeat('a.', 126).'example.com', "\0example.com", "example.com\n"] as $index => $invalidDomain) {
            yield 'invalid rule '.$index => [['mode' => 'restricted', 'domains' => [$invalidDomain]]];
            yield 'invalid all mode rule '.$index => [['mode' => 'all', 'domains' => [$invalidDomain]]];
        }
    }

    #[DataProvider('malformedOrigins')]
    public function testRestrictedRejectsMalformedOriginsEvenWithAnAllowedReferer(string $origin): void
    {
        $policy = new WebsiteDomainPolicy();
        $site = ['domain_policy' => ['mode' => 'restricted', 'domains' => ['example.com']]];
        self::assertFalse($policy->allows($site, $origin, 'https://example.com/page'));
        self::assertTrue($policy->allows(['domain_policy' => ['mode' => 'all']], $origin));
    }

    public static function malformedOrigins(): iterable
    {
        foreach ([
            'example.com', '//example.com', 'ftp://example.com', 'javascript://example.com',
            'https://user@example.com', 'https://user:password@example.com',
            'https://example.com/', 'https://example.com/path', 'https://example.com?query=value', 'https://example.com#fragment',
            'https://example.com https://attacker.test', 'https://example.com,https://attacker.test',
            'https://example.com:', 'https://example.com:65536', 'https://example.com:-1',
            ' https://example.com', "https://example.com\n", "https://example.com\0.attacker.test",
            'https://example.com\\@attacker.test', 'https://%65xample.com', 'https://.example.com',
            'https://a..example.com', 'https://-a.example.com', 'https://[example.com]',
        ] as $origin) {
            yield [$origin];
        }
    }

    public function testAllowAllDoesNotRequireRequestHeadersButStillValidatesEveryRule(): void
    {
        $policy = new WebsiteDomainPolicy();
        self::assertTrue($policy->allows(['domain_policy' => ['mode' => 'all']], null));
        self::assertTrue($policy->allows(['domain_policy' => ['mode' => 'all', 'domains' => ['example.com']]], 'https://unrelated.test'));
        self::assertFalse($policy->allows(['domain_policy' => ['mode' => 'all', 'domains' => ['*']]], null));
    }

    public function testPrimaryDomainSupportsExistingRootUrlInput(): void
    {
        $policy = new WebsiteDomainPolicy();
        self::assertSame('example.com', $policy->normalizePrimaryDomain(' HTTPS://EXAMPLE.COM.:443/ '));
        self::assertSame('example.com', $policy->normalizePrimaryDomain('example.com/'));
        self::assertSame('::1', $policy->normalizePrimaryDomain('http://[::1]:9001/'));
        $this->expectException(\InvalidArgumentException::class);
        $policy->normalizePrimaryDomain('https://example.com/private/path');
    }
}
