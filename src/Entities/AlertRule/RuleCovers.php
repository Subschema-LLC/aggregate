<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/**
 * What a rule covers, written as "all", "group:<id>" or "website:<token>".
 * Older YAML may name it with website, websites, group or website_group.
 */
final class RuleCovers
{
    public const ALL = 'all';

    /** @param array<string, mixed> $candidate */
    public static function fromInput(array $candidate): string
    {
        $covers = $candidate['covers'] ?? self::fromOlderKeys($candidate);
        if (!is_string($covers) || trim($covers) === '') {
            throw new \InvalidArgumentException(
                'Choose a website, a website group, or all websites for the rule to cover.',
            );
        }
        $covers = trim($covers);
        if ($covers === self::ALL || $covers === '*') {
            return self::ALL;
        }
        if (str_starts_with($covers, 'group:')) {
            $groupId = substr($covers, 6);
            if ($groupId === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $groupId) !== 1) {
                throw new \InvalidArgumentException('Choose a valid website group.');
            }

            return 'group:'.$groupId;
        }
        $token = str_starts_with($covers, 'website:') ? substr($covers, 8) : $covers;
        if ($token === '' || strlen($token) > 191
            || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new \InvalidArgumentException('Choose a valid website token.');
        }

        return 'website:'.$token;
    }

    /** @param array<string, mixed> $candidate */
    private static function fromOlderKeys(array $candidate): ?string
    {
        if (isset($candidate['group']) || isset($candidate['website_group'])) {
            $group = $candidate['group'] ?? $candidate['website_group'];

            return is_string($group) ? 'group:'.trim($group) : null;
        }
        if (isset($candidate['website'])) {
            return is_string($candidate['website']) ? 'website:'.trim($candidate['website']) : null;
        }
        $websites = $candidate['websites'] ?? null;
        if (is_array($websites) && count($websites) === 1 && is_string($websites[0] ?? null)) {
            return 'website:'.trim($websites[0]);
        }

        return is_string($websites) ? 'website:'.trim($websites) : null;
    }
}
