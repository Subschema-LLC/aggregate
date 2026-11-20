<?php

declare(strict_types=1);

namespace App\Integrations\TrafficRanges;

/**
 * Reads the two layouts published range lists use:
 *
 * - prefixes: {"prefixes": [{"ipv4Prefix": "..."}, {"ipv6Prefix": "..."}]}, used by
 *   OpenAI, Anthropic, Perplexity, Google, Bing and Apple;
 * - aws: {"prefixes": [{"ip_prefix": "..."}], "ipv6_prefixes": [{"ipv6_prefix": "..."}]}.
 */
final class RangeFormats
{
    /**
     * @return list<string>
     *
     * @throws \RuntimeException when the body is not a list in that layout
     */
    public static function parse(string $format, string $body): array
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('The list is not valid JSON.');
        }
        if (!is_array($data)) {
            throw new \RuntimeException('The list is not a JSON object.');
        }

        return match ($format) {
            'prefixes' => self::entries($data['prefixes'] ?? null, ['ipv4Prefix', 'ipv6Prefix']),
            'aws' => array_merge(
                self::entries($data['prefixes'] ?? null, ['ip_prefix']),
                self::entries($data['ipv6_prefixes'] ?? [], ['ipv6_prefix']),
            ),
            default => throw new \RuntimeException(sprintf('Unknown range format "%s".', $format)),
        };
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private static function entries(mixed $entries, array $keys): array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new \RuntimeException('The list has no prefixes array.');
        }
        $ranges = [];
        foreach ($entries as $entry) {
            $found = null;
            foreach ($keys as $key) {
                if (is_array($entry) && is_string($entry[$key] ?? null)) {
                    $found = $entry[$key];
                }
            }
            if ($found === null) {
                throw new \RuntimeException('A prefix entry has no address range.');
            }
            $ranges[] = $found;
        }

        return $ranges;
    }
}
