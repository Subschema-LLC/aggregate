<?php

declare(strict_types=1);

namespace App\Service\Settings\Section;

use App\Service\AggregateConfigLoader;
use App\Service\Settings\AbstractSection;
use App\Service\TrackerBuilds;

/** Leave out unused tracker features, as General settings' Page speed saves it. */
final class PageSpeedSection extends AbstractSection
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly TrackerBuilds $builds,
    ) {
    }

    public function name(): string
    {
        return 'page-speed';
    }

    public function page(): string
    {
        return 'Administration > General settings > Page speed';
    }

    public function keys(): array
    {
        return [TrackerBuilds::CONFIG_KEY];
    }

    public function values(?string $target = null): array
    {
        $this->assertTarget($target);

        return [TrackerBuilds::CONFIG_KEY => $this->builds->enabled()];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        $this->assertTarget($target);
        $this->assertKnown($changes);
        if ($this->builds->hasEnvironmentOverride()) {
            throw new \InvalidArgumentException('Leave out unused tracker features is set by the TRACKER_OMIT_UNUSED_FEATURES environment variable, which takes precedence.');
        }
        $enabled = TrackerBuilds::normalize($changes[TrackerBuilds::CONFIG_KEY]);
        if ($check) {
            return [self::done(true, '')];
        }
        $this->config->set(TrackerBuilds::CONFIG_KEY, $enabled);

        return [$enabled
            ? 'Visitors now get the smallest tracker for your settings. Browsers that already have the tracker receive it within five minutes.'
            : 'Every visitor now gets the full tracker. Browsers that already have the tracker receive it within five minutes.'];
    }

    protected function config(): AggregateConfigLoader
    {
        return $this->config;
    }
}
