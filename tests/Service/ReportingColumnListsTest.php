<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Service\AnonymousBiViewManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The dashboard's data structure page and the BI connection guide each list
 * the columns of the approved views and the private events table. Both must
 * match what the views and the entity actually have; view columns also in
 * their order, which BI connections see.
 */
final class ReportingColumnListsTest extends TestCase
{
    private const PAGE = __DIR__.'/../../templates/home/data_structure.html.twig';
    private const GUIDE = __DIR__.'/../../docs/BI-CONNECTION.md';

    public function testEachApprovedViewIsListedWithItsColumnsOnThePageAndInTheGuide(): void
    {
        $views = $this->viewColumns();
        $page = $this->pageColumns();
        $guide = $this->guideColumns();

        foreach (AnonymousBiViewManager::VIEW_NAMES as $view) {
            self::assertNotSame([], $views[$view], $view);
            self::assertSame($views[$view], $page[$view] ?? null, 'Columns exposed by '.$view.' on '.basename(self::PAGE));
            self::assertSame($views[$view], $guide[$view] ?? null, $view.' in '.basename(self::GUIDE));
        }
    }

    public function testThePrivateEventsTableIsListedWithItsColumns(): void
    {
        $columns = $this->eventColumns();
        $page = (new Crawler((string) file_get_contents(self::PAGE)))->filter('[aria-labelledby="events-table-heading"] tbody th[scope="row"] code');

        // Grouped for reading (both modes, then enhanced only), so compared as sets.
        $sorted = static function (?array $names): ?array {
            if ($names !== null) {
                sort($names);
            }

            return $names;
        };
        self::assertSame($sorted($columns), $sorted($page->each(static fn (Crawler $code): string => $code->text())), basename(self::PAGE));
        self::assertSame($sorted($columns), $sorted($this->guideColumns()['events'] ?? null), basename(self::GUIDE));
    }

    /** @return array<string, list<string>> */
    private function viewColumns(): array
    {
        $db = $this->database();
        (new AnonymousBiViewManager($db))->regenerate(5, 25);
        $columns = [];
        foreach (AnonymousBiViewManager::VIEW_NAMES as $view) {
            $columns[$view] = array_column($db->fetchAllAssociative('PRAGMA table_info('.$view.')'), 'name');
        }

        return $columns;
    }

    /** @return list<string> */
    private function eventColumns(): array
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));
        if (\PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('AggregateTestProxies');
        }
        $manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);

        return array_values($manager->getClassMetadata(Event::class)->getColumnNames());
    }

    /** @return array<string, list<string>> view name => listed columns, from each "Columns exposed by" table */
    private function pageColumns(): array
    {
        $columns = [];
        (new Crawler((string) file_get_contents(self::PAGE)))->filter('table')->each(static function (Crawler $table) use (&$columns): void {
            $caption = $table->filter('caption');
            if ($caption->count() === 1 && preg_match('/^Columns exposed by (\w+)$/', trim($caption->text()), $match) === 1) {
                $columns[$match[1]] = $table->filter('tbody th[scope="row"] code')->each(static fn (Crawler $code): string => $code->text());
            }
        });

        return $columns;
    }

    /** @return array<string, list<string>> each "### `name`" section's first table, by the first column of its rows */
    private function guideColumns(): array
    {
        $columns = [];
        $section = null;
        foreach (file(self::GUIDE, \FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^#{2,3} /', $line) === 1) {
                $section = preg_match('/^### `(\w+)`$/', $line, $match) === 1 ? $match[1] : (str_contains($line, 'Private source tables') ? 'events' : null);
            } elseif ($section !== null && preg_match('/^\| `(\w+)` \|/', $line, $match) === 1) {
                $columns[$section][] = $match[1];
            } elseif ($section !== null && isset($columns[$section]) && !str_starts_with($line, '|')) {
                // The first table ends the section's list.
                $section = null;
            }
        }

        return $columns;
    }

    private function database(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE events (id INTEGER PRIMARY KEY, website_token TEXT, created_at TEXT, event_name TEXT, url TEXT, referrer TEXT, device_class TEXT, viewport_bucket TEXT, privacy_mode TEXT, archived_at TEXT, goal_event TEXT, geo_area TEXT)');
        $db->executeStatement('CREATE TABLE analytics_archive_events (website_token TEXT, event_hour TEXT, event_name TEXT, page_path TEXT, referrer_channel TEXT, device_class TEXT, viewport_bucket TEXT, privacy_mode TEXT, event_count INTEGER)');
        $db->executeStatement('CREATE TABLE analytics_archive_goals (website_token TEXT, event_day TEXT, goal_event TEXT, privacy_mode TEXT, event_count INTEGER)');
        $db->executeStatement('CREATE TABLE analytics_archive_geo_events (website_token TEXT, event_day TEXT, event_name TEXT, geo_area TEXT, privacy_mode TEXT, event_count INTEGER)');

        return $db;
    }
}
