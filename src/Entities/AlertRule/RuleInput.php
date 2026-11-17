<?php

declare(strict_types=1);

namespace App\Entities\AlertRule;

/**
 * Reads the loosely typed values YAML, forms and the command line send for a
 * rule: booleans, thresholds, windows and destination lists.
 */
final class RuleInput
{
    public static function boolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => throw new \InvalidArgumentException(
                    sprintf('%s must be true or false.', $field),
                ),
            };
        }

        throw new \InvalidArgumentException(sprintf('%s must be true or false.', $field));
    }

    public static function threshold(string $condition, mixed $raw): int
    {
        if ($condition === AlertRule::CONDITION_NO_EVENTS) {
            if ($raw !== null && $raw !== '' && $raw !== 0 && $raw !== '0') {
                throw new \InvalidArgumentException(
                    'Threshold must be 0 or omitted when condition is no_events.',
                );
            }

            return 0;
        }
        $min = $condition === AlertRule::CONDITION_FEWER_THAN ? 1 : 0;
        if (is_int($raw)) {
            $int = $raw;
        } elseif (is_string($raw) && preg_match('/^[0-9]+$/D', trim($raw)) === 1) {
            $int = (int) trim($raw);
        } else {
            throw new \InvalidArgumentException(
                sprintf('Threshold (N) must be a whole number of at least %d.', $min),
            );
        }
        if ($int < $min || $int > AlertRule::MAX_THRESHOLD) {
            throw new \InvalidArgumentException(sprintf(
                'Threshold (N) must be between %d and %d.',
                $min,
                AlertRule::MAX_THRESHOLD,
            ));
        }

        return $int;
    }

    /** @return 'hour'|'day'|'week' */
    public static function window(mixed $window): string
    {
        if (is_int($window)) {
            $window = (string) $window;
        }
        if (!is_string($window)) {
            throw new \InvalidArgumentException(
                'Window must be hour (1h), day (24h) or week (168h).',
            );
        }

        return match (strtolower(trim($window))) {
            'hour', '1h', '1' => AlertRule::WINDOW_HOUR,
            'day', '24h', '24' => AlertRule::WINDOW_DAY,
            'week', '168h', '168' => AlertRule::WINDOW_WEEK,
            default => throw new \InvalidArgumentException(
                'Window must be hour (1 completed UTC hour), day (24 completed UTC hours) '
                .'or week (168 completed UTC hours).',
            ),
        };
    }

    /**
     * @param list<string>|null $knownIds when given, every destination must be in it
     *
     * @return list<string>
     */
    public static function destinations(mixed $raw, ?array $knownIds): array
    {
        if (is_string($raw) && trim($raw) !== '') {
            $raw = array_map('trim', explode(',', $raw));
        }
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            throw new \InvalidArgumentException(
                'Choose at least one destination for the alert rule.',
            );
        }
        $destinations = [];
        foreach ($raw as $id) {
            if (!is_string($id) || trim($id) === '') {
                throw new \InvalidArgumentException(
                    'Each rule destination must be a destination ID.',
                );
            }
            $id = trim($id);
            if ($knownIds !== null && !in_array($id, $knownIds, true)) {
                throw new \InvalidArgumentException(
                    sprintf('Unknown alert destination "%s". Add the destination first.', $id),
                );
            }
            if (!in_array($id, $destinations, true)) {
                $destinations[] = $id;
            }
        }

        return $destinations;
    }
}
