<?php

declare(strict_types=1);

namespace App\Service\BotDetection;

/**
 * Recognizes crawlers, monitors and automated browsers from the User-Agent
 * header of a collection request. The header is read only while the request
 * is handled; it is never stored or logged.
 */
final class BotDetector
{
    /** Words a crawler's User-Agent usually contains when the maintained list does not name it yet. */
    public const GENERIC_PATTERN = '/\bbot\b|[a-z]bot\/|\bcrawler\b|\bspider\b|headless/i';
    /** Longer headers are not real browsers; only this much is examined. */
    public const MAX_LENGTH = 1024;

    private static ?string $knownBots = null;

    /**
     * @param list<string> $extraPhrases operator phrases (bot_user_agents), matched case-insensitively
     */
    public static function isBot(string $userAgent, array $extraPhrases = []): bool
    {
        if ($userAgent === '') {
            // Server-side senders and some privacy tools send no User-Agent.
            return false;
        }
        $userAgent = substr($userAgent, 0, self::MAX_LENGTH);
        self::$knownBots ??= '/'.implode('|', BotUserAgentPatterns::PATTERNS).'/';

        foreach ([self::$knownBots, self::GENERIC_PATTERN] as $pattern) {
            $match = preg_match($pattern, $userAgent);
            // A header that cannot be examined is not counted as a visitor.
            if ($match === 1 || $match === false) {
                return true;
            }
        }
        foreach ($extraPhrases as $phrase) {
            if ($phrase !== '' && stripos($userAgent, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }
}
