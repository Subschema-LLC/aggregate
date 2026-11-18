<?php

declare(strict_types=1);

namespace App\Features\TagManager\SaveTagManager;

/**
 * Validates and decodes action-specific fields from tag form rows.
 */
final class TagFormAction
{
    /**
     * @param array<string, mixed> $tag
     * @return array<string, mixed>
     */
    public static function extract(
        string $type,
        array $tag,
        string $src,
        string $method,
        string $args,
        string $event,
        string $code,
    ): array {
        if ($type === 'script') {
            if ($method !== '' || !in_array($args, ['', '[]'], true)) {
                throw new \InvalidArgumentException(
                    'A script action uses its HTTPS URL. '
                    .'Clear the method and arguments or choose Call a library method.',
                );
            }

            return ['src' => $src];
        }
        if ($type === 'call') {
            if ($src !== '') {
                throw new \InvalidArgumentException(
                    'A method action must not also contain a script URL.',
                );
            }
            try {
                if (strlen($args) > 2_000_000 || ($args !== '' && !str_starts_with($args, '['))) {
                    throw new \InvalidArgumentException('Method arguments must be a JSON array.');
                }
                $decoded = $args === '' ? [] : json_decode($args, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \InvalidArgumentException(
                    'Method arguments must be valid JSON, '
                    .'for example ["purchase", {"total_minor": 1299}].',
                );
            }

            return ['method' => $method, 'args' => $decoded];
        }
        if ($type === 'event') {
            if ($src !== '' || $method !== '' || !in_array($args, ['', '[]'], true)) {
                throw new \InvalidArgumentException(
                    'A Send an Aggregate event action uses only its event. '
                    .'Clear the script URL, method and arguments.',
                );
            }
            try {
                if (strlen($event) > 2_000_000 || !str_starts_with($event, '{')) {
                    throw new \JsonException();
                }
                $decoded = json_decode($event, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \InvalidArgumentException(
                    'Tag '.$tag['id'].': the event must be a JSON object, '
                    .'for example {"eventName": "login", "customData": {"method": "password"}}.',
                );
            }

            return ['event' => $decoded];
        }
        if ($type === 'custom') {
            if ($src !== '' || $method !== '' || !in_array($args, ['', '[]'], true)) {
                throw new \InvalidArgumentException(
                    'A custom JavaScript action uses only its code. '
                    .'Clear the script URL, method and arguments.',
                );
            }

            return ['code' => $code];
        }
        throw new \InvalidArgumentException(
            'Choose a script, Aggregate event, library method or custom JavaScript action.',
        );
    }
}
