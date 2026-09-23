<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TagManagerValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TagManagerValuesTest extends TestCase
{
    #[DataProvider('validMethods')]
    public function testApplicationMethodPathsRemainLiteralIdentifiers(string $method): void
    {
        self::assertSame($method, TagManagerValues::validateMethod($method));
    }

    public static function validMethods(): iterable
    {
        yield ['Aggregate.emit'];
        yield ['Acme.analytics.track'];
        yield ['_library.$track2'];
        yield ['a.b.c.d.e.f.g.h'];
        yield ['A.'.str_repeat('x', 126)];
        yield ['mywindow.myconstructor'];
    }

    #[DataProvider('invalidMethods')]
    public function testExecutableExpressionsAndDangerousMethodPathsAreRejected(mixed $method): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TagManagerValues::validateMethod($method);
    }

    public static function invalidMethods(): iterable
    {
        foreach (TagManagerValues::BLOCKED_METHOD_ROOTS as $root) {
            yield 'root '.$root => [$root.'.method'];
        }
        foreach (TagManagerValues::BLOCKED_METHOD_COMPONENTS as $component) {
            yield 'middle '.$component => ['Acme.'.$component.'.method'];
            yield 'last '.$component => ['Acme.'.$component];
        }
        foreach ([null, false, [], 3, '', 'emit', 'Acme.emit()', 'Acme["emit"]', 'Acme?.emit',
            'Acme.emit;alert(1)', 'Acme..emit', '.Acme.emit', 'Acme.emit.', ' Acme.emit',
            "Acme.emit\n", 'a.b.c.d.e.f.g.h.i', 'A.'.str_repeat('x', 127), 'Acme.2emit'] as $index => $method) {
            yield 'syntax '.$index => [$method];
        }
    }

    public function testLiteralsAndExplicitVariableReferencesPreserveTypesWithoutEvaluation(): void
    {
        $arguments = [
            'purchase', ['currency' => 'USD', 'total_minor' => 4999, 'discount' => 0.1,
                'properties' => ['plan-name' => ['$var' => 'plan'], 'is_trial' => false, 'missing' => null],
                'items' => ['books', 'stationery']],
            ['$var' => 'page_path'],
            'Acme.method(); </script>',
        ];
        $variables = ['plan' => ['source' => 'global'], 'page_path' => ['source' => 'location']];
        self::assertSame($arguments, TagManagerValues::validateArguments($arguments, $variables));
        self::assertSame([], TagManagerValues::validateArguments([], []));
    }

    public function testDocumentedValueBoundariesAreAccepted(): void
    {
        self::assertSame(
            [TagManagerValues::MAX_SAFE_INTEGER, -TagManagerValues::MAX_SAFE_INTEGER, 0, -0.5, 1.5e-100],
            TagManagerValues::validateArguments([TagManagerValues::MAX_SAFE_INTEGER, -TagManagerValues::MAX_SAFE_INTEGER, 0, -0.5, 1.5e-100], []),
        );
        self::assertSame([str_repeat('é', 1024)], TagManagerValues::validateArguments([str_repeat('é', 1024)], []));
        self::assertCount(10, TagManagerValues::validateArguments(array_fill(0, 10, null), []));
        self::assertSame([[[[['value']]]]], TagManagerValues::validateArguments([[[[['value']]]]], []));
        // 1 argument list + (3 * (1 list + 32 values)) + (1 list + 27 values) = 128.
        $maximumNodes = [array_fill(0, 32, false), array_fill(0, 32, false), array_fill(0, 32, false), array_fill(0, 27, false)];
        self::assertSame($maximumNodes, TagManagerValues::validateArguments($maximumNodes, []));
        self::assertSame([[str_repeat('k', 64) => true]], TagManagerValues::validateArguments([[str_repeat('k', 64) => true]], []));
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidValuesCannotBecomeRuntimeArguments(mixed $arguments, array $variables = []): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TagManagerValues::validateArguments($arguments, $variables);
    }

    public static function invalidArguments(): iterable
    {
        yield 'not list' => [['event' => 'purchase']];
        yield 'not array' => ['purchase'];
        yield 'null args' => [null];
        yield 'too many args' => [array_fill(0, 11, null)];
        yield 'object' => [[new \stdClass()]];
        yield 'infinity' => [[INF]];
        yield 'nan' => [[NAN]];
        yield 'unsafe integer' => [[9_007_199_254_740_992]];
        yield 'unsafe negative integer' => [[-9_007_199_254_740_992]];
        yield 'unsafe float integer' => [[1.0e30]];
        yield 'long string' => [[str_repeat('x', 2049)]];
        yield 'invalid utf8' => [["\xFF"]];
        yield 'container width' => [[array_fill(0, 33, false)]];
        yield 'container depth' => [[[[[[['value']]]]]]];
        yield 'too many total nodes' => [[array_fill(0, 32, false), array_fill(0, 32, false), array_fill(0, 32, false), array_fill(0, 28, false)]];
        yield 'unknown variable' => [[['$var' => 'unknown']]];
        yield 'extra reference key' => [[['$var' => 'page', 'default' => 'value']], ['page' => []]];
        yield 'array alias' => [[['$var' => ['page']]], ['page' => []]];
        yield 'null alias' => [[['$var' => null]]];
        yield 'empty alias' => [[['$var' => '']], ['' => []]];
        yield 'prototype alias' => [[['$var' => '__proto__']], ['__proto__' => []]];
        yield 'long key' => [[[str_repeat('k', 65) => false]]];
        yield 'empty key' => [[['' => false]]];
        yield 'control key' => [[["line\nkey" => false]]];
        yield 'invalid utf8 key' => [[["\xFF" => false]]];
        foreach (['__proto__', 'prototype', 'constructor'] as $key) {
            yield 'unsafe map '.$key => [[['eventData' => [$key => ['value' => true]]]]];
        }
    }
}
