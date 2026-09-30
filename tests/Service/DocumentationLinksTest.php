<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\DocumentationLinks;
use App\Setup\FirstRunSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final class DocumentationLinksTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private string $projectDir;

    /** @var array{env_exists: bool, env: mixed, server_exists: bool, server: mixed} */
    private array $savedEnvironment;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-documentation-links-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
        $this->savedEnvironment = [
            'env_exists' => array_key_exists('DOCUMENTATION_URL', $_ENV),
            'env' => $_ENV['DOCUMENTATION_URL'] ?? null,
            'server_exists' => array_key_exists('DOCUMENTATION_URL', $_SERVER),
            'server' => $_SERVER['DOCUMENTATION_URL'] ?? null,
        ];
        unset($_ENV['DOCUMENTATION_URL'], $_SERVER['DOCUMENTATION_URL']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['DOCUMENTATION_URL'], $_SERVER['DOCUMENTATION_URL']);
        if ($this->savedEnvironment['env_exists']) {
            $_ENV['DOCUMENTATION_URL'] = $this->savedEnvironment['env'];
        }
        if ($this->savedEnvironment['server_exists']) {
            $_SERVER['DOCUMENTATION_URL'] = $this->savedEnvironment['server'];
        }
        foreach (glob($this->projectDir.'/config/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testDefaultsToThePublicDocumentationSite(): void
    {
        $links = $this->links();

        self::assertTrue($links->isEnabled());
        self::assertSame(DocumentationLinks::DEFAULT_URL, $links->baseUrl());
        self::assertSame('https://subschema-llc.github.io/aggregate/', $links->url('home'));
        self::assertSame('https://subschema-llc.github.io/aggregate/operate/updates#switch-methods', $links->url('updates.switch'));
        self::assertSame('https://subschema-llc.github.io/aggregate/operate/updates#switch-methods', $links->reference('updates.switch'));
        self::assertFalse($links->hasEnvironmentOverride());
    }

    public function testTheSetupPageUsesTheDefaultDocumentationSite(): void
    {
        self::assertSame(DocumentationLinks::DEFAULT_URL, FirstRunSetup::DOCUMENTATION_URL);
    }

    public function testUsesTheConfiguredSiteAndAddsATrailingSlash(): void
    {
        $links = $this->links(['documentation_url' => ' https://docs.example.test/analytics ']);

        self::assertSame('https://docs.example.test/analytics/', $links->baseUrl());
        self::assertSame('https://docs.example.test/analytics/tracking/setup', $links->url('tracking.setup'));
        self::assertSame('https://docs.example.test/analytics/', $links->configuredValue());
    }

    public function testAnEmptyValueTurnsLinksOffAndReferencesNameTheShippedFile(): void
    {
        $links = $this->links(['documentation_url' => '']);

        self::assertFalse($links->isEnabled());
        self::assertNull($links->url('updates'));
        self::assertSame('docs/UPDATES.md', $links->reference('updates.git-clone'));
        self::assertSame('DEPLOYMENT.md', $links->reference('install.manual-update'));
        self::assertSame('', $links->configuredValue());
    }

    public function testAnInvalidConfiguredValueTurnsLinksOff(): void
    {
        foreach (['javascript:alert(1)', '/relative/docs', 'https://user:secret@docs.example.test/', ['https://docs.example.test/'], 42] as $value) {
            $links = $this->links(['documentation_url' => $value]);
            self::assertNull($links->url('home'), var_export($value, true));
            self::assertSame('README.md', $links->reference('home'));
        }
    }

    public function testTheEnvironmentTakesPrecedenceIncludingAnEmptyValue(): void
    {
        $_ENV['DOCUMENTATION_URL'] = 'https://env-docs.example.test/';
        $links = $this->links(['documentation_url' => 'https://yaml-docs.example.test/']);
        self::assertTrue($links->hasEnvironmentOverride());
        self::assertSame('https://env-docs.example.test/configure/data-model', $links->url('data-model'));

        $_ENV['DOCUMENTATION_URL'] = '';
        $links = $this->links(['documentation_url' => 'https://yaml-docs.example.test/']);
        self::assertTrue($links->hasEnvironmentOverride());
        self::assertNull($links->url('data-model'));
    }

    public function testUnknownTopicsAreProgrammingErrors(): void
    {
        self::assertFalse(DocumentationLinks::hasTopic('no.such.topic'));
        self::assertFalse(DocumentationLinks::hasTopic(['home']));
        $this->expectException(\InvalidArgumentException::class);
        $this->links()->url('no.such.topic');
    }

    public function testNormalizeAcceptsWebAddressesAndEmpty(): void
    {
        self::assertSame('', DocumentationLinks::normalize('   '));
        self::assertSame('https://docs.example.test/', DocumentationLinks::normalize('https://docs.example.test'));
        self::assertSame('http://intranet.example.test/help/aggregate/', DocumentationLinks::normalize('http://intranet.example.test/help/aggregate'));
        self::assertSame('https://docs.example.test:8443/a/', DocumentationLinks::normalize('https://docs.example.test:8443/a/'));
    }

    #[DataProvider('invalidAddresses')]
    public function testNormalizeRejectsUnsafeOrUnusableAddresses(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentationLinks::normalize($value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidAddresses(): iterable
    {
        yield 'not text' => [['https://docs.example.test/']];
        yield 'null' => [null];
        yield 'script' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html,hello'];
        yield 'ftp' => ['ftp://docs.example.test/'];
        yield 'relative' => ['docs/'];
        yield 'protocol-relative' => ['//docs.example.test/'];
        yield 'no host' => ['https:///docs'];
        yield 'credentials' => ['https://user:secret@docs.example.test/'];
        yield 'query' => ['https://docs.example.test/?a=1'];
        yield 'fragment' => ['https://docs.example.test/#top'];
        yield 'space' => ['https://docs.example.test/my docs/'];
        yield 'newline' => ["https://docs.example.test/\nx"];
        yield 'backslash' => ['https://docs.example.test\\evil/'];
        yield 'too long' => ['https://docs.example.test/'.str_repeat('a', DocumentationLinks::MAX_LENGTH)];
    }

    /**
     * Every topic must name a published page and one of its headings, and fall back
     * to that page's source file, so a renamed heading or moved page fails here.
     */
    public function testEveryTopicPointsToAPublishedPageAndHeading(): void
    {
        $routes = self::siteRoutes();
        foreach (DocumentationLinks::TOPICS as $topic => [$route, $file]) {
            [$path, $anchor] = array_pad(explode('#', $route, 2), 2, null);
            self::assertFileExists(self::ROOT.'/'.$file, $topic);
            if ($path === '') {
                self::assertNull($anchor, $topic);
                continue;
            }
            self::assertArrayHasKey($path, $routes, sprintf('Topic "%s" names a route that website/pages.mjs does not publish.', $topic));
            self::assertSame($routes[$path], $file, sprintf('Topic "%s" falls back to a different file than its page.', $topic));
            if ($anchor !== null) {
                self::assertContains($anchor, self::headingAnchors($file), sprintf('Topic "%s": %s has no heading "#%s".', $topic, $file, $anchor));
            }
        }
    }

    /** Templates, navigation and messages may only use declared topics. */
    public function testEveryTopicUsedByTheApplicationIsDeclared(): void
    {
        $used = [];
        foreach ((new Finder())->files()->in(self::ROOT.'/templates')->name('*.twig') as $template) {
            $contents = $template->getContents();
            preg_match_all("/docs_url\\('([^']+)'\\)/", $contents, $calls);
            preg_match_all("/'Ui:DocsLink',\\s*\\{\\s*topic:\\s*'([^']+)'/", $contents, $components);
            foreach ([...$calls[1], ...$components[1]] as $topic) {
                $used[$topic][] = $template->getRelativePathname();
            }
        }
        foreach ((new Finder())->files()->in(self::ROOT.'/src')->name('*.php')->contains('DocumentationLinks') as $source) {
            preg_match_all("/(?:->reference|->url|DocumentationLinks::file|->guide|documentationReference)\\('([^']+)'\\)/", $source->getContents(), $calls);
            foreach ($calls[1] as $topic) {
                $used[$topic][] = $source->getRelativePathname();
            }
        }
        $navigation = Yaml::parseFile(self::ROOT.'/config/navigation.yaml')['parameters']['app.main_navigation']['items'];
        array_walk_recursive($navigation, static function (mixed $value, string|int $key) use (&$used): void {
            if ($key === 'docs') {
                $used[$value][] = 'config/navigation.yaml';
            }
        });

        self::assertNotEmpty($used);
        foreach ($used as $topic => $places) {
            self::assertTrue(DocumentationLinks::hasTopic($topic), sprintf('Unknown documentation topic "%s" in %s.', $topic, implode(', ', array_unique($places))));
        }
        // The dashboard pages link to their guides.
        foreach (['tracking.setup', 'reporting.connect', 'updates', 'configuration.application', 'install.worker'] as $topic) {
            self::assertArrayHasKey($topic, $used);
        }
    }

    /** The updates page appends section anchors to the guide; they must exist on its page. */
    public function testUpdatesPageSectionsExist(): void
    {
        preg_match_all('/\{\{ update_guide_url \}\}#([a-z0-9-]+)/', (string) file_get_contents(self::ROOT.'/templates/updates/index.html.twig'), $matches);
        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $anchor) {
            self::assertContains($anchor, self::headingAnchors('docs/UPDATES.md'), $anchor);
        }
    }

    private function links(array $config = []): DocumentationLinks
    {
        if ($config !== []) {
            file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($config));
        }

        return new DocumentationLinks(new AggregateConfigLoader($this->projectDir, 'test'));
    }

    /** @return array<string, string> route => source file, from website/pages.mjs */
    private static function siteRoutes(): array
    {
        preg_match_all("/source:\\s*'([^']+)',\\s*route:\\s*'([^']+)'/", (string) file_get_contents(self::ROOT.'/website/pages.mjs'), $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches);
        $routes = [];
        foreach ($matches as [, $source, $route]) {
            $routes[$route] = $source;
        }

        return $routes;
    }

    /**
     * GitHub-compatible heading anchors, as website/slug.mjs and the site build compute them.
     *
     * @return list<string>
     */
    private static function headingAnchors(string $file): array
    {
        $anchors = [];
        $counts = [];
        $fence = null;
        foreach (explode("\n", (string) file_get_contents(self::ROOT.'/'.$file)) as $line) {
            if (preg_match('/^\s*(```+|~~~+)/', $line, $marker) === 1) {
                $fence = $fence === null ? $marker[1][0] : ($marker[1][0] === $fence ? null : $fence);
                continue;
            }
            if ($fence !== null || preg_match('/^#{1,6}\s+(.+?)\s*#*\s*$/', $line, $heading) !== 1) {
                continue;
            }
            $text = preg_replace_callback('/(`+[^`]*`+)|([^`]+)/', static fn (array $part): string => $part[1] !== ''
                ? trim($part[1], '`')
                : (string) preg_replace(['/!?\[([^\]]*)\]\([^)]*\)/', '/(\*\*|\*)(.+?)\1/', '/<[^>]+>/'], ['$1', '$2', ''], $part[2]), $heading[1]);
            $slug = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/u', '', mb_strtolower(trim((string) $text))));
            $seen = $counts[$slug] ?? 0;
            $counts[$slug] = $seen + 1;
            $anchors[] = $seen === 0 ? $slug : $slug.'-'.$seen;
        }

        return $anchors;
    }
}
