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
            if (is_array($link['feature'])) {
                return $this->anyOf($link['feature']);
            }
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

    /**
     * A link several features share, such as API tokens for the reporting API
     * and the MCP server: available while any of them is on, and hidden only
     * when every feature that is on hides it (or, with all off, every one does).
     *
     * @param array<mixed> $names
     *
     * @return array{enabled: bool, hidden: bool}
     */
    private function anyOf(array $names): array
    {
        if ($names === [] || !array_is_list($names)) {
            return ['enabled' => false, 'hidden' => true];
        }
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                return ['enabled' => false, 'hidden' => true];
            }
        }
        $names = array_values(array_unique($names));
        $on = array_values(array_filter($names, fn (string $name): bool => $this->features->isEnabled($name)));
        $deciding = $on === [] ? $names : $on;
        $hidden = true;
        foreach ($deciding as $name) {
            $hidden = $this->features->isHiddenFromNavigation($name) && $hidden;
        }

        return ['enabled' => $on !== [], 'hidden' => $hidden];
    }

    private function linksToUpdates(array $link): bool
    {
        if (in_array($link['route'] ?? null, ['app_updates', 'app_updates_refresh', 'app_updates_install', 'app_updates_settings', 'app_updates_upload', 'app_updates_method'], true)) {
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
            '/dashboard/updates/settings',
            '/dashboard/updates/upload',
            '/dashboard/updates/method',
        ], true);
    }
}
