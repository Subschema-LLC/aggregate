<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TagManagerVariables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TagManagerVariablesTest extends TestCase
{
    public function testSafeDataAndEventPathsRemainUnchanged(): void
    {
        $variables = [
            'sku' => 'ecommerce.items[0].sku',
            'nested' => 'matrix[10][10000].value',
            'eventName' => 'event.type',
            'eventValue' => 'event.detail.cart.total_minor',
            'privateKey' => '_context.$value',
            'root' => 'purchase',
        ];
        self::assertSame($variables, TagManagerVariables::validate($variables));
        self::assertSame([], TagManagerVariables::validate([]));
    }

    public function testDocumentedSizeBoundsAreInclusive(): void
    {
        $variables = [];
        for ($index = 0; $index < TagManagerVariables::MAX_VARIABLES; ++$index) {
            $variables['var'.$index] = 'value';
        }
        $variables['var0'] = str_repeat('a', TagManagerVariables::MAX_PATH_LENGTH);
        $variables['var1'] = implode('.', array_fill(0, TagManagerVariables::MAX_SEGMENTS, 'a'));
        $variables[str_repeat('a', 64)] = $variables['var2'];
        unset($variables['var2']);
        self::assertSame($variables, TagManagerVariables::validate($variables));
    }

    #[DataProvider('invalidVariables')]
    public function testMalformedMappingsAndUnsafePathsAreRejected(mixed $variables): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TagManagerVariables::validate($variables);
    }

    public static function invalidVariables(): iterable
    {
        foreach ([null, true, 'dataLayer', ['event.detail']] as $value) {
            yield 'invalid mapping '.json_encode($value) => [$value];
        }
        $tooMany = [];
        for ($index = 0; $index <= TagManagerVariables::MAX_VARIABLES; ++$index) {
            $tooMany['var'.$index] = 'value';
        }
        yield 'too many aliases' => [$tooMany];
        foreach (['', '0name', '_name', 'name-with-hyphens', 'name.path', 'name space', 'é', str_repeat('a', 65), '__proto__', 'prototype', 'constructor'] as $alias) {
            yield 'invalid alias '.$alias => [[$alias => 'value']];
        }
        foreach ([
            null, false, 0, [], '', ' ', 'event.', '.event', 'event..detail',
            'event.detail()', 'event?.detail', 'event["detail"]', "event['detail']",
            'items[-1]', 'items[1.5]', 'items[01]', 'items[1e2]', 'items[10001]',
            'items[100000000000000000000]', 'items[0]value', 'items.[0]', '[0].value',
            'items.0.value', 'value + other', "event\ndetail", 'event;alert(1)',
            'constructor', 'prototype', '__proto__', 'event.constructor.name',
            'event.detail.prototype.key', 'items[0].__proto__.key',
            str_repeat('a', 257), implode('.', array_fill(0, 17, 'key')),
        ] as $index => $path) {
            yield 'invalid path '.$index => [['value' => $path]];
        }
    }

    #[DataProvider('htmlEncodedSources')]
    public function testHtmlEncodedAmpersandsFromCopiedSnippetsBecomeQuerySeparators(string $pasted, string $expected): void
    {
        self::assertSame($expected, TagManagerVariables::normalizeSource($pasted));
        self::assertSame($expected, TagManagerVariables::validateSource($pasted, ['sku' => 'ecommerce.items[0].sku']));
    }

    public static function htmlEncodedSources(): iterable
    {
        $tracker = 'https://analytics.example/aggregate.js?min=1&endpoint=https%3A%2F%2Fanalytics.example%2Fapi%2Freceive&token=public-token&consent=0';
        yield 'tracker URL from an HTML snippet' => [str_replace('&', '&amp;', $tracker), $tracker];
        yield 'uppercase entity' => ['https://scripts.example/a.js?x=1&AMP;y=2', 'https://scripts.example/a.js?x=1&y=2'];
        yield 'numeric entities' => ['https://scripts.example/a.js?x=1&#38;y=2&#x26;z=3&#038;w=4', 'https://scripts.example/a.js?x=1&y=2&z=3&w=4'];
        yield 'double-encoded' => ['https://scripts.example/a.js?x=1&amp;amp;y=2', 'https://scripts.example/a.js?x=1&y=2'];
        yield 'template variable kept' => ['https://scripts.example/{{sku}}.js?a=1&amp;b=2', 'https://scripts.example/{{sku}}.js?a=1&b=2'];
        yield 'plain separators unchanged' => [$tracker, $tracker];
        yield 'other entities unchanged' => ['https://scripts.example/a.js?q=&lt;', 'https://scripts.example/a.js?q=&lt;'];
    }

    #[DataProvider('validSources')]
    public function testStaticAuthorityWithDeclaredPathAndQueryVariablesIsAllowed(string $src): void
    {
        self::assertSame($src, TagManagerVariables::validateSource($src, ['sku' => 'ecommerce.items[0].sku', 'eventName' => 'event.type']));
    }

    public static function validSources(): iterable
    {
        foreach ([
            'https://scripts.example/script.js',
            'HTTPS://scripts.example:8443/{{sku}}.js?event={{eventName}}&again={{sku}}',
            'https://scripts.example?{{eventName}}={{sku}}',
            'https://[2001:db8::1]/script.js?sku={{sku}}',
            'https://scripts.example/script.js?literal=%7Bencoded%7D',
            'https://scripts.example/script.js?label=</script>&quoted="value"',
            'https://scripts.example/'.str_repeat('a', 2048 - strlen('https://scripts.example/')),
        ] as $index => $src) {
            yield 'valid source '.$index => [$src];
        }
    }

    #[DataProvider('invalidSources')]
    public function testMalformedUrlsAndDynamicAuthoritiesAreRejected(mixed $src): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TagManagerVariables::validateSource($src, ['sku' => 'ecommerce.items[0].sku']);
    }

    public static function invalidSources(): iterable
    {
        foreach ([
            null, false, [], '', '/script.js', '//scripts.example/script.js',
            'http://scripts.example/script.js', 'javascript:alert(1)', 'data:text/javascript,alert(1)',
            '{{sku}}://scripts.example/a.js', 'https://{{sku}}/a.js',
            'https://scripts.{{sku}}/a.js', 'https://scripts.example:{{sku}}/a.js',
            'https://user:password@scripts.example/a.js', 'https://@scripts.example/a.js',
            'https://{{sku}}@scripts.example/a.js', 'https://scripts.example/a.js#',
            'https://scripts.example/a.js#{{sku}}', 'https://scripts.example/a.js?x={{unknown}}',
            'https://scripts.example/a.js?x={{ sku }}', 'https://scripts.example/{sku}',
            'https://scripts.example/{{sku}', 'https://scripts.example/{{sku}}}',
            'https://scripts.example/{{{sku}}}', 'https://scripts.example/{{sku.path}}',
            'https://scripts.example/{{constructor}}', 'https://scripts.example/a b.js',
            'https://scripts.example/a\\b.js', "https://scripts.example/a.js\n",
            "https://scripts.example/\0.js", 'https://scripts.example/'.str_repeat('a', 2048),
        ] as $index => $src) {
            yield 'invalid source '.$index => [$src];
        }
    }

    public function testSourceValidationDoesNotTrustAnUnvalidatedVariableMapping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TagManagerVariables::validateSource('https://scripts.example/{{sku}}.js', ['sku' => 'event.constructor']);
    }
}
