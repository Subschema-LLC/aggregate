<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HowItWorksControllerTest extends WebTestCase
{
    public function testOverviewPageIsPubliclyAvailable(): void
    {
        $client = self::createClient();

        $client->request('GET', '/how-it-works');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'How Aggregate Analytics Works');
        self::assertSelectorTextContains('body', 'Anonymous mode');
        self::assertSelectorTextContains('body', 'Enhanced analytics');
        self::assertSelectorExists('a[href="/how-it-works/data-visualization"]');
        self::assertSelectorExists('a[href="/"]');
    }

    public function testDataVisualizationStructurePageIsPubliclyAvailable(): void
    {
        $client = self::createClient();

        $client->request('GET', '/how-it-works/data-visualization');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Data Structure for Visualization');
        self::assertSelectorTextContains('body', 'bi_anonymous_events_v1');
        self::assertSelectorTextContains('body', 'bi_anonymous_geo_events_v1');
        self::assertSelectorExists('#anonymous-events-view table');
        self::assertSelectorTextContains('#anonymous-events-view table', 'event_hour');
        self::assertSelectorTextContains('#anonymous-events-view table', 'event_count');
        self::assertSelectorExists('#anonymous-geography-view table');
        self::assertSelectorTextContains('#anonymous-geography-view table', 'event_day');
        self::assertSelectorExists('#private-source-tables table');
        self::assertSelectorTextContains('#private-source-tables', 'not the routine BI contract');
        self::assertSelectorExists('a[href="/how-it-works"]');
    }
}
