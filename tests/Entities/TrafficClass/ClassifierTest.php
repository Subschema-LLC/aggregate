<?php

declare(strict_types=1);

namespace App\Tests\Entities\TrafficClass;

use App\Entities\TrafficClass\Classifier;
use App\Entities\TrafficClass\Confidence;
use App\Entities\TrafficClass\KnownAgents;
use App\Entities\TrafficClass\RangeSource;
use App\Entities\TrafficClass\Signals;
use App\Entities\TrafficClass\TrafficClass;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase
{
    public function testAPublishedBotListVerifiesItsOwnClass(): void
    {
        $fetcher = new RangeSource('openai-chatgpt-user', 'openai', class: TrafficClass::AiFetcher);
        $result = Classifier::classify(new Signals(
            knownAgent: null,
            ranges: [$fetcher],
        ));

        self::assertSame(TrafficClass::AiFetcher, $result->class);
        self::assertSame(Confidence::Verified, $result->confidence);
        self::assertSame('openai', $result->operator);
    }

    public function testAListCoveringSeveralBotsTakesTheClassFromTheUserAgent(): void
    {
        $anthropic = new RangeSource('anthropic-bots', 'anthropic');
        $claudeUser = KnownAgents::match('Mozilla/5.0 (compatible; Claude-User/1.0)');

        $named = Classifier::classify(new Signals($claudeUser, ranges: [$anthropic]));
        $unnamed = Classifier::classify(new Signals(null, ranges: [$anthropic]));
        $otherOperator = Classifier::classify(new Signals(
            KnownAgents::match('GPTBot/1.1'),
            ranges: [$anthropic],
        ));

        self::assertSame([TrafficClass::AiFetcher, Confidence::Verified], [
            $named->class, $named->confidence,
        ]);
        self::assertSame(TrafficClass::Automation, $unnamed->class);
        self::assertSame(TrafficClass::Automation, $otherOperator->class);
        self::assertSame('anthropic', $otherOperator->operator);
    }

    public function testAUserAgentAloneIsOnlyDeclared(): void
    {
        $result = Classifier::classify(new Signals(KnownAgents::match('GPTBot/1.1')));

        self::assertSame(TrafficClass::Crawler, $result->class);
        self::assertSame(Confidence::Declared, $result->confidence);
    }

    public function testAGenericBotIsOtherAutomation(): void
    {
        $result = Classifier::classify(new Signals(botUserAgent: true));

        self::assertSame(TrafficClass::Automation, $result->class);
        self::assertSame(Confidence::Declared, $result->confidence);
    }

    public function testACloudNetworkAloneStaysHuman(): void
    {
        $result = Classifier::classify(new Signals(ranges: [new RangeSource('aws', 'aws', true)]));

        self::assertSame(TrafficClass::Human, $result->class);
        self::assertSame(Confidence::Inferred, $result->confidence);
        self::assertCount(2, $result->reasons);
    }

    public function testReportedAutomationIsAnInferredAgent(): void
    {
        $alone = Classifier::classify(new Signals(automationReported: true));
        $withCloud = Classifier::classify(new Signals(
            automationReported: true,
            ranges: [new RangeSource('google-cloud', 'gcp', true)],
        ));

        self::assertSame(TrafficClass::AiAgent, $alone->class);
        self::assertSame(TrafficClass::AiAgent, $withCloud->class);
        self::assertSame(Confidence::Inferred, $withCloud->confidence);
        self::assertCount(2, $withCloud->reasons);
    }

    public function testABotListWinsOverACloudList(): void
    {
        $result = Classifier::classify(new Signals(ranges: [
            new RangeSource('google-cloud', 'gcp', true),
            new RangeSource('google-common-crawlers', 'google', class: TrafficClass::Crawler),
        ]));

        self::assertSame(TrafficClass::Crawler, $result->class);
        self::assertSame(Confidence::Verified, $result->confidence);
    }

    public function testNoSignalsIsHuman(): void
    {
        $result = Classifier::classify(new Signals());

        self::assertSame([
            'class' => 'human',
            'label' => 'Human',
            'confidence' => 'inferred',
            'operator' => null,
            'reasons' => ['Nothing marked the request as automated.'],
        ], $result->toArray());
    }

    public function testACloudListNamesNoClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RangeSource('aws', 'aws', true, TrafficClass::Crawler);
    }
}
