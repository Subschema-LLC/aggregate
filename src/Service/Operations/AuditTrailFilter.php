<?php

declare(strict_types=1);

namespace App\Service\Operations;

/**
 * Which audit trail entries to list: the Audit trail page, its CSV export and
 * app:audit:list read the same filter from the same parameter names.
 *
 * Dates are whole UTC days, both inclusive. Areas, actions and outcomes match
 * any of the values given; the user matches exactly (NO_USER lists entries
 * without one, such as scheduled job runs); subject and text match part of a
 * value, ignoring case. Every filter given must match.
 */
final class AuditTrailFilter
{
    public const PAGE_SIZES = [25, 50, 100, 200];
    public const DEFAULT_PAGE_SIZE = 50;
    /** The user filter for entries recorded without a user. */
    public const NO_USER = '-';
    public const MAX_TEXT_LENGTH = 191;
    /** More values than this for one filter are not a real selection. */
    private const MAX_VALUES = 100;

    /**
     * @param list<string> $areas    categories (AuditTrailSettings::AREAS)
     * @param list<string> $actions  operation codes
     * @param list<string> $outcomes outcome codes
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $to = null,
        public readonly array $areas = [],
        public readonly array $actions = [],
        public readonly array $outcomes = [],
        public readonly ?string $user = null,
        public readonly ?string $subject = null,
        public readonly ?string $text = null,
        public readonly ?int $task = null,
        public readonly bool $oldestFirst = false,
        public readonly int $pageSize = self::DEFAULT_PAGE_SIZE,
        public readonly int $page = 1,
    ) {
    }

    /**
     * Reads the parameters the page and app:audit:list use: from, to (YYYY-MM-DD),
     * area[], action[], outcome[], user, subject, q, task, sort (newest or
     * oldest), per_page and page. A value that cannot be used is left out and
     * described in $problems, so a mistyped link still lists entries.
     *
     * @param array<string, mixed> $query
     * @param list<string>         $problems
     *
     * @param-out list<string> $problems
     */
    public static function fromQuery(array $query, ?array &$problems = null): self
    {
        $problems = [];
        $from = self::date($query['from'] ?? null, 'start date', $problems);
        $to = self::date($query['to'] ?? null, 'end date', $problems);
        if ($from !== null && $to !== null && $to < $from) {
            $problems[] = 'The end date is before the start date, so the dates were swapped.';
            [$from, $to] = [$to, $from];
        }
        $task = $query['task'] ?? null;
        if (is_string($task) && trim($task) !== '') {
            if (preg_match('/^[1-9][0-9]{0,17}$/D', trim($task)) === 1) {
                $task = (int) trim($task);
            } else {
                $problems[] = 'The job run number must be a whole number.';
                $task = null;
            }
        } else {
            $task = is_int($task) && $task > 0 ? $task : null;
        }
        $sort = $query['sort'] ?? 'newest';
        if (!in_array($sort, ['newest', 'oldest'], true)) {
            $problems[] = 'Sort by newest or oldest first.';
            $sort = 'newest';
        }
        $pageSize = self::integer($query['per_page'] ?? null) ?? self::DEFAULT_PAGE_SIZE;
        if (!in_array($pageSize, self::PAGE_SIZES, true)) {
            $problems[] = sprintf('Show %s entries per page.', implode(', ', self::PAGE_SIZES));
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        return new self(
            $from,
            $to,
            self::codes($query['area'] ?? null, 'area', $problems, array_keys(AuditTrailSettings::AREAS)),
            self::codes($query['action'] ?? null, 'action', $problems),
            self::codes($query['outcome'] ?? null, 'outcome', $problems),
            self::text($query['user'] ?? null),
            self::text($query['subject'] ?? null),
            self::text($query['q'] ?? null),
            $task,
            $sort === 'oldest',
            $pageSize,
            max(1, min(1_000_000, self::integer($query['page'] ?? null) ?? 1)),
        );
    }

    /**
     * The parameters that give this filter back, without defaults, for links.
     *
     * @return array<string, string|list<string>>
     */
    public function toQuery(bool $withPage = true): array
    {
        return array_filter([
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
            'area' => $this->areas,
            'action' => $this->actions,
            'outcome' => $this->outcomes,
            'user' => $this->user,
            'subject' => $this->subject,
            'q' => $this->text,
            'task' => $this->task !== null ? (string) $this->task : null,
            'sort' => $this->oldestFirst ? 'oldest' : null,
            'per_page' => $this->pageSize !== self::DEFAULT_PAGE_SIZE ? (string) $this->pageSize : null,
            'page' => $withPage && $this->page > 1 ? (string) $this->page : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function withPage(int $page): self
    {
        return new self($this->from, $this->to, $this->areas, $this->actions, $this->outcomes, $this->user, $this->subject, $this->text, $this->task, $this->oldestFirst, $this->pageSize, max(1, $page));
    }

    /** Whether any filter narrows the list; sort and paging do not. */
    public function isFiltered(): bool
    {
        return $this->filterNames() !== [];
    }

    /**
     * Which filters are set, by name only, for the audit entry of an export:
     * what someone searched for is not recorded.
     *
     * @return list<string>
     */
    public function filterNames(): array
    {
        return array_keys(array_filter([
            'dates' => $this->from !== null || $this->to !== null,
            'area' => $this->areas !== [],
            'action' => $this->actions !== [],
            'outcome' => $this->outcomes !== [],
            'user' => $this->user !== null,
            'subject' => $this->subject !== null,
            'text' => $this->text !== null,
            'job run' => $this->task !== null,
        ]));
    }

    /** @param list<string> $problems */
    private static function date(mixed $value, string $label, array &$problems): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            $problems[] = sprintf('The %s must be a date like 2026-10-18.', $label);

            return null;
        }

        return $date;
    }

    /**
     * @param list<string>      $problems
     * @param list<string>|null $allowed
     *
     * @return list<string>
     */
    private static function codes(mixed $value, string $label, array &$problems, ?array $allowed = null): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        $codes = [];
        foreach ($values as $code) {
            if ($code === '' || $code === null) {
                continue;
            }
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1 || ($allowed !== null && !in_array($code, $allowed, true))) {
                $problems[] = sprintf('An unknown %s was left out of the filter.', $label);
                continue;
            }
            $codes[$code] = true;
        }
        if (count($codes) > self::MAX_VALUES) {
            $problems[] = sprintf('At most %d values of one filter can be chosen.', self::MAX_VALUES);

            return array_slice(array_keys($codes), 0, self::MAX_VALUES);
        }

        return array_keys($codes);
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($value, 'UTF-8')));

        return $value === '' ? null : mb_substr($value, 0, self::MAX_TEXT_LENGTH);
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^[0-9]{1,9}$/D', trim($value)) === 1 ? (int) trim($value) : null;
    }
}
