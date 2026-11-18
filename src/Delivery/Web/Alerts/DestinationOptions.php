<?php

declare(strict_types=1);

namespace App\Delivery\Web\Alerts;

use App\Features\Alerts\SaveDestination\SecretChange;
use Symfony\Component\Console\Attribute\Option;

/** The options of app:alerts:destination, and the destination they describe. */
final class DestinationOptions
{
    #[Option('Destination ID slug (e.g. geektown-hub, ops-email)')]
    public ?string $id = null;

    #[Option('Destination display label (e.g. "Geektown Hub")')]
    public ?string $label = null;

    #[Option('Destination type: webhook or email')]
    public ?string $type = null;

    #[Option('HTTPS webhook URL (webhook destinations only)')]
    public ?string $url = null;

    /** @var list<string> */
    #[Option('Recipient email address (email destinations only, repeatable)')]
    public array $recipient = [];

    #[Option('Webhook signing secret to save in config/secrets/alerts.yaml')]
    public ?string $secret = null;

    #[Option('Generate and store a new random webhook signing secret')]
    public bool $generateSecret = false;

    #[Option('With secret --id: remove the destination\'s own secret so it signs with the '
        .'shared one')]
    public bool $useShared = false;

    /** The trimmed ID, or null when none was given. */
    public function idOrNull(): ?string
    {
        return $this->id !== null && trim($this->id) !== '' ? trim($this->id) : null;
    }

    /**
     * The given options over $existing, the saved destination when editing.
     *
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    public function candidate(?array $existing): array
    {
        $candidate = $existing ?? [];
        unset($candidate['host']);
        $id = $this->idOrNull();
        if ($id !== null) {
            $candidate['id'] = $id;
        }
        $given = ['label' => $this->label, 'type' => $this->type, 'url' => $this->url];
        foreach ($given as $key => $value) {
            if ($value !== null) {
                $candidate[$key] = $value;
            }
        }
        if ($this->recipient !== []) {
            $candidate['recipients'] = $this->recipient;
        }

        return $candidate;
    }

    public function secretChange(): SecretChange
    {
        return $this->generateSecret
            ? SecretChange::generate()
            : SecretChange::set((string) ($this->secret ?? ''));
    }
}
