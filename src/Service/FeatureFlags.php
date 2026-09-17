<?php

declare(strict_types=1);

namespace App\Service;

/** Deployment-wide capabilities configured only through the active aggregate YAML. */
class FeatureFlags
{
    public const DEFINITIONS = [
        'updates' => [
            'label' => 'Updates',
            'description' => 'Version checks, Git source updates, and offline release package verification.',
            'enabled' => true,
            'hide_from_navigation' => false,
        ],
    ];

    private const OPTIONS = ['enabled', 'hide_from_navigation'];

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array<string, array{enabled: bool, hide_from_navigation: bool}> */
    public function all(): array
    {
        $this->config->assertHealthy();

        return $this->resolve($this->config->all());
    }

    public function isEnabled(string $name): bool
    {
        try {
            return $this->all()[$name]['enabled'] ?? false;
        } catch (\RuntimeException|\InvalidArgumentException) {
            return false;
        }
    }

    public function isHiddenFromNavigation(string $name): bool
    {
        try {
            return $this->all()[$name]['hide_from_navigation'] ?? true;
        } catch (\RuntimeException|\InvalidArgumentException) {
            return true;
        }
    }

    public function assertEnabled(string $name): void
    {
        if (!$this->isEnabled($name)) {
            throw new \RuntimeException('The requested feature is disabled or its configuration is invalid.');
        }
    }

    /** Save partial changes without resetting untouched flags or options. */
    public function save(array $changes): void
    {
        $changes = $this->validate($changes);
        if ($changes === []) {
            $this->all();
            return;
        }

        $this->config->updateMany(function (array $current) use ($changes): array {
            $flags = $this->resolve($current);
            foreach ($changes as $name => $options) {
                $flags[$name] = array_replace($flags[$name], $options);
            }

            return ['feature_flags' => $flags];
        });
    }

    /** @return array<string, array{enabled: bool, hide_from_navigation: bool}> */
    private function resolve(array $config): array
    {
        // An explicitly null mapping is invalid; only omission selects defaults.
        $configured = $this->validate(array_key_exists('feature_flags', $config) ? $config['feature_flags'] : []);
        $flags = [];
        foreach (self::DEFINITIONS as $name => $definition) {
            $flags[$name] = array_replace([
                'enabled' => $definition['enabled'],
                'hide_from_navigation' => $definition['hide_from_navigation'],
            ], $configured[$name] ?? []);
        }

        return $flags;
    }

    /** @return array<string, array{enabled?: bool, hide_from_navigation?: bool}> */
    private function validate(mixed $flags): array
    {
        if (!is_array($flags)) {
            throw new \InvalidArgumentException('Feature flags must be a mapping.');
        }

        foreach ($flags as $name => $options) {
            if (!is_string($name) || !array_key_exists($name, self::DEFINITIONS)) {
                throw new \InvalidArgumentException('Feature flags contain an unregistered feature.');
            }
            if (!is_array($options)) {
                throw new \InvalidArgumentException(sprintf('The "%s" feature options must be a mapping.', $name));
            }
            foreach ($options as $option => $value) {
                if (!in_array($option, self::OPTIONS, true)) {
                    throw new \InvalidArgumentException(sprintf('The "%s" feature contains an unknown option.', $name));
                }
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException(sprintf('The "%s.%s" feature option must be a YAML boolean.', $name, $option));
                }
            }
        }

        return $flags;
    }
}
