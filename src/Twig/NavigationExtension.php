<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Presentation filtering only: every destination must enforce its own authorization. */
final class NavigationExtension extends AbstractExtension
{
    private const MAX_ITEMS = 32;

    public function __construct(
        private readonly FeatureFlagsExtension $features,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('navigation_menu', [$this, 'menu'])];
    }

    public function menu(mixed $navigation): array
    {
        $navigation = is_array($navigation) ? $navigation : [];
        $account = is_array($navigation['account'] ?? null) ? $navigation['account'] : [];
        $items = [];
        $configuredItems = $navigation['items'] ?? [];
        if (is_array($configuredItems) && array_is_list($configuredItems)) {
            foreach (array_slice($configuredItems, 0, self::MAX_ITEMS) as $item) {
                $normalized = $this->item($item, allowGroup: true);
                if ($normalized !== null) {
                    $items[] = $normalized;
                }
            }
        }

        return [
            'brand' => $this->item($navigation['brand'] ?? null),
            'items' => $items,
            'account' => [
                'user_icon' => $this->text($account['user_icon'] ?? null),
                'logout' => $this->item($account['logout'] ?? null),
            ],
        ];
    }

    private function item(mixed $item, bool $allowGroup = false, bool $parentEnabled = true): ?array
    {
        if (!is_array($item) || ($label = $this->text($item['label'] ?? null)) === null) {
            return null;
        }
        if (array_key_exists('role', $item)) {
            $role = $this->text($item['role']);
            if ($role === null || !$this->authorization->isGranted($role)) {
                return null;
            }
        }

        $state = $this->features->navigationState($item);
        if ($state['hidden']) {
            return null;
        }
        $enabled = $state['enabled'] && $parentEnabled;
        $normalized = ['label' => $label, 'icon' => $this->text($item['icon'] ?? null), 'enabled' => $enabled];

        if (array_key_exists('children', $item)) {
            // One level only, and a group is a disclosure label rather than a link.
            if (!$allowGroup || array_key_exists('route', $item) || array_key_exists('url', $item)
                || !is_array($item['children']) || !array_is_list($item['children'])) {
                return null;
            }
            $children = [];
            foreach (array_slice($item['children'], 0, self::MAX_ITEMS) as $child) {
                $child = $this->item($child, parentEnabled: $enabled);
                if ($child !== null) {
                    $children[] = $child;
                }
            }

            return $children === [] ? null : [...$normalized, 'children' => $children];
        }

        $hasRoute = array_key_exists('route', $item);
        if ($hasRoute === array_key_exists('url', $item)) {
            return null;
        }
        $url = null;
        if ($hasRoute) {
            $route = $this->text($item['route']);
            $parameters = $item['route_parameters'] ?? [];
            if ($route === null || !$this->validParameters($parameters)) {
                return null;
            }
            if ($enabled) {
                try {
                    $url = $this->urls->generate($route, $parameters);
                } catch (RoutingException) {
                    // A stale/custom destination must not break the entire administration UI.
                    return null;
                }
            }
        } else {
            $url = $item['url'];
        }

        if ($url !== null && !$this->safeUrl($url)) {
            return null;
        }
        if (!$hasRoute && $url === null) {
            return null;
        }

        return [
            ...$normalized,
            'url' => $enabled ? $url : null,
            'logout' => ($item['route'] ?? null) === 'app_logout'
                || (is_string($url) && str_starts_with($url, '/') && !str_starts_with($url, '//')
                    && parse_url($url, PHP_URL_PATH) === '/logout'),
        ];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 160 ? trim($value) : null;
    }

    private function validParameters(mixed $parameters): bool
    {
        if (!is_array($parameters) || count($parameters) > self::MAX_ITEMS) {
            return false;
        }
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || strlen($key) > 160
                || (!is_scalar($value) && $value !== null)
                || (is_string($value) && strlen($value) > 2048)
                || (is_float($value) && !is_finite($value))) {
                return false;
            }
        }

        return true;
    }

    private function safeUrl(mixed $url): bool
    {
        if (!is_string($url) || $url === '' || strlen($url) > 2048
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            return false;
        }
        if (isset($parts['scheme']) || str_starts_with($url, '//')) {
            return isset($parts['host']) && $parts['host'] !== '';
        }

        return true;
    }
}
