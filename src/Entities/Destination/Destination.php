<?php

declare(strict_types=1);

namespace App\Entities\Destination;

/**
 * Where an alert is delivered: a webhook URL or a list of email addresses,
 * with a label and an ID that rules refer to. A Destination always holds a
 * valid value; fromInput() throws \InvalidArgumentException otherwise.
 *
 * @phpstan-type DestinationArray array{
 *     id: string,
 *     label: string,
 *     type: 'webhook'|'email',
 *     url?: string,
 *     host?: string,
 *     recipients?: list<string>
 * }
 */
final class Destination
{
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_EMAIL = 'email';
    public const TYPES = [self::TYPE_WEBHOOK, self::TYPE_EMAIL];
    public const MAX_LABEL = 80;

    private const FIELDS = ['id', 'label', 'type', 'url', 'recipients', 'emails', 'addresses'];

    /**
     * @param 'webhook'|'email' $type
     * @param list<string>      $recipients
     */
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $type,
        public readonly ?string $url,
        public readonly array $recipients,
    ) {
    }

    /**
     * Accepts what YAML, the dashboard form and the command line send:
     * recipients may also be named emails or addresses, and a missing ID is
     * made from the label.
     *
     * @param array<string, mixed> $candidate
     */
    public static function fromInput(array $candidate): self
    {
        $unknown = array_diff(array_keys($candidate), self::FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown alert destination field "%s".',
                (string) reset($unknown),
            ));
        }

        $label = self::checkLabel($candidate['label'] ?? null);

        $id = $candidate['id'] ?? self::slugify($label);
        if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', trim($id)) !== 1) {
            throw new \InvalidArgumentException(
                'A destination ID must be 1 to 63 lowercase letters, numbers, hyphens '
                .'or underscores.',
            );
        }
        $id = trim($id);

        $type = $candidate['type'] ?? null;
        $type = is_string($type) ? strtolower(trim($type)) : null;
        if ($type === self::TYPE_WEBHOOK) {
            $recipients = $candidate['recipients'] ?? null;
            if ($recipients !== null && $recipients !== [] && $recipients !== '') {
                throw new \InvalidArgumentException(
                    'A webhook destination cannot list email recipients.',
                );
            }

            return new self($id, $label, $type, WebhookUrl::check($candidate['url'] ?? null), []);
        }
        if ($type !== self::TYPE_EMAIL) {
            throw new \InvalidArgumentException('Destination type must be webhook or email.');
        }

        if (isset($candidate['url']) && trim((string) $candidate['url']) !== '') {
            throw new \InvalidArgumentException('An email destination cannot have a webhook URL.');
        }
        $raw = $candidate['recipients'] ?? $candidate['emails'] ?? $candidate['addresses'] ?? [];

        return new self($id, $label, $type, null, Recipients::check($raw));
    }

    /** @return DestinationArray */
    public function toArray(): array
    {
        if ($this->type === self::TYPE_WEBHOOK && $this->url !== null) {
            return [
                'id' => $this->id,
                'label' => $this->label,
                'type' => self::TYPE_WEBHOOK,
                'url' => $this->url,
                'host' => WebhookUrl::hostOf($this->url),
            ];
        }

        return [
            'id' => $this->id,
            'label' => $this->label,
            'type' => self::TYPE_EMAIL,
            'recipients' => $this->recipients,
        ];
    }

    public static function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug === '' ? 'destination' : substr($slug, 0, 63);
    }

    private static function checkLabel(mixed $label): string
    {
        if (!is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > self::MAX_LABEL
            || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1
            || !mb_check_encoding($label, 'UTF-8')) {
            throw new \InvalidArgumentException(sprintf(
                'A destination label is required and must be at most %d characters on one line.',
                self::MAX_LABEL,
            ));
        }

        return trim($label);
    }
}
