<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caching for the tracker, tag manager and consent scripts served to visitors.
 * Browsers and CDNs may reuse a script for five minutes without asking; after
 * that, an unchanged script is confirmed with a short 304 instead of being
 * downloaded again. Saved settings therefore reach a returning visitor within
 * five minutes, and a new visitor at once.
 */
final class BrowserScriptCache
{
    public const MAX_AGE = 300;

    /** Adds the cache headers and, when the browser already has this script, turns the response into a 304. */
    public static function apply(Response $response, ?Request $request = null): Response
    {
        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }
        $response->headers->remove('Cache-Control');
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->setEtag(substr(hash('sha256', (string) $response->getContent()), 0, 32));
        if ($request === null || !$request->isMethodCacheable()) {
            return $response;
        }
        // A compressing web server changes the tag the browser keeps: nginx
        // makes it weak (W/"…") and Apache adds -gzip or -br inside the quotes.
        // Either still names this content, so it is confirmed with a 304.
        foreach ($request->getETags() as $tag) {
            $tag = preg_replace('~-(?:gzip|br|zstd|deflate)"$~', '"', str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag);
            if ($tag === $response->getEtag() || $tag === '*') {
                $response->setNotModified();
                break;
            }
        }

        return $response;
    }
}
