<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates and represents a tag trigger.
 */
final class TagTrigger
{
    public const LIFECYCLE_TYPES = ['dom_ready', 'window_load'];
    public const EVENT_TYPES = ['document_event', 'window_event', 'data_layer'];
    public const TYPES = [...self::LIFECYCLE_TYPES, ...self::EVENT_TYPES];

    /** @return array{type: string, event?: string} */
    public static function validate(mixed $trigger): array
    {
        if (!is_array($trigger)
            || array_diff(array_keys($trigger), ['type', 'event']) !== []
        ) {
            throw new \InvalidArgumentException(
                'A tag trigger must contain type and, for an event trigger, event.',
            );
        }
        $type = array_key_exists('type', $trigger) ? $trigger['type'] : 'dom_ready';
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                'A tag trigger type must be dom_ready, window_load, '
                .'document_event, window_event or data_layer.',
            );
        }
        if (in_array($type, self::LIFECYCLE_TYPES, true)) {
            if (array_key_exists('event', $trigger)) {
                throw new \InvalidArgumentException(
                    'Document-ready and window-load triggers do not use an event name.',
                );
            }

            return ['type' => $type];
        }
        $event = $trigger['event'] ?? null;
        if (!is_string($event)
            || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/D', $event) !== 1
        ) {
            throw new \InvalidArgumentException(
                'An event trigger needs a name of 1–100 letters, digits, '
                .'underscores, dots, colons or hyphens, beginning with a letter.',
            );
        }

        return ['type' => $type, 'event' => $event];
    }
}
