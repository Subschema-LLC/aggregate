<?php

declare(strict_types=1);

namespace App\Tests\Entities\AlertRule;

use App\Entities\AlertRule\AlertRule;
use App\Entities\AlertRule\EventNames;
use App\Entities\AlertRule\RuleReferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlertRuleTest extends TestCase
{
    public function testReadsTheLooseFormsYamlFormsAndCommandsSend(): void
    {
        $rule = AlertRule::fromInput([
            'rule' => ' checkout-quiet ',
            'enabled' => 'no',
            'website' => ' site-token ',
            'filter' => ['privacy_mode' => 'ENHANCED', 'event_name' => ' purchase '],
            'condition' => 'Fewer_Than',
            'n' => '25',
            'window' => '24h',
            'destinations' => 'ops, chat, ops',
        ], self::anyEventName());

        self::assertSame([
            'id' => 'checkout-quiet',
            'enabled' => false,
            'covers' => 'website:site-token',
            'source' => 'events',
            'privacy_mode' => 'enhanced',
            'event_name' => 'purchase',
            'condition' => 'fewer_than',
            'threshold' => 25,
            'window' => 'day',
            'destinations' => ['ops', 'chat'],
        ], $rule->toArray());
        self::assertSame('fewer_than_25_per_day', AlertRule::conditionText($rule->toArray()));
    }

    public function testTrackingFailuresDefaultToMoreThan(): void
    {
        $rule = AlertRule::fromInput([
            'id' => 'failures',
            'covers' => '*',
            'source' => 'tracking_failures',
            'threshold' => 0,
            'window' => 'hour',
            'destinations' => ['ops'],
        ], self::anyEventName())->toArray();

        self::assertSame('all', $rule['covers']);
        self::assertSame('more_than', $rule['condition']);
        self::assertSame('more_than_0_tracking_failures_per_hour', AlertRule::conditionText($rule));
    }

    public function testAnEventNameCollectionWouldRefuseIsRefused(): void
    {
        $names = new class implements EventNames {
            public function isSafe(string $eventName): bool
            {
                return false;
            }
        };

        $this->expectExceptionMessage('Event name filter must use the safe event-name format');
        AlertRule::fromInput(self::validRule(['event_name' => 'purchase']), $names);
    }

    public function testReferencesAreCheckedWhereTheirValueIsRead(): void
    {
        $references = new class implements RuleReferences {
            /** @var list<string> */
            public array $calls = [];

            public function assertCoversExist(string $covers): void
            {
                $this->calls[] = 'covers '.$covers;
                throw new \InvalidArgumentException('Website "gone" is not registered.');
            }

            public function assertWindowFitsRetention(
                string $source,
                string $privacyMode,
                string $window,
            ): void {
                $this->calls[] = 'window';
            }
        };

        // The source is invalid too, but the missing website is read first.
        try {
            AlertRule::fromInput(
                self::validRule(['covers' => 'website:gone', 'source' => 'nonsense']),
                self::anyEventName(),
                $references,
            );
            self::fail('A missing website must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Website "gone" is not registered.', $e->getMessage());
        }
        self::assertSame(['covers website:gone'], $references->calls);
    }

    public function testDestinationsMustBeKnownWhenAListIsGiven(): void
    {
        $this->expectExceptionMessage('Unknown alert destination "chat". Add the destination first.');
        AlertRule::fromInput(
            self::validRule(['destinations' => ['ops', 'chat']]),
            self::anyEventName(),
            null,
            ['ops'],
        );
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalidRules')]
    public function testRefusesInvalidRules(array $change, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        AlertRule::fromInput(self::validRule($change), self::anyEventName());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRules(): iterable
    {
        yield 'unknown field' => [['colour' => 'red'], 'Unknown alert rule field "colour".'];
        yield 'bad id' => [['id' => 'Has Spaces'], 'Rule ID (rule) must be 1 to 63'];
        yield 'nothing covered' => [['covers' => ' '], 'Choose a website, a website group'];
        yield 'bad group' => [['covers' => 'group:Bad_Group'], 'Choose a valid website group.'];
        yield 'filter list' => [['filter' => ['x']], 'Rule filter must be a mapping.'];
        yield 'privacy mode' => [['privacy_mode' => 'some'], 'Privacy mode filter must be all'];
        yield 'filtered failures' => [
            ['source' => 'tracking_failures', 'condition' => 'more_than', 'event_name' => 'x'],
            'filters apply only to the events source',
        ];
        yield 'events condition' => [['condition' => 'more_than'], 'must be no_events or fewer_than'];
        yield 'no_events threshold' => [
            ['condition' => 'no_events', 'threshold' => 3],
            'Threshold must be 0 or omitted when condition is no_events.',
        ];
        yield 'zero fewer_than' => [['threshold' => 0], 'Threshold (N) must be between 1 and 100000.'];
        yield 'text threshold' => [['threshold' => 'ten'], 'must be a whole number of at least 1'];
        yield 'window' => [['window' => 'month'], 'Window must be hour (1 completed UTC hour)'];
        yield 'no destinations' => [['destinations' => []], 'Choose at least one destination'];
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private static function validRule(array $change = []): array
    {
        return [...[
            'id' => 'quiet',
            'covers' => 'all',
            'condition' => 'fewer_than',
            'threshold' => 10,
            'window' => 'day',
            'destinations' => ['ops'],
        ], ...$change];
    }

    private static function anyEventName(): EventNames
    {
        return new class implements EventNames {
            public function isSafe(string $eventName): bool
            {
                return true;
            }
        };
    }
}
