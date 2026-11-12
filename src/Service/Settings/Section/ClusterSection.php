<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertSettings;
use App\Service\Cluster\ClusterSettings;
use App\Service\Settings\AbstractSection;

/**
 * A cluster's settings on its administration server, as app:cluster:settings
 * and the Updates page's cluster section save them: the pause limit, the
 * alert destinations told about a server that stopped pulling, and the port
 * the balancer reaches the collection servers at. The server list has its
 * own command (app:cluster:server) and form.
 */
final class ClusterSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AlertSettings $alerts,
    ) {
    }

    public function name(): string
    {
        return 'cluster';
    }

    public function page(): string
    {
        return 'Administration > Updates > Servers';
    }

    public function keys(): array
    {
        return [ClusterSettings::MAX_AGE_KEY, ClusterSettings::ALERT_DESTINATIONS_KEY, ClusterSettings::COLLECTION_PORT_KEY];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);
        $settings = new ClusterSettings($this->config);

        return [
            ClusterSettings::MAX_AGE_KEY => $settings->maxAgeMinutes(),
            ClusterSettings::ALERT_DESTINATIONS_KEY => $settings->alertDestinations(),
            ClusterSettings::COLLECTION_PORT_KEY => $settings->collectionPort(),
        ];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        $validated = ClusterSettings::validateSettings($changes, array_keys($this->alerts->destinations()));
        if (!$check) {
            $this->config->updateMany(static fn (): array => $validated);
        }

        return [self::done($check, 'Saved '.implode(', ', array_keys($validated)).'.')];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
