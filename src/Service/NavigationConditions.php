<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\ViewSync\ViewSyncDestinations;

/**
 * Named conditions a navigation link can state with shown_when, so the menu
 * leaves out a page until it is in use and config/navigation.yaml still says
 * when it appears. Presentation only: the page itself enforces access, and
 * quick search still finds a link whose condition is not met.
 */
final class NavigationConditions
{
    /** Each condition and when it is met, as the configuration guide describes it. */
    public const CONDITIONS = [
        'view_sync_on' => 'BigQuery sync or the reporting database sync is turned on, or its settings need repair.',
    ];

    public function __construct(private readonly ViewSyncDestinations $sync)
    {
    }

    public static function has(mixed $name): bool
    {
        return is_string($name) && array_key_exists($name, self::CONDITIONS);
    }

    public function isMet(string $name): bool
    {
        return match ($name) {
            'view_sync_on' => $this->sync->anyNeedsThePage(),
            default => false,
        };
    }
}
