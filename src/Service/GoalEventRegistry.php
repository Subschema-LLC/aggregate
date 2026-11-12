<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Resolves configured goal names without retaining rejected candidate values.
 * The goals come from GoalDefinitions, read when first needed, so a saved
 * change applies without clearing the cache; tests may pass them directly.
 */
final class GoalEventRegistry
{
    /** @var array<string, array{label: string, enabled: bool, anonymous: bool}>|null */
    private ?array $definitions = null;

    /**
     * @param GoalDefinitions|array<string, mixed> $source the goals, or fixed definitions for a test
     */
    public function __construct(
        private readonly PrivacySanitizer $sanitizer,
        private readonly GoalDefinitions|array $source,
    ) {
        if (is_array($source)) {
            $this->definitions = GoalDefinitions::validate($source);
        }
    }

    public function wasSubmitted(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        return !is_string($candidate) || trim($candidate) !== '';
    }

    public function resolve(mixed $candidate, bool $anonymousMode): ?string
    {
        // Goal keys are an exact API taxonomy. Whitespace and case variants
        // must not silently become aliases for a configured value.
        if (!is_string($candidate)
            || trim($candidate) === ''
            || trim($candidate) !== $candidate) {
            return null;
        }

        $goalEvent = $this->sanitizer->sanitizeGoalEvent($candidate);
        if ($goalEvent === null) {
            return null;
        }

        $definition = $this->definitions()[$goalEvent] ?? null;
        if ($definition === null || !$definition['enabled']) {
            return null;
        }

        if ($anonymousMode && !$definition['anonymous']) {
            return null;
        }

        return $goalEvent;
    }

    /** @return array<string, array{label: string, enabled: bool, anonymous: bool}> */
    private function definitions(): array
    {
        if ($this->source instanceof GoalDefinitions) {
            try {
                return $this->source->all();
            } catch (\InvalidArgumentException|\RuntimeException) {
                // Goals that cannot be read or are not valid allow no goal:
                // the event is still recorded, without one, and /api/health
                // reports the configuration as invalid.
                return [];
            }
        }

        return $this->definitions ?? [];
    }
}
