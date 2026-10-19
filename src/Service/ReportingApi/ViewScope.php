<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

use App\Service\ViewSync\ViewCatalog;

/**
 * Which reporting views a credential may read: the rule shared by reporting
 * API tokens (ApiToken) and AI assistant connections (McpConnection), and the
 * validation both stores apply to a scope.
 *
 * - $views null means every approved view, including approved views a later
 *   release adds; a list means only those approved views.
 * - Labels and definitions (ViewCatalog::isMetadata) can always be read: they
 *   hold no traffic, and a caller needs them to read the views it was given.
 * - Private views are granted only through $privateViews, one by one, and are
 *   readable only while the deployment allows private views
 *   (ReportingApiSettings::PRIVATE_VIEWS).
 */
final class ViewScope
{
    /**
     * @param list<string>|null $views
     * @param list<string>      $privateViews
     */
    public static function mayRead(?array $views, array $privateViews, string $view, bool $privateViewsAllowed): bool
    {
        if (ViewCatalog::isApproved($view)) {
            return $views === null || ViewCatalog::isMetadata($view) || in_array($view, $views, true);
        }
        if (ViewCatalog::isPrivate($view)) {
            return $privateViewsAllowed && in_array($view, $privateViews, true);
        }

        return false;
    }

    /**
     * The readable views: approved views in catalog order, then private ones.
     *
     * @param list<string>|null $views
     * @param list<string>      $privateViews
     *
     * @return list<string>
     */
    public static function readable(?array $views, array $privateViews, bool $privateViewsAllowed): array
    {
        return array_values(array_filter(
            [...array_keys(ViewCatalog::APPROVED), ...array_keys(ViewCatalog::PRIVATE)],
            static fn (string $view): bool => self::mayRead($views, $privateViews, $view, $privateViewsAllowed),
        ));
    }

    /**
     * Validates a scope and puts each list in catalog order. A private view is
     * accepted only in its own list, so it is never granted by accident.
     *
     * @return array{0: list<string>|null, 1: list<string>}
     *
     * @throws \InvalidArgumentException naming the first problem
     */
    public static function validate(mixed $views, mixed $privateViews): array
    {
        if ($views !== null) {
            $views = self::viewList($views, 'views');
            foreach ($views as $view) {
                if (ViewCatalog::isPrivate($view)) {
                    throw new \InvalidArgumentException($view.' holds row-level or unsuppressed data. Grant it as a private view instead.');
                }
                if (!ViewCatalog::isApproved($view)) {
                    throw new \InvalidArgumentException($view.' is not a reporting view the API can serve.');
                }
            }
            $views = array_values(array_intersect(array_keys(ViewCatalog::APPROVED), $views));
        }
        $privateViews = self::viewList($privateViews ?? [], 'private_views');
        foreach ($privateViews as $view) {
            if (!ViewCatalog::isPrivate($view)) {
                throw new \InvalidArgumentException($view.' is not a private reporting view.'.(ViewCatalog::isApproved($view) ? ' Grant approved views as views.' : ''));
            }
        }

        return [$views, array_values(array_intersect(array_keys(ViewCatalog::PRIVATE), $privateViews))];
    }

    /** @return list<string> */
    private static function viewList(mixed $value, string $key): array
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw new \InvalidArgumentException($key.' must be a list of view names.');
        }
        $views = [];
        foreach ($value as $view) {
            if (!is_string($view) || trim($view) === '') {
                throw new \InvalidArgumentException($key.' must be a list of view names.');
            }
            $views[trim($view)] = true;
        }

        return array_keys($views);
    }
}
