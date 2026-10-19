<?php

declare(strict_types=1);

namespace App\Security\Access;

/**
 * One set of grants, as stored on a user (their own grants) and on a
 * permission group: which websites, and which dashboard pages at which level.
 *
 *     websites:
 *         all: false            # every website and group, including new ones
 *         groups: [brand-a]     # every website in these website groups
 *         sites: [<token>]      # these websites, by registration token
 *     pages:
 *         tag_manager: edit     # view or edit; a page left out is no access
 *
 * normalize() validates a stored or submitted document and returns it in this
 * exact shape; it throws for anything it does not understand, so a typo can
 * never widen access.
 */
final class AccessGrant
{
    public const MAX_ITEMS = 500;

    private const GROUP_ID = '/^[a-z0-9][a-z0-9-]{0,62}$/D';
    /** As config/websites.yaml and website groups accept them: no spaces or control characters. */
    private const TOKEN = '/^[^\s\x00-\x1f\x7f]{1,191}$/D';

    /** @return array{websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>} */
    public static function empty(): array
    {
        return ['websites' => ['all' => false, 'groups' => [], 'sites' => []], 'pages' => []];
    }

    /**
     * @return array{websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return self::empty();
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new \InvalidArgumentException('Access must be a mapping with websites and pages.');
        }
        $unknown = array_diff(array_keys($raw), ['websites', 'pages']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown access setting "%s". Use websites and pages.', (string) reset($unknown)));
        }

        return ['websites' => self::websites($raw['websites'] ?? null), 'pages' => self::pages($raw['pages'] ?? null)];
    }

    /**
     * A user's stored access: their permission groups plus their own grants.
     *
     * @return array{permission_groups: list<string>, websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeUser(mixed $raw): array
    {
        if (is_array($raw) && !array_is_list($raw) && array_key_exists('permission_groups', $raw)) {
            $groups = self::list($raw['permission_groups'], self::GROUP_ID, 'permission group id');
            unset($raw['permission_groups']);
        } else {
            $groups = [];
        }

        return ['permission_groups' => $groups] + self::normalize($raw);
    }

    /** True when the grant gives nothing. */
    public static function isEmpty(array $grant): bool
    {
        return $grant['websites']['all'] === false && $grant['websites']['groups'] === []
            && $grant['websites']['sites'] === [] && $grant['pages'] === [];
    }

    /**
     * A grant built from the shared access fields of the Users and permission
     * group forms (users/_access_fields.html.twig).
     *
     * @param array<string, mixed> $form
     *
     * @return array{websites: array{all: bool, groups: list<string>, sites: list<string>}, pages: array<string, string>}
     */
    public static function fromForm(array $form): array
    {
        $mode = $form['website_mode'] ?? 'selected';
        if (!in_array($mode, ['all', 'selected'], true)) {
            throw new \InvalidArgumentException('Choose every website or selected websites.');
        }
        $pages = [];
        $levels = $form['pages'] ?? [];
        if (!is_array($levels)) {
            throw new \InvalidArgumentException('Choose a level for each page.');
        }
        foreach ($levels as $page => $level) {
            if ($level === '' || $level === 'none') {
                continue;
            }
            $pages[$page] = $level;
        }

        return self::normalize([
            'websites' => [
                'all' => $mode === 'all',
                'groups' => $mode === 'all' ? [] : ($form['website_groups'] ?? []),
                'sites' => $mode === 'all' ? [] : ($form['websites'] ?? []),
            ],
            'pages' => $pages,
        ]);
    }

    /** @return array{all: bool, groups: list<string>, sites: list<string>} */
    private static function websites(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return ['all' => false, 'groups' => [], 'sites' => []];
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new \InvalidArgumentException('Website access must be a mapping with all, groups and sites.');
        }
        $unknown = array_diff(array_keys($raw), ['all', 'groups', 'sites']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown website access setting "%s". Use all, groups and sites.', (string) reset($unknown)));
        }
        $all = $raw['all'] ?? false;
        if (!is_bool($all)) {
            throw new \InvalidArgumentException('Website access "all" must be true or false.');
        }

        return [
            'all' => $all,
            'groups' => self::list($raw['groups'] ?? [], self::GROUP_ID, 'website group id'),
            'sites' => self::list($raw['sites'] ?? [], self::TOKEN, 'website token'),
        ];
    }

    /** @return array<string, string> */
    private static function pages(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new \InvalidArgumentException('Page access must be a mapping of page to view or edit.');
        }
        $pages = [];
        // In menu order, whatever order they were given in.
        foreach (array_keys(DashboardPages::PAGES) as $page) {
            if (!array_key_exists($page, $raw)) {
                continue;
            }
            $level = $raw[$page];
            if (!is_string($level) || !in_array($level, DashboardPages::levels($page), true)) {
                throw new \InvalidArgumentException(sprintf('Choose %s for %s.', implode(' or ', DashboardPages::levels($page)), DashboardPages::label($page)));
            }
            $pages[$page] = $level;
        }
        $unknown = array_diff(array_keys($raw), array_keys(DashboardPages::PAGES));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown page "%s". Users and permission groups cannot be granted.', (string) reset($unknown)));
        }

        return $pages;
    }

    /** @return list<string> */
    private static function list(mixed $raw, string $pattern, string $what): array
    {
        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) {
            throw new \InvalidArgumentException(sprintf('Each %s must be in a list.', $what));
        }
        if (count($raw) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(sprintf('At most %d of each can be granted.', self::MAX_ITEMS));
        }
        $values = [];
        foreach ($raw as $value) {
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a valid %s.', is_scalar($value) ? (string) $value : gettype($value), $what));
            }
            if (!in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
