<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\TrackerNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackerNamespaceTest extends TestCase
{
    #[DataProvider('names')]
    public function testOnlyAPlainJavaScriptNameIsUsed(mixed $configured, string $used, ?string $ignored): void
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('getWithEnvFallback')->willReturnCallback(static fn (string $key, mixed $default): mixed => $key === 'js_namespace' ? ($configured ?? $default) : $default);

        self::assertSame($used, TrackerNamespace::of($config));
        self::assertSame($ignored, TrackerNamespace::ignored($config));
    }

    public static function names(): iterable
    {
        yield 'unset' => [null, 'Aggregate', null];
        yield 'renamed' => ['Optin', 'Optin', null];
        yield 'underscore and dollar' => ['_my$Stats2', '_my$Stats2', null];
        yield '64 characters' => [str_repeat('a', 64), str_repeat('a', 64), null];
        yield '65 characters' => [str_repeat('a', 65), 'Aggregate', str_repeat('a', 65)];
        yield 'empty' => ['', 'Aggregate', ''];
        yield 'hyphen' => ['my-analytics', 'Aggregate', 'my-analytics'];
        yield 'space' => ['My Analytics', 'Aggregate', 'My Analytics'];
        yield 'leading digit' => ['1stParty', 'Aggregate', '1stParty'];
        yield 'markup' => ["A'</script>", 'Aggregate', "A'</script>"];
        yield 'trailing newline' => ["Optin\n", 'Aggregate', "Optin\n"];
        yield 'not a string' => [42, 'Aggregate', '42'];
    }

    public function testTheFormPatternMatchesTheServerRule(): void
    {
        foreach (['Optin', '_a', '$', 'a-b', '', '1a', 'a b'] as $name) {
            self::assertSame(TrackerNamespace::valid($name), preg_match('/^(?:'.TrackerNamespace::PATTERN.')$/D', $name) === 1, $name);
        }
        self::assertFalse(TrackerNamespace::valid(null));
    }
}
