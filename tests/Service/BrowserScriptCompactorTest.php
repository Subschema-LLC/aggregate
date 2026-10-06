<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserScriptCompactor;
use Peast\Peast;
use Peast\Syntax\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BrowserScriptCompactorTest extends TestCase
{
    /** Every script the server can compact must keep its exact program. */
    #[DataProvider('browserScripts')]
    public function testEachBrowserScriptCompactsToTheSameProgram(string $path): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $compact = (new BrowserScriptCompactor())->compact($source);

        self::assertNotNull($compact);
        self::assertSame(self::program($source), self::program($compact), $path);
        self::assertLessThan(0.85 * strlen($source), strlen($compact), $path);
        self::assertStringStartsWith(self::licenseOf($source), $compact, 'the license notice is kept');
    }

    public static function browserScripts(): iterable
    {
        foreach (['public/aggregate.js', 'public/tag-manager.js', 'public/consent.js', 'micro-consent-dropins/js/consent-ui.js', 'micro-consent-dropins/js/aggregate-consent.js'] as $path) {
            yield $path => [$path];
        }
    }

    /** The custom-code corpus exercises syntax the scripts above do not use. */
    public function testTrickySyntaxKeepsItsMeaning(): void
    {
        $corpus = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/custom-script-corpus.json'), true, flags: JSON_THROW_ON_ERROR);
        $extra = [
            "if (a) if (b) c(); else d();",
            "if (a) { if (b) c(); } else d();",
            "var x = a\n/b/g.exec(c)",
            "var y = a / b / c, z = /=/.test(s);",
            "for (var i = ('x' in o) ? 1 : 0; i < 2; i++) {}",
            "label: for (;;) { break label; }",
            'var t = `a${b}c\u0041`, s = \'\u2028\', n = 0x1F + 1e3 + .5;',
            "x = a ++ + + b; y = a - -b; z = a + +b;",
            "return\nvalue;",
        ];
        $compactor = new BrowserScriptCompactor();
        $checked = 0;
        foreach (array_merge(array_column(array_filter($corpus, static fn (array $entry): bool => $entry['syntax']), 'code'), $extra) as $code) {
            $source = "(function (tag) {\n".$code."\n});";
            $compact = $compactor->compact($source);
            self::assertNotNull($compact, $code);
            self::assertSame(self::program($source), self::program($compact), $code);
            $checked++;
        }
        self::assertGreaterThan(40, $checked);
    }

    public function testTemplatesKeepEachPlaceholderOnceAndOtherwiseAreRefused(): void
    {
        $compactor = new BrowserScriptCompactor();
        $source = "/*! License */\n(function () {\n  // settings\n  var settings = {enabled: false};\n  window.ready = settings.enabled;\n})();\n";
        $template = $compactor->template($source, ['var settings = {enabled: false};' => 'var settings = __SETTINGS__;'], ['__SETTINGS__']);
        self::assertSame("/*! License */\n(function(){var settings=__SETTINGS__;window.ready=settings.enabled;})();\n", $template);

        self::assertNull($compactor->template($source, ['var missing = 1;' => 'var missing = __X__;'], ['__X__']), 'a missing declaration');
        self::assertNull($compactor->template($source."var settings = {enabled: false};\n", ['var settings = {enabled: false};' => 'var settings = __SETTINGS__;'], ['__SETTINGS__']), 'a repeated declaration');
        self::assertNull($compactor->template($source, ['var settings = {enabled: false};' => 'var settings = __SETTINGS__ || __SETTINGS__;'], ['__SETTINGS__']), 'a repeated placeholder');
        self::assertNull($compactor->compact('function ('), 'code that cannot be parsed is served as it is');
    }

    public function testBuildSwitchesKeepOnlyTheBranchThatRuns(): void
    {
        $source = <<<'JS'
            /*! License */
            (function () {
              var withA = true;
              var withB = true;
              var api = {
                one: function () {
                  if (withA) {
                    a();
                    a2();
                  }
                  return 1;
                },
                two: function () {
                  if (!withA) {
                    fallback();
                  } else if (withB) {
                    b();
                  } else {
                    neither();
                  }
                }
              };
              if (withB) {
                if (ready) start();
              }
            })();
            JS;
        $compactor = new BrowserScriptCompactor();

        self::assertSame("/*! License */\n(function(){var api={one:function(){{a();a2();}return 1;},two:function(){b();}};if(ready){start();}})();\n", $compactor->compact($source, ['withA' => true, 'withB' => true]));
        self::assertSame("/*! License */\n(function(){var api={one:function(){return 1;},two:function(){fallback();}};})();\n", $compactor->compact($source, ['withA' => false, 'withB' => false]));
        self::assertSame("/*! License */\n(function(){var api={one:function(){{a();a2();}return 1;},two:function(){neither();}};})();\n", $compactor->compact($source, ['withA' => true, 'withB' => false]));
        self::assertNotNull($compactor->compact($source), 'without switches the declarations stay');
    }

    /** Anything but the documented form could leave an undefined name behind. */
    public function testBuildSwitchesUsedAnyOtherWayAreRefused(): void
    {
        $compactor = new BrowserScriptCompactor();
        foreach ([
            'not declared' => 'if (withA) { a(); }',
            'declared twice' => 'var withA = true; var withA = true; if (withA) { a(); }',
            'declared false' => 'var withA = false; if (withA) { a(); }',
            'declared with let' => 'let withA = true; if (withA) { a(); }',
            'declared with others' => 'var withA = true, other = 1; if (withA) { a(); }',
            'used in an expression' => 'var withA = true; var on = withA && ready;',
            'compared' => 'var withA = true; if (withA === true) { a(); }',
            'reassigned' => 'var withA = true; withA = false;',
        ] as $case => $code) {
            self::assertNull($compactor->compact($code, ['withA' => true]), $case);
        }
    }

    /** Every tracker build compacts, keeps its license, and never refers to a switch. */
    public function testEachTrackerBuildCompacts(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/public/aggregate.js');
        $sizes = [];
        foreach (\App\Service\TrackerBuilds::SWITCHES as $build => $switches) {
            $compact = (new BrowserScriptCompactor())->compact($source, $switches);
            self::assertNotNull($compact, $build);
            self::assertStringStartsWith(self::licenseOf($source), $compact, $build);
            foreach (array_keys($switches) as $switch) {
                self::assertStringNotContainsString($switch, $compact, $build);
            }
            Peast::latest($compact, ['sourceType' => Peast::SOURCE_TYPE_SCRIPT])->parse();
            $sizes[$build] = strlen($compact);
        }
        self::assertLessThan($sizes['full'], $sizes['without-page-depth']);
        self::assertLessThan($sizes['without-page-depth'], $sizes['strict']);
    }

    public function testResultsAreRememberedInTheCacheDirectory(): void
    {
        $directory = sys_get_temp_dir().'/aggregate-compact-'.bin2hex(random_bytes(8));
        try {
            $source = 'window.marker = '.random_int(1, PHP_INT_MAX).';';
            self::assertSame("window.marker=".substr($source, 16, -1).";\n", (new BrowserScriptCompactor($directory))->compact($source));
            self::assertCount(1, glob($directory.'/*.js'));
            $file = glob($directory.'/*.js')[0];
            file_put_contents($file, "window.fromCache=true;\n");
            $other = 'window.other = '.random_int(1, PHP_INT_MAX).';';
            (new BrowserScriptCompactor($directory))->compact($other);
            self::assertCount(2, glob($directory.'/*.js'));
            self::assertNotNull((new BrowserScriptCompactor('/proc/aggregate-not-writable'))->compact('window.unwritable = 1;'), 'an unwritable cache only means compacting again');
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    /**
     * The parsed program without positions or source spelling. A one-statement
     * block and the statement alone mean the same as a branch or loop body,
     * and {a: a} means {a}; the compactor adds the braces and may shorten.
     */
    private static function program(string $source): string
    {
        $plain = static function (mixed $value) use (&$plain): mixed {
            if ($value instanceof Node\Node) {
                $value = $value->jsonSerialize();
            }
            if (!is_array($value)) {
                return is_object($value) ? null : $value;
            }
            $out = [];
            foreach ($value as $key => $member) {
                if (in_array($key, ['location', 'raw', 'shorthand'], true)) {
                    continue;
                }
                if (in_array($key, ['consequent', 'alternate', 'body'], true) && $member instanceof Node\BlockStatement && count($member->getBody()) === 1) {
                    $member = $member->getBody()[0];
                }
                $out[$key] = $plain($member);
            }

            return $out;
        };

        return json_encode($plain(Peast::latest($source, ['sourceType' => Peast::SOURCE_TYPE_SCRIPT])->parse()), JSON_THROW_ON_ERROR);
    }

    private static function licenseOf(string $source): string
    {
        preg_match('~\A\s*(/\*!.*?\*/)~s', $source, $match);

        return $match[1] ?? '';
    }
}
