<?php

declare(strict_types=1);

namespace App\Features\TrafficClassification\ClassifyRequest;

use App\Entities\TrafficClass\BotUserAgents;
use App\Entities\TrafficClass\Classifier;
use App\Entities\TrafficClass\KnownAgents;
use App\Entities\TrafficClass\PublishedRanges;
use App\Entities\TrafficClass\Signals;

/**
 * Classifies one request as human, AI agent, AI live fetcher, crawler or
 * other automation. The address and User-Agent are used only during the call
 * and are not part of the result.
 */
final class ClassifyRequest
{
    public function __construct(
        private readonly PublishedRanges $ranges,
        private readonly BotUserAgents $bots,
    ) {
    }

    /**
     * @param string $ip the client address, or '' when none is known
     *
     * @return array{class: string, label: string, confidence: string, operator: ?string,
     *     reasons: list<string>}
     */
    public function classify(string $userAgent, string $ip, bool $automationReported): array
    {
        $signals = new Signals(
            knownAgent: KnownAgents::match($userAgent),
            botUserAgent: $userAgent !== '' && $this->bots->isBot($userAgent),
            automationReported: $automationReported,
            ranges: $ip === '' ? [] : $this->ranges->sourcesContaining($ip),
        );

        return Classifier::classify($signals)->toArray();
    }
}
