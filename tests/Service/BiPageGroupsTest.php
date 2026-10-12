<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\BiPageGroups;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class BiPageGroupsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-page-groups-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory.'/config');
        rmdir($this->directory);
    }

    public function testMissingConfigurationMeansNoGroups(): void
    {
        self::assertSame([], $this->groups([])->rules());
    }

    public function testSaveWritesYamlAndPreservesOtherSettings(): void
    {
        $groups = $this->groups(['operator_setting' => 'preserve']);

        $groups->save([
            ['pattern' => ' /inventory/** ', 'group' => ' inventory '],
            ['pattern' => '/locations/*', 'group' => 'location page'],
        ]);

        $saved = Yaml::parseFile($this->directory.'/config/aggregate.yaml');
        self::assertSame('preserve', $saved['operator_setting']);
        self::assertSame([
            ['pattern' => '/inventory/**', 'group' => 'inventory'],
            ['pattern' => '/locations/*', 'group' => 'location page'],
        ], $saved[BiPageGroups::KEY]);
        self::assertSame($saved[BiPageGroups::KEY], (new BiPageGroups(new AggregateConfigLoader($this->directory, 'test')))->rules());
    }

    public function testInvalidYamlFailsClosedWithTheSettingName(): void
    {
        $groups = $this->groups([BiPageGroups::KEY => [['pattern' => '/a/*/b', 'group' => 'x']]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bi_page_groups is invalid');
        $groups->rules();
    }

    #[DataProvider('invalidRules')]
    public function testValidationRejects(mixed $rules, string $message): void
    {
        try {
            BiPageGroups::validate($rules);
            self::fail('Invalid page groups were accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public static function invalidRules(): iterable
    {
        yield 'not a list' => [['pattern' => '/a', 'group' => 'a'], 'must be a list'];
        yield 'scalar' => ['/a', 'must be a list'];
        yield 'missing group' => [[['pattern' => '/a']], 'text pattern and a text group'];
        yield 'extra key' => [[['pattern' => '/a', 'group' => 'a', 'note' => 'x']], 'and nothing else'];
        yield 'relative pattern' => [[['pattern' => 'inventory/**', 'group' => 'a']], 'must start with /'];
        yield 'quote' => [[['pattern' => "/it's", 'group' => 'a']], 'path characters'];
        yield 'backslash' => [[['pattern' => '/a\\b', 'group' => 'a']], 'path characters'];
        yield 'bracket' => [[['pattern' => '/a[1]', 'group' => 'a']], 'path characters'];
        yield 'space' => [[['pattern' => '/a b', 'group' => 'a']], 'path characters'];
        yield 'query' => [[['pattern' => '/a?b', 'group' => 'a']], 'path characters'];
        yield 'wildcard before last segment' => [[['pattern' => '/blog/*/comments', 'group' => 'a']], 'only allowed in the last path segment'];
        yield 'partial double star' => [[['pattern' => '/blog/**.html', 'group' => 'a']], 'whole last segment'];
        yield 'label starting with slash' => [[['pattern' => '/a', 'group' => '/a']], 'cannot start with /'];
        yield 'label with quote' => [[['pattern' => '/a', 'group' => "o'clock"]], 'cannot start with /'];
        yield 'empty label' => [[['pattern' => '/a', 'group' => '']], 'cannot start with /'];
        yield 'long label' => [[['pattern' => '/a', 'group' => str_repeat('a', 65)]], '1 to 64 characters'];
        yield 'duplicate' => [[['pattern' => '/a', 'group' => 'a'], ['pattern' => '/a', 'group' => 'b']], 'repeats the pattern /a'];
        yield 'too many' => [array_map(static fn (int $i): array => ['pattern' => '/p'.$i, 'group' => 'g'], range(1, 101)), 'At most 100'];
    }

    public function testDashboardLinesRoundTrip(): void
    {
        $rules = BiPageGroups::parseLines("# inventory pages\n/inventory/**   inventory\n\n/locations/*\tlocation page\r\n");

        self::assertSame([
            ['pattern' => '/inventory/**', 'group' => 'inventory'],
            ['pattern' => '/locations/*', 'group' => 'location page'],
        ], $rules);
        self::assertSame($rules, BiPageGroups::parseLines(BiPageGroups::toLines($rules)));
    }

    public function testALineWithoutAGroupNamesItsLine(): void
    {
        $this->expectExceptionMessage('Line 2 needs a pattern, a space and a group');
        BiPageGroups::parseLines("/a a\n/inventory/**");
    }

    #[DataProvider('matches')]
    public function testMatchingFollowsTheExcludedPathGlobSemantics(string $pattern, string $path, bool $expected): void
    {
        self::assertSame($expected, BiPageGroups::matches($pattern, $path));
    }

    public static function matches(): iterable
    {
        yield 'double star includes the directory' => ['/inventory/**', '/inventory', true];
        yield 'double star includes children' => ['/inventory/**', '/inventory/2024-camry', true];
        yield 'double star includes depth' => ['/inventory/**', '/inventory/a/b/c', true];
        yield 'double star needs the separator' => ['/inventory/**', '/inventoryx', false];
        yield 'single star one segment' => ['/locations/*', '/locations/dallas', true];
        yield 'single star not deeper' => ['/locations/*', '/locations/dallas/hours', false];
        yield 'single star not the directory' => ['/locations/*', '/locations', false];
        yield 'star inside the segment' => ['/files/*.pdf', '/files/report.pdf', true];
        yield 'star inside the segment, other extension' => ['/files/*.pdf', '/files/report.txt', false];
        yield 'literal underscore is literal' => ['/a_b', '/aXb', false];
        yield 'literal percent is literal' => ['/100%/off', '/100x/off', false];
        yield 'exact literal' => ['/about', '/about', true];
        yield 'home page' => ['/', '/', true];
        yield 'case-sensitive in PHP' => ['/About', '/about', false];
    }

    public function testFirstMatchingRuleWinsAndUnmatchedPathsKeepTheirPath(): void
    {
        $rules = [
            ['pattern' => '/inventory/featured', 'group' => 'featured'],
            ['pattern' => '/inventory/**', 'group' => 'inventory'],
        ];

        self::assertSame('featured', BiPageGroups::groupFor('/inventory/featured', $rules));
        self::assertSame('inventory', BiPageGroups::groupFor('/inventory/featured/x', $rules));
        self::assertSame('/contact', BiPageGroups::groupFor('/contact', $rules));
    }

    private function groups(array $config): BiPageGroups
    {
        file_put_contents($this->directory.'/config/aggregate.yaml', $config === [] ? "# empty\n" : Yaml::dump($config, 4, 2));

        return new BiPageGroups(new AggregateConfigLoader($this->directory, 'test'));
    }
}
