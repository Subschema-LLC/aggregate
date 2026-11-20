<?php

declare(strict_types=1);

namespace App\Integrations\TrafficRanges;

use App\Entities\TrafficClass\RangeSource;
use App\Entities\TrafficClass\TrafficClass;

/**
 * The range lists operators publish for their bots, and cloud providers for
 * their networks, as their documentation gave them on 2026-11-20. Lists are
 * downloaded by the operator's installation and never shipped in a release.
 */
final class PublishedSources
{
    private const GOOGLE = 'https://developers.google.com/static/crawling/ipranges/';

    /** id => [url, format, operator, class code or null, cloud] */
    private const SOURCES = [
        'openai-gptbot' => [
            'https://openai.com/gptbot.json',
            'prefixes', 'openai', 'crawler',
        ],
        'openai-searchbot' => [
            'https://openai.com/searchbot.json',
            'prefixes', 'openai', 'crawler',
        ],
        'openai-chatgpt-user' => [
            'https://openai.com/chatgpt-user.json',
            'prefixes', 'openai', 'ai_fetcher',
        ],
        'openai-adsbot' => [
            'https://openai.com/adsbot.json',
            'prefixes', 'openai', 'automation',
        ],
        'anthropic-bots' => [
            'https://claude.com/crawling/bots.json',
            'prefixes', 'anthropic', null,
        ],
        'perplexity-bot' => [
            'https://www.perplexity.com/perplexitybot.json',
            'prefixes', 'perplexity', 'crawler',
        ],
        'perplexity-user' => [
            'https://www.perplexity.com/perplexity-user.json',
            'prefixes', 'perplexity', 'ai_fetcher',
        ],
        'google-common-crawlers' => [
            self::GOOGLE.'common-crawlers.json',
            'prefixes', 'google', 'crawler',
        ],
        'google-special-crawlers' => [
            self::GOOGLE.'special-crawlers.json',
            'prefixes', 'google', 'automation',
        ],
        'google-user-fetchers' => [
            self::GOOGLE.'user-triggered-fetchers-google.json',
            'prefixes', 'google', 'ai_fetcher',
        ],
        'google-user-agents' => [
            self::GOOGLE.'user-triggered-agents.json',
            'prefixes', 'google', 'ai_agent',
        ],
        'bing-bingbot' => [
            'https://www.bing.com/toolbox/bingbot.json',
            'prefixes', 'microsoft', 'crawler',
        ],
        'apple-applebot' => [
            'https://search.developer.apple.com/applebot.json',
            'prefixes', 'apple', 'crawler',
        ],
        'aws' => [
            'https://ip-ranges.amazonaws.com/ip-ranges.json',
            'aws', 'aws', null, true,
        ],
        'google-cloud' => [
            'https://www.gstatic.com/ipranges/cloud.json',
            'prefixes', 'gcp', null, true,
        ],
    ];

    /** @return list<RangeSource> */
    public function all(): array
    {
        $sources = [];
        foreach (self::SOURCES as $id => $definition) {
            $class = $definition[3] === null ? null : TrafficClass::from($definition[3]);
            $sources[] = new RangeSource($id, $definition[2], $definition[4] ?? false, $class);
        }

        return $sources;
    }

    /** @return array{url: string, format: string} */
    public function location(RangeSource $source): array
    {
        $definition = self::SOURCES[$source->id] ?? null;
        if ($definition === null) {
            throw new \RuntimeException(sprintf('There is no range source "%s".', $source->id));
        }

        return ['url' => $definition[0], 'format' => $definition[1]];
    }
}
