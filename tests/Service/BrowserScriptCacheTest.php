<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserScriptCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BrowserScriptCacheTest extends TestCase
{
    public function testScriptsAreReusedForFiveMinutesThenConfirmedWithAShortReply(): void
    {
        $first = BrowserScriptCache::apply(new Response('window.version = 1;', headers: ['Cache-Control' => 'no-cache']), Request::create('/lib.js'));
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('max-age=300, public', $first->headers->get('Cache-Control'));
        $etag = (string) $first->getEtag();
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);

        $request = Request::create('/lib.js', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        $unchanged = BrowserScriptCache::apply(new Response('window.version = 1;'), $request);
        self::assertSame(304, $unchanged->getStatusCode());
        self::assertSame('', (string) $unchanged->getContent());

        $changed = BrowserScriptCache::apply(new Response('window.version = 2;'), $request);
        self::assertSame(200, $changed->getStatusCode(), 'saved changes are sent in full');
        self::assertSame('window.version = 2;', $changed->getContent());
        self::assertNotSame($etag, $changed->getEtag());
    }

    public function testFailuresAreNeverCached(): void
    {
        $failure = BrowserScriptCache::apply(new Response('/* unavailable */', 503, ['Cache-Control' => 'no-store']), Request::create('/lib.js'));
        self::assertSame(503, $failure->getStatusCode());
        self::assertTrue($failure->headers->hasCacheControlDirective('no-store'));
        self::assertNull($failure->getEtag());
    }
}
