<?php

declare(strict_types=1);

namespace App\Features\SaveDestination;

use App\Entities\Destination\Destination;
use App\Entities\Destination\Destinations;
use App\Entities\Destination\SigningSecrets;

/**
 * Creates or edits an alert destination and its signing secret, for the
 * Alerts page and app:alerts:destination alike. A missing ID is made from the
 * label. On edit, a blank URL or recipient list keeps the saved one. A
 * webhook's own secret changes only with the destination, never when the
 * environment sets it, and a webhook turned into email drops its secret.
 *
 * @phpstan-import-type DestinationArray from Destination
 */
final class SaveDestination
{
    public function __construct(
        private readonly Destinations $destinations,
        private readonly SigningSecrets $secrets,
    ) {
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return array{destination: DestinationArray, generated_secret: ?string}
     */
    public function create(array $candidate, SecretChange $secret): array
    {
        return $this->save($candidate, $secret, true);
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return array{destination: DestinationArray, generated_secret: ?string}
     */
    public function edit(array $candidate, SecretChange $secret): array
    {
        return $this->save($candidate, $secret, false);
    }

    /**
     * @param array<string, mixed> $candidate
     *
     * @return array{destination: DestinationArray, generated_secret: ?string}
     */
    private function save(array $candidate, SecretChange $secret, bool $isNew): array
    {
        $label = is_string($candidate['label'] ?? null) ? trim($candidate['label']) : '';
        $id = is_string($candidate['id'] ?? null) ? trim($candidate['id']) : '';
        if ($id === '' && $label !== '') {
            $id = Destination::slugify($label);
            $candidate['id'] = $id;
        }
        $existing = $id !== '' ? $this->destinations->find($id) : null;
        if ($isNew && $existing !== null) {
            throw new DestinationAlreadyExists($id);
        }
        if (!$isNew && $existing === null) {
            throw new DestinationNotFound($id);
        }

        $type = is_string($candidate['type'] ?? null) ? strtolower(trim($candidate['type'])) : '';
        $candidate = KeepSaved::target($candidate, $type, $existing);
        $webhook = $type === Destination::TYPE_WEBHOOK;
        $fromEnvironment = $id !== '' && $this->secrets->ownIsSetByEnvironment($id);
        if ($webhook && $secret->changes() && $fromEnvironment) {
            throw new \InvalidArgumentException(sprintf(
                'This destination\'s signing secret is set by %s in the environment and cannot be '
                .'changed here.',
                $this->secrets->environmentName($id),
            ));
        }

        $saved = $this->destinations->save($candidate);
        $generated = null;
        if ($webhook) {
            $generated = $secret->applyTo($this->secrets, $saved['id']);
        } elseif ($existing !== null && $existing['type'] === Destination::TYPE_WEBHOOK) {
            $this->secrets->remove($saved['id']);
        }

        return ['destination' => $saved, 'generated_secret' => $generated];
    }
}
