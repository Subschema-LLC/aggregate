<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates action-specific payload for script, call, event and custom tags.
 */
final class TagAction
{
    /**
     * @param array<string, mixed> $tag
     * @param array<string, string> $variables
     * @param array<string, true>   $seenSources
     * @return array<string, mixed>
     */
    public static function validate(
        mixed $type,
        array $tag,
        array $variables,
        string $id,
        CustomDataProperties $properties,
        array &$seenSources,
        ?CustomCodeSyntax $syntax,
        ?string $cacheDirectory,
        int &$codeBytes,
    ): array {
        return match ($type) {
            'script' => self::validateScript($tag, $variables, $seenSources),
            'call' => self::validateCall($tag, $variables),
            'event' => self::validateEvent($tag, $variables, $id, $properties),
            'custom' => self::validateCustom(
                $tag,
                $id,
                $syntax,
                $cacheDirectory,
                $codeBytes,
            ),
            default => throw new \InvalidArgumentException(
                'A tag type must be script, call, event or custom.',
            ),
        };
    }

    /**
     * @param array<string, mixed> $tag
     * @param array<string, string> $variables
     * @param array<string, true>   $seenSources
     * @return array{src: string}
     */
    private static function validateScript(
        array $tag,
        array $variables,
        array &$seenSources,
    ): array {
        if (array_key_exists('method', $tag) || array_key_exists('args', $tag)
            || array_key_exists('event', $tag) || array_key_exists('code', $tag)
        ) {
            throw new \InvalidArgumentException(
                'A script tag uses src; method and args belong to a call tag, '
                .'event to an event tag, and code to a custom tag.',
            );
        }
        $src = TagVariables::validateSource($tag['src'] ?? null, $variables);
        if (isset($seenSources[$src])) {
            throw new \InvalidArgumentException(
                'Each script tag needs a unique HTTPS URL template.',
            );
        }
        $seenSources[$src] = true;

        return ['src' => $src];
    }

    /**
     * @param array<string, mixed> $tag
     * @param array<string, string> $variables
     * @return array{method: string, args: array}
     */
    private static function validateCall(array $tag, array $variables): array
    {
        if (array_key_exists('src', $tag) || array_key_exists('event', $tag)
            || array_key_exists('code', $tag)
        ) {
            throw new \InvalidArgumentException(
                'A call tag uses method and args; src belongs to a script tag, '
                .'event to an event tag, and code to a custom tag.',
            );
        }

        return [
            'method' => TagValues::validateMethod($tag['method'] ?? null),
            'args' => TagArguments::validate(
                array_key_exists('args', $tag) ? $tag['args'] : [],
                $variables,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $tag
     * @param array<string, string> $variables
     * @return array{event: array}
     */
    private static function validateEvent(
        array $tag,
        array $variables,
        string $id,
        CustomDataProperties $properties,
    ): array {
        if (array_key_exists('src', $tag) || array_key_exists('method', $tag)
            || array_key_exists('args', $tag) || array_key_exists('code', $tag)
        ) {
            throw new \InvalidArgumentException(
                'An event tag uses event; src belongs to a script tag, '
                .'method and args to a call tag, and code to a custom tag.',
            );
        }

        return [
            'event' => TagEvent::validate(
                $tag['event'] ?? null,
                $variables,
                $properties,
                'Tag '.$id.': the event',
            ),
        ];
    }

    /**
     * @param array<string, mixed> $tag
     * @return array{code: string}
     */
    private static function validateCustom(
        array $tag,
        string $id,
        ?CustomCodeSyntax $syntax,
        ?string $cacheDirectory,
        int &$codeBytes,
    ): array {
        if (array_key_exists('src', $tag) || array_key_exists('method', $tag)
            || array_key_exists('args', $tag) || array_key_exists('event', $tag)
        ) {
            throw new \InvalidArgumentException(
                'A custom tag uses code; src belongs to a script tag, '
                .'method and args to a call tag, and event to an event tag.',
            );
        }
        $code = TagCustomCode::validate(
            $tag['code'] ?? null,
            'Tag '.$id.': the custom JavaScript',
            $syntax,
            $cacheDirectory,
        );
        $codeBytes += strlen($code);
        if ($codeBytes > TagCustomCode::MAX_TOTAL_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Custom JavaScript for one website may total at most %s bytes, '
                .'because every page downloads it with the tag manager. '
                .'Load larger code with tag.loadScript().',
                number_format(TagCustomCode::MAX_TOTAL_BYTES),
            ));
        }

        return ['code' => $code];
    }
}
