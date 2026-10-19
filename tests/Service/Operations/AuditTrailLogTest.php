<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations;

use App\Service\Operations\AuditTrail;
use App\Service\Operations\AuditTrailFilter;
use App\Service\Operations\AuditTrailLog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditTrailLogTest extends TestCase
{
    private Connection $connection;
    private AuditTrail $trail;
    private AuditTrailLog $log;

    protected function setUp(): void
    {
        $this->connection = OperationsDatabase::connection();
        $this->trail = new AuditTrail($this->connection);
        $this->log = new AuditTrailLog($this->connection);
        foreach ([
            ['2026-10-01 08:00:00', 'settings', 'branding_saved', 'succeeded', null, 'scott', null, 'Changed aggregate.yaml: brand_name'],
            ['2026-10-02 09:30:00', 'users', 'user_created', 'succeeded', 'ana', 'scott', null, 'Viewer account.'],
            ['2026-10-02 23:59:59', 'users', 'password_changed', 'denied', 'scott', 'ana', null, null],
            ['2026-10-03 00:00:00', 'task', 'bigquery_sync', 'failed', 'bi_anonymous_events_v1', null, 41, 'No service account key 100%_ready'],
            ['2026-10-04 12:00:00', 'settings', 'feature_flags_saved', 'failed', null, 'Scott', null, 'Changed aggregate.yaml: feature_flags.updates'],
            ['2026-10-05 12:00:00', 'websites', 'website_created', 'succeeded', 'Shop', 'root (command line)', null, 'Command app:create-website. Changed websites.yaml: websites'],
            ['2026-10-06 07:15:00', 'updates', 'update_started', 'started', null, 'scott', null, null],
        ] as [$time, $area, $action, $outcome, $subject, $user, $task, $details]) {
            $this->trail->record($area, $action, $outcome, new \DateTimeImmutable($time.' UTC'), $subject, $user, $task, $details);
        }
    }

    /** @param list<string> $expected actions, newest first */
    #[DataProvider('filters')]
    public function testFiltersNarrowTheEntries(array $query, array $expected): void
    {
        $filter = AuditTrailFilter::fromQuery($query, $problems);
        self::assertSame([], $problems);

        $result = $this->log->search($filter);
        self::assertSame($expected, array_column($result['entries'], 'action'));
        self::assertSame(count($expected), $result['total']);
    }

    public static function filters(): iterable
    {
        yield 'everything, newest first' => [[], ['update_started', 'website_created', 'feature_flags_saved', 'bigquery_sync', 'password_changed', 'user_created', 'branding_saved']];
        yield 'oldest first' => [['sort' => 'oldest', 'area' => ['users']], ['user_created', 'password_changed']];
        yield 'whole days, both included' => [['from' => '2026-10-02', 'to' => '2026-10-03'], ['bigquery_sync', 'password_changed', 'user_created']];
        yield 'from a day on' => [['from' => '2026-10-05'], ['update_started', 'website_created']];
        yield 'until a day' => [['to' => '2026-10-01'], ['branding_saved']];
        yield 'several areas' => [['area' => ['users', 'websites']], ['website_created', 'password_changed', 'user_created']];
        yield 'several actions' => [['action' => ['branding_saved', 'update_started']], ['update_started', 'branding_saved']];
        yield 'several outcomes' => [['outcome' => ['failed', 'denied']], ['feature_flags_saved', 'bigquery_sync', 'password_changed']];
        yield 'a user exactly' => [['user' => 'scott'], ['update_started', 'user_created', 'branding_saved']];
        yield 'entries without a user' => [['user' => AuditTrailFilter::NO_USER], ['bigquery_sync']];
        yield 'subject contains, ignoring case' => [['subject' => 'SHO'], ['website_created']];
        yield 'search the details' => [['q' => 'feature_flags'], ['feature_flags_saved']];
        yield 'search words find codes' => [['q' => 'user created'], ['user_created']];
        yield 'search words find setting names' => [['q' => 'brand name'], ['branding_saved']];
        yield 'search the user' => [['q' => 'command line'], ['website_created']];
        yield 'search the subject' => [['q' => 'events_v1'], ['bigquery_sync']];
        yield 'wildcards are literal' => [['q' => '100%_'], ['bigquery_sync']];
        yield 'a percent sign alone matches only itself' => [['q' => '%'], ['bigquery_sync']];
        yield 'an underscore alone matches only itself' => [['q' => '_', 'area' => ['updates']], ['update_started']];
        yield 'a job run' => [['task' => '41'], ['bigquery_sync']];
        yield 'every filter must match' => [['area' => ['settings'], 'outcome' => ['failed'], 'q' => 'aggregate.yaml'], ['feature_flags_saved']];
        yield 'nothing matches' => [['area' => ['sign_ins']], []];
    }

    public function testPagesAreCountedAndAPagePastTheLastShowsTheLast(): void
    {
        $result = $this->log->search(AuditTrailFilter::fromQuery(['per_page' => '25']));
        self::assertSame([7, 1, 1], [$result['total'], $result['page'], $result['pages']]);

        $page = $this->log->search(new AuditTrailFilter(pageSize: 3, page: 2));
        self::assertSame(['bigquery_sync', 'password_changed', 'user_created'], array_column($page['entries'], 'action'));
        self::assertSame([7, 2, 3], [$page['total'], $page['page'], $page['pages']]);

        $beyond = $this->log->search(new AuditTrailFilter(pageSize: 3, page: 99));
        self::assertSame(['branding_saved'], array_column($beyond['entries'], 'action'));
        self::assertSame(3, $beyond['page']);

        $empty = $this->log->search(new AuditTrailFilter(areas: ['sign_ins'], page: 4));
        self::assertSame([0, 1, 1], [$empty['total'], $empty['page'], $empty['pages']]);
    }

    public function testEntriesAreReadAsUtcWithTheirFields(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/Chicago');
        try {
            $entry = $this->log->search(new AuditTrailFilter(task: 41))['entries'][0];
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertSame('2026-10-03 00:00:00 UTC', $entry['occurred_at']->format('Y-m-d H:i:s T'));
        self::assertSame(['task', 'bigquery_sync', 'failed', 'bi_anonymous_events_v1', null, 41], [$entry['area'], $entry['action'], $entry['outcome'], $entry['subject'], $entry['user'], $entry['task']]);
        self::assertIsInt($entry['id']);
    }

    public function testAnExportReadsEveryMatchingEntryInBatchesWithoutRepeats(): void
    {
        // Many entries share a time, so batches must continue by time and id.
        $this->connection->beginTransaction();
        $time = new \DateTimeImmutable('2026-10-07 10:00:00 UTC');
        for ($number = 0; $number < 2150; ++$number) {
            $this->trail->record('operations', 'bigquery_sync_started', 'succeeded', $time->modify('+'.intdiv($number, 700).' seconds'), 'run '.$number, 'scott');
        }
        $this->connection->commit();

        $newest = iterator_to_array($this->log->each(new AuditTrailFilter(areas: ['operations'])), false);
        self::assertCount(2150, $newest);
        self::assertCount(2150, array_unique(array_column($newest, 'id')));
        self::assertSame('run 2149', $newest[0]['subject']);
        self::assertSame('run 0', $newest[2149]['subject']);

        $oldest = iterator_to_array($this->log->each(new AuditTrailFilter(areas: ['operations'], oldestFirst: true), 1500), false);
        self::assertCount(1500, $oldest);
        self::assertSame('run 0', $oldest[0]['subject']);
        self::assertSame('run 1499', $oldest[1499]['subject']);
        self::assertSame([], iterator_to_array($this->log->each(new AuditTrailFilter(), 0), false));
    }

    public function testChoicesAndSummaryDescribeWhatWasRecorded(): void
    {
        $choices = $this->log->choices();
        self::assertSame(['settings', 'task', 'updates', 'users', 'websites'], $choices['areas']);
        self::assertSame(['bigquery_sync', 'branding_saved', 'feature_flags_saved', 'password_changed', 'update_started', 'user_created', 'website_created'], $choices['actions']);
        self::assertSame(['denied', 'failed', 'started', 'succeeded'], $choices['outcomes']);
        self::assertSame(['Scott', 'ana', 'root (command line)', 'scott'], $choices['users']);
        self::assertTrue($choices['without_user']);

        $summary = $this->log->summary();
        self::assertSame(2, $summary['settings']['count']);
        self::assertSame('2026-10-01 08:00:00', $summary['settings']['oldest']->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-04 12:00:00', $summary['settings']['newest']->format('Y-m-d H:i:s'));
        self::assertArrayNotHasKey('sign_ins', $summary);
    }

    public function testJobRunsAndOtherEntriesArePurgedSeparately(): void
    {
        $cutoff = new \DateTimeImmutable('2026-10-05 00:00:00 UTC');
        self::assertSame([5, 1, 4], [$this->trail->countBefore($cutoff), $this->trail->countBefore($cutoff, true), $this->trail->countBefore($cutoff, false)]);

        self::assertSame(1, $this->trail->purgeBefore($cutoff, 100, true));
        self::assertSame(['update_started', 'website_created', 'feature_flags_saved', 'password_changed', 'user_created', 'branding_saved'], array_column($this->log->search(new AuditTrailFilter())['entries'], 'action'));
        self::assertSame(4, $this->trail->purgeBefore($cutoff, 2, false), 'in batches');
        self::assertSame(['update_started', 'website_created'], array_column($this->log->search(new AuditTrailFilter())['entries'], 'action'));
        self::assertSame(2, $this->trail->purgeBefore(new \DateTimeImmutable('2027-01-01 UTC'), 100));
    }
}
