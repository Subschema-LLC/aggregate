<?php

declare(strict_types=1);

namespace App\Entities\TagManager;

/**
 * Validates and represents one tag in a tag manager configuration.
 */
final class Tag
{
    public const ALLOWED_FIELDS = [
        'id', 'type', 'src', 'method', 'args', 'event', 'code', 'enabled',
        'consent', 'trigger', 'after',
    ];
    public const TYPES = ['script', 'call', 'event', 'custom'];

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @param array<string, mixed>  $candidate
     * @param array<string, string> $variables
     */
    public static function fromInput(
        array $candidate,
        array $variables,
        CustomDataProperties $properties,
        CustomCodeSyntax $syntax,
    ): self {
        if (array_diff(array_keys($candidate), self::ALLOWED_FIELDS) !== []) {
            throw new \InvalidArgumentException(
                'Each tag must contain only its ID, action, enabled setting, '
                .'consent, trigger and, optionally, the tag it runs after.',
            );
        }
        $id = $candidate['id'] ?? null;
        $type = array_key_exists('type', $candidate) ? $candidate['type'] : 'script';
        $tagEnabled = array_key_exists('enabled', $candidate) ? $candidate['enabled'] : true;
        $consent = array_key_exists('consent', $candidate) ? $candidate['consent'] : 'analytics';
        $trigger = TagTrigger::validate(
            array_key_exists('trigger', $candidate)
                ? $candidate['trigger']
                : ['type' => 'dom_ready'],
        );

        if (!is_string($id) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id) !== 1) {
            throw new \InvalidArgumentException(
                'Each tag needs a unique ID of 1–64 letters, digits, '
                .'underscores or hyphens, beginning with a letter.',
            );
        }
        if (!is_bool($tagEnabled)) {
            throw new \InvalidArgumentException(
                'Each tag enabled setting must be a YAML boolean (true or false).',
            );
        }
        if (!is_string($consent) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $consent) !== 1) {
            throw new \InvalidArgumentException(
                'Each tag consent category must contain 1–32 lowercase letters, '
                .'digits, underscores or hyphens, beginning with a letter. '
                .'Use none only for a tag that needs no consent.',
            );
        }

        $action = TagAction::validate($type, $candidate, $variables, $id, $properties, $syntax);
        $entry = [
            'id' => $id,
            ...$action,
            'enabled' => $tagEnabled,
            'consent' => $consent,
            'trigger' => $trigger,
            'type' => $type,
        ];
        if (array_key_exists('after', $candidate)) {
            $entry['after'] = $candidate['after'];
        }

        return new self($entry);
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function id(): string
    {
        return (string) $this->values['id'];
    }

    public function type(): string
    {
        return (string) $this->values['type'];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->values['enabled'];
    }

    public function consent(): string
    {
        return (string) $this->values['consent'];
    }

    /** @return array{type: string, event?: string} */
    public function trigger(): array
    {
        return $this->values['trigger'];
    }

    public function after(): ?string
    {
        return isset($this->values['after']) ? (string) $this->values['after'] : null;
    }

    public function src(): ?string
    {
        return isset($this->values['src']) ? (string) $this->values['src'] : null;
    }

    public function method(): ?string
    {
        return isset($this->values['method']) ? (string) $this->values['method'] : null;
    }

    public function code(): ?string
    {
        return isset($this->values['code']) ? (string) $this->values['code'] : null;
    }
}
