<?php

declare(strict_types=1);

namespace App\Features\Destinations\SaveDestination;

use App\Entities\Destination\Destination;

/**
 * On edit, a blank webhook URL or recipient list keeps the saved one: the
 * page never shows them again, and the command changes only what it is given.
 *
 * @phpstan-import-type DestinationArray from Destination
 */
final class KeepSaved
{
    /**
     * @param array<string, mixed>  $candidate
     * @param DestinationArray|null $existing
     *
     * @return array<string, mixed>
     */
    public static function target(array $candidate, string $type, ?array $existing): array
    {
        if ($existing === null || $existing['type'] !== $type) {
            return $candidate;
        }
        if ($type === Destination::TYPE_WEBHOOK && self::blank($candidate['url'] ?? null)) {
            $candidate['url'] = $existing['url'] ?? '';
        }
        $recipients = $candidate['recipients'] ?? $candidate['emails']
            ?? $candidate['addresses'] ?? null;
        if ($type === Destination::TYPE_EMAIL && self::blank($recipients)) {
            unset($candidate['emails'], $candidate['addresses']);
            $candidate['recipients'] = $existing['recipients'] ?? [];
        }

        return $candidate;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }
}
