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

    public function testACustomDataWrapperInTheTrackersEmitIsExplainedWithTheFix(): void
    {
        $args = ['login', ['customData' => ['app' => 'optin_model_facilitation_app', 'method' => ['$var' => 'login_method']]]];

        self::assertSame(
            'Remove the "customData" wrapper and put its properties directly in the second argument. The tracker puts the properties in customData itself and drops a property that holds an object, so this event would be sent without them.',
            TagManagerValues::emitProblem('Optin.emit', $args, ['Optin', 'Aggregate']),
        );
        self::assertNull(TagManagerValues::emitProblem('Optin.emit', $args, ['Aggregate']), 'Optin is another library unless it is the namespace');
    }

    #[DataProvider('emitCalls')]
    public function testOnlyCallsToTheTrackersEmitThatWouldLoseProperties(string $method, array $args, ?string $expected): void
    {
        $problem = TagManagerValues::emitProblem($method, $args, ['Optin', 'Aggregate']);

        if ($expected === null) {
            self::assertNull($problem);
        } else {
            self::assertStringStartsWith($expected, (string) $problem);
        }
    }

    public static function emitCalls(): iterable
    {
        $variable = ['$var' => 'login_method'];
        yield 'flat properties' => ['Optin.emit', ['login', ['app' => 'a', 'method' => $variable, 'count' => 2, 'ok' => true, 'none' => null]], null];
        yield 'no properties' => ['Aggregate.emit', ['login'], null];
        yield 'null properties with a goal' => ['Aggregate.emit', ['signup_click', null, 'signup'], null];
        yield 'empty properties' => ['Aggregate.emit', ['login', []], null];
        yield 'name from a variable' => ['Aggregate.emit', [$variable, ['plan' => 'pro']], null];
        yield 'another library' => ['Acme.emit', ['login', ['customData' => ['app' => 'a']]], null];
        yield 'another tracker method' => ['Optin.configure', [['customData' => ['propertyTypes' => []]]], null];
        yield 'eventData wrapper' => ['Aggregate.emit', ['login', ['eventData' => []]], 'Remove the "eventData" wrapper'];
        yield 'wrapper beside a property' => ['Aggregate.emit', ['login', ['customData' => ['app' => 'a'], 'plan' => 'pro']], 'The tracker drops "customData": a property must be'];
        yield 'wrapper holding a list' => ['Aggregate.emit', ['login', ['customData' => ['a', 'b']]], 'The tracker drops "customData": '];
        yield 'nested properties' => ['Aggregate.emit', ['purchase', ['items' => [['sku' => 'x']], 'plan' => 'pro', 'meta' => ['a' => 1], 'tags' => ['x']]], 'The tracker drops "items", "meta" and "tags": '];
        yield 'properties as a list' => ['Aggregate.emit', ['purchase', ['pro']], 'The second argument holds the event properties'];
        yield 'goal in the properties place' => ['Aggregate.emit', ['signup_click', 'signup'], 'The second argument holds the event properties'];
        yield 'properties from one variable' => ['Aggregate.emit', ['login', $variable], 'A variable gives one value'];
        yield 'properties first' => ['Aggregate.emit', [['method' => 'password']], 'The first argument is the event name'];
        yield 'no arguments' => ['Aggregate.emit', [], 'The first argument is the event name'];
    }

    public function testAnAggregateEventKeepsItsFieldsAndFlatCustomData(): void
    {
        $variables = ['login_method' => 'method'];
        $properties = ['app' => 'facilitation', 'method' => ['$var' => 'login_method'], 'attempts' => 2, 'rate' => 0.5, 'admin' => true, 'none' => null, 'context.source' => 'nav'];
        self::assertSame(
            ['eventName' => 'login', 'customData' => $properties, 'goalEvent' => 'signup', 'pagePath' => '/account/login'],
            TagManagerValues::validateEvent(['eventName' => 'login', 'customData' => $properties, 'goalEvent' => 'signup', 'pagePath' => '/account/login'], $variables),
        );
        self::assertSame(['eventName' => 'login'], TagManagerValues::validateEvent(['eventName' => 'login', 'customData' => []], []), 'empty customData is left out');
        self::assertSame(['eventName' => ['$var' => 'login_method'], 'goalEvent' => null, 'pagePath' => ['$var' => 'login_method']],
            TagManagerValues::validateEvent(['eventName' => ['$var' => 'login_method'], 'goalEvent' => null, 'pagePath' => ['$var' => 'login_method']], $variables));
    }

    #[DataProvider('invalidEvents')]
    public function testAnAggregateEventIsRefusedWithTheReason(mixed $event, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        TagManagerValues::validateEvent($event, ['login_method' => 'method'], 'Tag login: the event');
    }

    public static function invalidEvents(): iterable
    {
        yield 'a list' => [['login'], 'Tag login: the event must be an object such as {"eventName": "login", "customData": {"method": "password"}}.'];
        yield 'text' => ['login', 'Tag login: the event must be an object'];
        yield 'missing' => [null, 'Tag login: the event must be an object'];
        yield 'no name' => [['customData' => []], 'Tag login: the event needs an eventName, such as "login".'];
        yield 'a property beside the fields' => [['eventName' => 'login', 'method' => 'password'], "Tag login: the event has method, but it takes only eventName, customData, goalEvent and pagePath. Put the event's properties in customData."];
        yield 'a field the tracker sets' => [['eventName' => 'login', 'deviceClass' => 'bot'], 'The tracker sets deviceClass itself.'];
        yield 'an invalid name' => [['eventName' => 'log in'], 'Tag login: the event: eventName must be a fixed name such as "login": a letter, then up to 99 letters, digits, _ . : or -.'];
        yield 'a nested wrapper' => [['eventName' => 'login', 'customData' => ['customData' => ['a' => 1]]], 'Tag login: the event: customData.customData must be text of at most 500 bytes, a number, true, false or null, not an object or a list. To read a value from the page, use {"$var": "alias"}.'];
        yield 'a list property' => [['eventName' => 'login', 'customData' => ['tags' => ['a']]], 'customData.tags must be text'];
        yield 'long text' => [['eventName' => 'login', 'customData' => ['note' => str_repeat('a', 501)]], 'customData.note must be text of at most 500 bytes'];
        yield 'an unsafe integer' => [['eventName' => 'login', 'customData' => ['count' => 2 ** 60]], 'customData.count must be'];
        yield 'customData as a list' => [['eventName' => 'login', 'customData' => ['a']], 'Tag login: the event: customData must be an object of properties, such as {"method": "password"}.'];
        yield 'a property name' => [['eventName' => 'login', 'customData' => ['1st' => 'a']], 'customData.1st is not a property name'];
        yield 'a prototype name' => [['eventName' => 'login', 'customData' => ['constructor' => 'a']], 'customData.constructor is not a property name'];
        yield 'a built-in property' => [['eventName' => 'login', 'customData' => ['org_internal_traffic' => true]], 'Tag login: the event: the tracker adds customData.org_internal_traffic itself; leave it out.'];
        yield 'page depth' => [['eventName' => 'login', 'customData' => ['page_sequence' => 2]], 'the tracker adds customData.page_sequence itself'];
        yield 'too many properties' => [['eventName' => 'login', 'customData' => array_fill_keys(array_map(static fn (int $i): string => 'p'.$i, range(1, 33)), 1)], 'customData may hold at most 32 properties.'];
        yield 'an undefined variable' => [['eventName' => 'login', 'customData' => ['method' => ['$var' => 'missing']]], 'Tag login: the event: customData.method uses {"$var": …} with a variable that is not defined. Add it under Variables.'];
        yield 'a goal with spaces' => [['eventName' => 'login', 'goalEvent' => ' signup'], 'goalEvent must name a goal from config/goals.yaml, or be null.'];
        yield 'an empty goal' => [['eventName' => 'login', 'goalEvent' => ''], 'goalEvent must name a goal'];
        yield 'a query in the path' => [['eventName' => 'login', 'pagePath' => '/search?q=x'], 'pagePath must be a path on the site such as /checkout/step-2, without a query or fragment.'];
        yield 'a fragment in the path' => [['eventName' => 'login', 'pagePath' => '/a#b'], 'pagePath must be a path'];
        yield 'an address' => [['eventName' => 'login', 'pagePath' => 'https://site.example/x'], 'pagePath must be a path'];
        yield 'another host' => [['eventName' => 'login', 'pagePath' => '//site.example/x'], 'pagePath must be a path'];
        yield 'a relative path' => [['eventName' => 'login', 'pagePath' => 'checkout'], 'pagePath must be a path'];
    }
}
