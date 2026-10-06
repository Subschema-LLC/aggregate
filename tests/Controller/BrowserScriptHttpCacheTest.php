<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The full application keeps the five-minute cache and the short 304 reply. */
final class BrowserScriptHttpCacheTest extends WebTestCase
{
    public function testTheServedTrackerIsReusedThenConfirmedUnchanged(): void
    {
        $client = self::createClient();
        $client->request('GET', '/aggregate.js?min=1');
        $response = $client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
        self::assertContains($response->headers->get('X-Aggregate-Script'), ['minified', 'compact'], 'never the full source for ?min=1');
        $etag = (string) $response->getEtag();
        self::assertNotSame('', $etag);

        $client->request('GET', '/aggregate.js?min=1', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        self::assertSame('', (string) $client->getResponse()->getContent());
    }
}
