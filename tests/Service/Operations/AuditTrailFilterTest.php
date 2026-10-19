<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations;

use App\Service\Operations\AuditTrailFilter;
use PHPUnit\Framework\TestCase;

final class AuditTrailFilterTest extends TestCase
{
    public function testParametersRoundTripWithoutDefaults(): void
    {
        $query = [
            'from' => '2026-10-01', 'to' => '2026-10-18', 'area' => ['settings', 'users'], 'action' => ['user_created'],
            'outcome' => ['failed', 'denied'], 'user' => 'scott', 'subject' => 'Shop', 'q' => 'feature flags', 'task' => '41',
            'sort' => 'oldest', 'per_page' => '100', 'page' => '3',
        ];
        $filter = AuditTrailFilter::fromQuery($query, $problems);

        self::assertSame([], $problems);
        self::assertSame('2026-10-01 00:00:00 UTC', $filter->from?->format('Y-m-d H:i:s T'));
        self::assertSame([['settings', 'users'], ['user_created'], ['failed', 'denied']], [$filter->areas, $filter->actions, $filter->outcomes]);
        self::assertSame(['scott', 'Shop', 'feature flags', 41, true, 100, 3], [$filter->user, $filter->subject, $filter->text, $filter->task, $filter->oldestFirst, $filter->pageSize, $filter->page]);
        self::assertSame($query, $filter->toQuery());
        self::assertArrayNotHasKey('page', $filter->toQuery(false));
        self::assertSame(['dates', 'area', 'action', 'outcome', 'user', 'subject', 'text', 'job run'], $filter->filterNames());
        self::assertTrue($filter->isFiltered());
        self::assertSame(1, $filter->withPage(0)->page);

        $default = AuditTrailFilter::fromQuery([]);
        self::assertSame([], $default->toQuery());
        self::assertFalse($default->isFiltered());
        self::assertSame([AuditTrailFilter::DEFAULT_PAGE_SIZE, 1, false], [$default->pageSize, $default->page, $default->oldestFirst]);
        // Sorting and paging are not filters.
        self::assertFalse(AuditTrailFilter::fromQuery(['sort' => 'oldest', 'per_page' => '200', 'page' => '2'])->isFiltered());
    }

    public function testEmptyFormFieldsAreIgnored(): void
    {
        $filter = AuditTrailFilter::fromQuery(['from' => '', 'to' => ' ', 'area' => [''], 'outcome' => '', 'user' => '', 'subject' => '  ', 'q' => '', 'task' => '', 'per_page' => '50'], $problems);

        self::assertSame([], $problems);
        self::assertFalse($filter->isFiltered());
    }

    public function testUnusableValuesAreLeftOutAndExplained(): void
    {
        $filter = AuditTrailFilter::fromQuery([
            'from' => '2026-02-30', 'to' => 'yesterday', 'area' => ['settings', 'everything'], 'action' => ['DROP TABLE', 'user_created', ['nested']],
            'outcome' => 'failed', 'task' => '4x', 'sort' => 'random', 'per_page' => '1000', 'page' => '-2',
        ], $problems);

        self::assertNull($filter->from);
        self::assertNull($filter->to);
        self::assertSame(['settings'], $filter->areas);
        self::assertSame(['user_created'], $filter->actions);
        self::assertSame(['failed'], $filter->outcomes, 'a single value is accepted');
        self::assertNull($filter->task);
        self::assertSame([false, AuditTrailFilter::DEFAULT_PAGE_SIZE, 1], [$filter->oldestFirst, $filter->pageSize, $filter->page]);
        self::assertSame([
            'The start date must be a date like 2026-10-18.',
            'The end date must be a date like 2026-10-18.',
            'The job run number must be a whole number.',
            'Sort by newest or oldest first.',
            'Show 25, 50, 100, 200 entries per page.',
            'An unknown area was left out of the filter.',
            'An unknown action was left out of the filter.',
            'An unknown action was left out of the filter.',
        ], $problems);
    }

    public function testReversedDatesAreSwappedAndTextIsTrimmedToOneLine(): void
    {
        $filter = AuditTrailFilter::fromQuery(['from' => '2026-10-18', 'to' => '2026-10-01', 'q' => "  two\nlines\t", 'user' => str_repeat('a', 300)], $problems);

        self::assertSame(['2026-10-01', '2026-10-18'], [$filter->from?->format('Y-m-d'), $filter->to?->format('Y-m-d')]);
        self::assertSame(['The end date is before the start date, so the dates were swapped.'], $problems);
        self::assertSame('two lines', $filter->text);
        self::assertSame(AuditTrailFilter::MAX_TEXT_LENGTH, mb_strlen((string) $filter->user));
    }

    public function testTooManyValuesForOneFilterAreCut(): void
    {
        $actions = array_map(static fn (int $number): string => 'action_'.$number, range(1, 150));
        $filter = AuditTrailFilter::fromQuery(['action' => $actions], $problems);

        self::assertCount(100, $filter->actions);
        self::assertSame(['At most 100 values of one filter can be chosen.'], $problems);
    }
}
