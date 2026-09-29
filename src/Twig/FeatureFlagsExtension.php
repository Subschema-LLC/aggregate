<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\FeatureFlags;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FeatureFlagsExtension extends AbstractExtension
{
    public function __construct(private readonly FeatureFlags $features)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('feature_enabled', [$this->features, 'isEnabled']),
            new TwigFunction('feature_hidden_from_navigation', [$this->features, 'isHiddenFromNavigation']),
            new TwigFunction('navigation_feature_state', [$this, 'navigationState']),
        ];
    }

    /** @return array{enabled: bool, hidden: bool} */
    public function navigationState(array $link): array
    {
        $names = [];
        if (array_key_exists('feature', $link)) {
            if (!is_string($link['feature']) || $link['feature'] === '') {
                return ['enabled' => false, 'hidden' => true];
            }
            $names[] = $link['feature'];
        }

        // Older or customized navigation may omit the explicit feature tag.
        if ($this->linksToUpdates($link)) {
            $names[] = 'updates';
        }

        $enabled = true;
        $hidden = false;
        foreach (array_unique($names) as $name) {
            $enabled = $this->features->isEnabled($name) && $enabled;
            $hidden = $this->features->isHiddenFromNavigation($name) || $hidden;
        }

        return ['enabled' => $enabled, 'hidden' => $hidden];
    }

    private function linksToUpdates(array $link): bool
    {
        if (in_array($link['route'] ?? null, ['app_updates', 'app_updates_refresh', 'app_updates_install'], true)) {
            return true;
        }

        $url = $link['url'] ?? null;
        if (!is_string($url) || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && in_array(rtrim($path, '/'), [
            '/dashboard/updates',
            '/dashboard/updates/refresh',
            '/dashboard/updates/install',
        ], true);
    }
}
