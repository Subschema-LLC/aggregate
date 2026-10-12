<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Page groups for bi_anonymous_daily_v1: YAML rules that map sanitized page
 * paths to a content label, such as every inventory page to "inventory".
 *
 * Rules are compiled into the view's SQL, so a change takes effect when the BI
 * views are regenerated. The first matching rule wins; a path that matches no
 * rule keeps its own path as its group.
 *
 * Pattern syntax is the subset of anonymous_excluded_paths globs that every
 * supported database can match exactly with LIKE: a literal prefix ending in
 * "/", then a last segment that is "**" (the directory and every depth below
 * it), a segment containing "*" (any text within that one segment), or a
 * literal segment.
 */
final class BiPageGroups
{
    public const KEY = 'bi_page_groups';
    public const MAX_RULES = 100;
    public const MAX_PATTERN_LENGTH = 512;
    public const MAX_GROUP_LENGTH = 64;

    private const PATTERN_CHARACTERS = '#^/[A-Za-z0-9/._~!$&()*+,;=:@%-]*$#D';
    private const GROUP_LABEL = '~^[A-Za-z0-9](?:[A-Za-z0-9 _.:()&+-]*[A-Za-z0-9)])?$~D';

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /**
     * The configured rules, validated. Invalid configuration throws, so view
     * regeneration stops and the existing views stay in place.
     *
     * @return list<array{pattern: string, group: string}>
     */
    public function rules(): array
    {
        try {
            return self::validate($this->config->get(self::KEY, []));
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(self::KEY.' is invalid: '.$e->getMessage(), 0, $e);
        }
    }

    /** @param list<array{pattern: string, group: string}> $rules */
    public function save(array $rules): void
    {
        $this->config->setMany([self::KEY => self::validate($rules)]);
    }

    /**
     * @return list<array{pattern: string, group: string}>
     *
     * @throws \InvalidArgumentException with a message suitable for the administrator
     */
    public static function validate(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw new \InvalidArgumentException('Page groups must be a list of rules, each with a pattern and a group.');
        }
        if (count($value) > self::MAX_RULES) {
            throw new \InvalidArgumentException(sprintf('At most %d page group rules are allowed.', self::MAX_RULES));
        }

        $rules = [];
        $seen = [];
        foreach ($value as $index => $rule) {
            $position = $index + 1;
            if (!is_array($rule) || array_diff(array_keys($rule), ['pattern', 'group']) !== []
                || !is_string($rule['pattern'] ?? null) || !is_string($rule['group'] ?? null)) {
                throw new \InvalidArgumentException(sprintf('Page group rule %d must have a text pattern and a text group, and nothing else.', $position));
            }
            $pattern = trim($rule['pattern']);
            $group = trim($rule['group']);
            self::assertPattern($pattern, $position);
            self::assertGroup($group, $position);
            if (isset($seen[$pattern])) {
                throw new \InvalidArgumentException(sprintf('Page group rule %d repeats the pattern %s.', $position, $pattern));
            }
            $seen[$pattern] = true;
            $rules[] = ['pattern' => $pattern, 'group' => $group];
        }

        return $rules;
    }

    /**
     * Rules from the dashboard text area: one per line, the pattern, then
     * whitespace, then the group. Blank lines and lines starting with # are
     * ignored.
     *
     * @return list<array{pattern: string, group: string}>
     */
    public static function parseLines(string $text): array
    {
        $rules = [];
        foreach (preg_split('/\R/', $text) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2) ?: [];
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException(sprintf('Line %d needs a pattern, a space and a group, such as "/inventory/** inventory".', $number + 1));
            }
            $rules[] = ['pattern' => $parts[0], 'group' => trim($parts[1])];
        }

        return self::validate($rules);
    }

    /** @param list<array{pattern: string, group: string}> $rules */
    public static function toLines(array $rules): string
    {
        return implode("\n", array_map(static fn (array $rule): string => $rule['pattern'].' '.$rule['group'], $rules));
    }

    /**
     * The group a path falls into under the given rules, as the view computes
     * it (with case-sensitive matching; see the documentation for collations).
     *
     * @param list<array{pattern: string, group: string}> $rules
     */
    public static function groupFor(string $path, array $rules): string
    {
        foreach ($rules as $rule) {
            if (self::matches($rule['pattern'], $path)) {
                return $rule['group'];
            }
        }

        return $path;
    }

    public static function matches(string $pattern, string $path): bool
    {
        [$prefix, $last] = self::split($pattern);
        if ($last === '**') {
            return $path === rtrim($prefix, '/') || str_starts_with($path, $prefix);
        }
        if (!str_contains($last, '*')) {
            return $path === $pattern;
        }
        if (!str_starts_with($path, $prefix)) {
            return false;
        }
        $rest = substr($path, strlen($prefix));
        $regex = '#^'.str_replace('\*', '[^/]*', preg_quote($last, '#')).'$#D';

        return !str_contains($rest, '/') && preg_match($regex, $rest) === 1;
    }

    /** @return array{0: string, 1: string} the literal prefix ending in "/", and the last segment */
    public static function split(string $pattern): array
    {
        $slash = strrpos($pattern, '/');
        if ($slash === false) {
            throw new \InvalidArgumentException('Page group patterns start with /.');
        }

        return [substr($pattern, 0, $slash + 1), substr($pattern, $slash + 1)];
    }

    private static function assertPattern(string $pattern, int $position): void
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_LENGTH || preg_match(self::PATTERN_CHARACTERS, $pattern) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Page group rule %d: the pattern must start with / and use only path characters (no spaces, quotes, backslashes, brackets, ? or #), at most %d characters.',
                $position,
                self::MAX_PATTERN_LENGTH,
            ));
        }
        [$prefix, $last] = self::split($pattern);
        if (str_contains($prefix, '*')) {
            throw new \InvalidArgumentException(sprintf('Page group rule %d: wildcards are only allowed in the last path segment, such as /inventory/** or /locations/*.', $position));
        }
        if (str_contains($last, '**') && $last !== '**') {
            throw new \InvalidArgumentException(sprintf('Page group rule %d: ** must be the whole last segment, such as /inventory/**.', $position));
        }
    }

    private static function assertGroup(string $group, int $position): void
    {
        if (strlen($group) > self::MAX_GROUP_LENGTH || preg_match(self::GROUP_LABEL, $group) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Page group rule %d: the group must be 1 to %d characters, start with a letter or digit, and use letters, digits, spaces and _ . : ( ) & + -. It cannot start with /, so it never looks like a page path.',
                $position,
                self::MAX_GROUP_LENGTH,
            ));
        }
    }
}
