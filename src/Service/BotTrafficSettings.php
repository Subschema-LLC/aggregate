<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What collection does with requests from bots: crawlers, monitors and
 * automated browsers recognized by their User-Agent, and browsers the tracker
 * reports as automated (navigator.webdriver).
 *
 * - label (default): the event is recorded with device_class "bot", so reports
 *   can include or leave out bot traffic.
 * - drop: the event is ignored before any other processing; nothing is stored.
 *
 * bot_traffic has a BOT_TRAFFIC environment override; bot_user_agents, extra
 * phrases that mark a request as a bot, is read from YAML only. Invalid values
 * make the configuration unhealthy, which stops ingestion, and every reader
 * treats them as drop, the narrower choice.
 */
final class BotTrafficSettings
{
    public const KEY = 'bot_traffic';
    public const USER_AGENTS_KEY = 'bot_user_agents';
    public const DROP = 'drop';
    public const LABEL = 'label';
    public const MODES = [self::LABEL, self::DROP];
    public const DEFAULT_MODE = self::LABEL;
    public const MAX_USER_AGENTS = 50;
    public const MIN_PHRASE_LENGTH = 3;
    public const MAX_PHRASE_LENGTH = 100;

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public static function normalizeMode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return in_array($value, self::MODES, true) ? $value : null;
    }

    /**
     * Trimmed, de-duplicated phrases, or null when any entry is invalid.
     *
     * @return list<string>|null
     */
    public static function normalizeUserAgents(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            return null;
        }
        $phrases = [];
        foreach ($value as $phrase) {
            if (!is_string($phrase) || preg_match('//u', $phrase) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $phrase) === 1) {
                return null;
            }
            $phrase = trim($phrase);
            if (mb_strlen($phrase) < self::MIN_PHRASE_LENGTH || mb_strlen($phrase) > self::MAX_PHRASE_LENGTH) {
                return null;
            }
            if (!in_array(mb_strtolower($phrase), array_map('mb_strtolower', $phrases), true)) {
                $phrases[] = $phrase;
            }
        }

        return count($phrases) > self::MAX_USER_AGENTS ? null : $phrases;
    }

    /** Whether the configured values are valid; AggregateConfigLoader's health check uses this. */
    public static function isValidConfiguration(mixed $mode, mixed $userAgents): bool
    {
        return self::normalizeMode($mode) !== null && self::normalizeUserAgents($userAgents) !== null;
    }

    public function mode(): string
    {
        $value = $this->config->getWithEnvFallback(self::KEY, self::DEFAULT_MODE);

        return self::isValidConfiguration($value, $this->config->get(self::USER_AGENTS_KEY, []))
            ? (string) self::normalizeMode($value) : self::DROP;
    }

    /** @return list<string> */
    public function userAgents(): array
    {
        return self::normalizeUserAgents($this->config->get(self::USER_AGENTS_KEY, [])) ?? [];
    }

    public function hasEnvironmentOverride(): bool
    {
        return $this->config->hasEnvironmentOverride(self::KEY);
    }

    /** Whether a saved value is invalid, so ingestion is stopped until it is corrected. */
    public function isInvalid(): bool
    {
        return !self::isValidConfiguration(
            $this->config->getWithEnvFallback(self::KEY, self::DEFAULT_MODE),
            $this->config->get(self::USER_AGENTS_KEY, []),
        );
    }
}
