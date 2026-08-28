<?php

declare(strict_types=1);

namespace App\Service;

/** Resolves configured goal names without retaining rejected candidate values. */
final class GoalEventRegistry
{
    /** @var array<string, array{label: string, enabled: bool, anonymous: bool}> */
    private array $definitions;

    /**
     * @param array<string, mixed> $definitions
     */
    public function __construct(
        private readonly PrivacySanitizer $sanitizer,
        array $definitions,
    ) {
        $this->definitions = $this->validateDefinitions($definitions);
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

        $definition = $this->definitions[$goalEvent] ?? null;
        if ($definition === null || !$definition['enabled']) {
            return null;
        }

        if ($anonymousMode && !$definition['anonymous']) {
            return null;
        }

        return $goalEvent;
    }

    /**
     * @param array<string, mixed> $definitions
     *
     * @return array<string, array{label: string, enabled: bool, anonymous: bool}>
     */
    private function validateDefinitions(array $definitions): array
    {
        $validated = [];

        foreach ($definitions as $name => $definition) {
            if (!is_string($name)
                || trim($name) !== $name
                || !PrivacySanitizer::isSafeEventName($name)) {
                throw new \InvalidArgumentException('Configured goal names must use the safe event-name format.');
            }

            if (!is_array($definition)) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" must be a mapping.', $name));
            }

            $unexpectedKeys = array_diff(array_keys($definition), ['label', 'enabled', 'anonymous']);
            if ($unexpectedKeys !== []) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" contains unsupported options.', $name));
            }

            $label = $definition['label'] ?? null;
            if (!is_string($label) || trim($label) === '' || strlen($label) > 191) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" requires a non-empty label.', $name));
            }

            if (!array_key_exists('enabled', $definition) || !is_bool($definition['enabled'])) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" requires a boolean enabled option.', $name));
            }

            if (!array_key_exists('anonymous', $definition) || !is_bool($definition['anonymous'])) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" requires a boolean anonymous option.', $name));
            }

            $validated[$name] = [
                'label' => trim($label),
                'enabled' => $definition['enabled'],
                'anonymous' => $definition['anonymous'],
            ];
        }

        return $validated;
    }
}
