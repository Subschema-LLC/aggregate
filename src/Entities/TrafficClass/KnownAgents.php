<?php

declare(strict_types=1);

namespace App\Entities\TrafficClass;

/**
 * User-Agent tokens that AI and search operators document for their crawlers
 * and fetchers, with the class each one belongs to. A token is a claim: anyone
 * can send it, so a match alone is only "declared".
 */
final class KnownAgents
{
    /** token => [operator, class] */
    private const AGENTS = [
        'GPTBot' => ['openai', TrafficClass::Crawler],
        'OAI-SearchBot' => ['openai', TrafficClass::Crawler],
        'OAI-AdsBot' => ['openai', TrafficClass::Automation],
        'ChatGPT-User' => ['openai', TrafficClass::AiFetcher],
        'ClaudeBot' => ['anthropic', TrafficClass::Crawler],
        'Claude-SearchBot' => ['anthropic', TrafficClass::Crawler],
        'Claude-User' => ['anthropic', TrafficClass::AiFetcher],
        'anthropic-ai' => ['anthropic', TrafficClass::Crawler],
        'PerplexityBot' => ['perplexity', TrafficClass::Crawler],
        'Perplexity-User' => ['perplexity', TrafficClass::AiFetcher],
        'Googlebot' => ['google', TrafficClass::Crawler],
        'GoogleOther' => ['google', TrafficClass::Crawler],
        'Google-CloudVertexBot' => ['google', TrafficClass::Crawler],
        'bingbot' => ['microsoft', TrafficClass::Crawler],
        'Applebot' => ['apple', TrafficClass::Crawler],
        'Amazonbot' => ['amazon', TrafficClass::Crawler],
        'CCBot' => ['commoncrawl', TrafficClass::Crawler],
        'Bytespider' => ['bytedance', TrafficClass::Crawler],
        'meta-externalagent' => ['meta', TrafficClass::Crawler],
        'meta-externalfetcher' => ['meta', TrafficClass::AiFetcher],
        'MistralAI-User' => ['mistral', TrafficClass::AiFetcher],
        'DuckAssistBot' => ['duckduckgo', TrafficClass::AiFetcher],
    ];

    /** Longer headers are not examined past this length. */
    public const MAX_LENGTH = 1024;

    /**
     * The documented agent a User-Agent names, longest token first, so
     * Claude-SearchBot is not read as a shorter token.
     *
     * @return array{token: string, operator: string, class: TrafficClass}|null
     */
    public static function match(string $userAgent): ?array
    {
        $userAgent = substr($userAgent, 0, self::MAX_LENGTH);
        if ($userAgent === '') {
            return null;
        }
        $tokens = array_keys(self::AGENTS);
        usort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($tokens as $token) {
            $pattern = '/(?<![a-z0-9-])'.preg_quote($token, '/').'(?![a-z0-9])/i';
            if (preg_match($pattern, $userAgent) === 1) {
                [$operator, $class] = self::AGENTS[$token];

                return ['token' => $token, 'operator' => $operator, 'class' => $class];
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function tokens(): array
    {
        return array_keys(self::AGENTS);
    }
}
