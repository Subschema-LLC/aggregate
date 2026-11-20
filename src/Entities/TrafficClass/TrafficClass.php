<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/**
 * Who or what sent a request, in the buckets reports separate:
 *
 * - human: nothing marked the request as automated;
 * - ai_agent: an automated browser acting for someone, such as an AI agent;
 * - ai_fetcher: a page fetched live because someone asked an assistant a
 *   question (retrieval), or another user-triggered fetch;
 * - crawler: a training or search crawler;
 * - automation: any other bot, such as an uptime monitor or SEO tool.
 */
enum TrafficClass: string
{
    case Human = 'human';
    case AiAgent = 'ai_agent';
    case AiFetcher = 'ai_fetcher';
    case Crawler = 'crawler';
    case Automation = 'automation';

    public function label(): string
    {
        return match ($this) {
            self::Human => 'Human',
            self::AiAgent => 'AI agent (interactive)',
            self::AiFetcher => 'AI live fetcher',
            self::Crawler => 'Training or search crawler',
            self::Automation => 'Other automation',
        };
    }

    public function isAutomated(): bool
    {
        return $this !== self::Human;
    }
}
