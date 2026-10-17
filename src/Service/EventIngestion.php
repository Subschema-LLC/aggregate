<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\TrackEventMessage;
use App\Security\IpRateLimiter;
use App\Service\GeoIp\ConfiguredGeoIpResolver;
use App\Service\GeoIp\GeoIpResolverInterface;
use App\Service\GeoIp\MmdbGeoCodeLookupInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Accepts one event sent to /api/receive and records it, or says why not.
 *
 * The checks run in this order and the first one that fails ends the request
 * (docs/ARCHITECTURE.md, "The server checks it"):
 *
 *  1. the request body: size and JSON;
 *  2. the website's own settings, when its group or website overrides them;
 *  3. the collection switch and excluded paths;
 *  4. bot traffic, when it is dropped;
 *  5. the rate limit;
 *  6. the website token and the request's origin;
 *  7. the page path and event name;
 *  then record() reduces the event to what its privacy mode keeps, and
 *  records it anonymously at once or queues it as an enhanced event.
 *
 * Steps 2 to 4 run before a rate-limit bucket, a website lookup or a
 * geography lookup, so requests that collect nothing leave nothing behind.
 * Nothing is kept on the object between requests: under a worker runtime one
 * instance serves many of them.
 */
final class EventIngestion
{
    public const MAX_BODY_BYTES = 65_536;

    public function __construct(
        private readonly WebsiteConfigManager $websites,
        private readonly MessageBusInterface $bus,
        private readonly IpRateLimiter $rateLimiter,
        private readonly PrivacySanitizer $sanitizer,
        private readonly GoalEventRegistry $goalEvents,
        private readonly PrivacyPolicy $privacyPolicy,
        private readonly GeoIpResolverInterface $geoIpResolver,
        private readonly AnonymousEventRecorder $anonymousRecorder,
        private readonly LoggerInterface $logger,
        private readonly CustomDataSettings $customDataSettings,
        private readonly ?TrackingFailureRetryRunner $trackingFailures = null,
        private readonly ?WebsiteScopeResolver $scopes = null,
        private readonly ?MmdbGeoCodeLookupInterface $geoLookup = null,
        private readonly WebsiteDomainPolicy $domainPolicy = new WebsiteDomainPolicy(),
    ) {
    }

    public function receive(Request $request): IngestionResult
    {
        // What a failure report can say: how far the event got.
        $failure = ['mode' => 'unknown', 'websiteToken' => null];
        try {
            return $this->ingest($request, $failure);
        } catch (\Throwable $e) {
            $this->reportFailure($e, $failure['mode'], $failure['websiteToken']);

            return IngestionResult::error('Ingestion failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @param array{mode: string, websiteToken: ?string} $failure */
    private function ingest(Request $request, array &$failure): IngestionResult
    {
        // 1. The request body.
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return IngestionResult::error('Payload is too large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $payload = $this->payload($request);
        if ($payload === null) {
            return IngestionResult::error('Invalid JSON payload', Response::HTTP_BAD_REQUEST);
        }

        // 2. The website's own settings.
        $settings = $this->settingsFor($payload);
        if ($settings === null) {
            return IngestionResult::ignored();
        }
        [$privacyPolicy, $customDataSettings, $geoIpResolver] = $settings;

        // 3. Despite its legacy name, this is the ingestion kill switch for
        // the website's effective settings. Check it before deriving any
        // request-based bucket.
        if (!$privacyPolicy->isAnonymousTrackingEnabled()) {
            return IngestionResult::ignored();
        }

        // New clients send pagePath. url remains a compatibility input,
        // but its host, query and fragment are always discarded.
        $pagePath = $this->sanitizer->sanitizePagePath($payload['pagePath'] ?? $payload['url'] ?? null);

        // Exclusions are a server-side kill switch for sensitive routes,
        // including when a client claims enhanced consent. Valid excluded
        // paths are ignored before a local IP rate-limit bucket is made.
        if ($pagePath !== null && $privacyPolicy->isExcludedPath($pagePath)) {
            return IngestionResult::ignored();
        }

        // 4. Bots are classified from the User-Agent header and the tracker's
        // automation report while this request is handled; neither is
        // stored. The strict profile reads no User-Agent and classifies
        // nothing. With bot_traffic: drop, bot events end here, before an
        // IP bucket, website lookup, geography lookup or queue message.
        $strictCollection = $privacyPolicy->isStrictCollection();
        $userAgent = $strictCollection ? '' : (string) $request->headers->get('User-Agent', '');
        $isBot = !$strictCollection && $privacyPolicy->isBotRequest($userAgent, $payload['deviceClass'] ?? null);
        if ($isBot && $privacyPolicy->botTraffic() === BotTrafficSettings::DROP) {
            return IngestionResult::ignored();
        }

        // 5. Apply the rate limiter only after the zero-collection controls.
        // Excluded/disabled requests therefore create no local IP bucket.
        // Other valid and invalid requests use a short-lived rotating HMAC
        // bucket; the raw address never enters Messenger or analytics.
        $ip = $request->getClientIp() ?? '0.0.0.0';
        if (!$this->rateLimiter->allow($ip)) {
            return IngestionResult::error('Too Many Requests', Response::HTTP_TOO_MANY_REQUESTS);
        }

        // 6. The website, by its public token, and the request's origin.
        $websiteToken = is_string($payload['websiteToken'] ?? null) ? trim($payload['websiteToken']) : '';
        if ($websiteToken === '' || strlen($websiteToken) > 191) {
            return IngestionResult::error('websiteToken is required', Response::HTTP_BAD_REQUEST);
        }
        $failure['websiteToken'] = $websiteToken;
        $website = $this->websites->findOneByToken($websiteToken);
        if (!$website) {
            return IngestionResult::error('Invalid websiteToken', Response::HTTP_BAD_REQUEST);
        }
        if (!$this->originAllowed($request, $website)) {
            return IngestionResult::error('Forbidden origin', Response::HTTP_FORBIDDEN);
        }

        // 7. What every event needs.
        if ($pagePath === null) {
            return IngestionResult::error('pagePath is required', Response::HTTP_BAD_REQUEST);
        }
        $eventName = $this->sanitizer->sanitizeEventName($payload['eventName'] ?? null);
        if ($eventName === null) {
            return IngestionResult::error('eventName is invalid', Response::HTTP_BAD_REQUEST);
        }

        return $this->record($payload, [
            'website' => $website, 'websiteToken' => $websiteToken, 'pagePath' => $pagePath, 'eventName' => $eventName,
            'strictCollection' => $strictCollection, 'isBot' => $isBot, 'userAgent' => $userAgent, 'ip' => $ip,
            'privacyPolicy' => $privacyPolicy, 'customDataSettings' => $customDataSettings, 'geoIpResolver' => $geoIpResolver,
        ], $failure);
    }

    /**
     * Reduces an accepted event to what its privacy mode keeps, and records it
     * anonymously at once or queues it as an enhanced event.
     *
     * @param array<string, mixed> $payload
     * @param array{website: array<string, mixed>, websiteToken: string, pagePath: string, eventName: string, strictCollection: bool, isBot: bool, userAgent: string, ip: string, privacyPolicy: PrivacyPolicy, customDataSettings: CustomDataSettings, geoIpResolver: GeoIpResolverInterface} $accepted
     * @param array{mode: string, websiteToken: ?string} $failure
     */
    private function record(array $payload, array $accepted, array &$failure): IngestionResult
    {
        [
            'website' => $website, 'websiteToken' => $websiteToken, 'pagePath' => $pagePath, 'eventName' => $eventName,
            'strictCollection' => $strictCollection, 'isBot' => $isBot, 'userAgent' => $userAgent, 'ip' => $ip,
            'privacyPolicy' => $privacyPolicy, 'customDataSettings' => $customDataSettings, 'geoIpResolver' => $geoIpResolver,
        ] = $accepted;
        unset($accepted);

        // The strict profile keeps only what reporting a page view or named
        // event requires. Every other dimension a client submits is ignored,
        // including values sent by stale scripts or direct API requests.
        $referrerChannel = $strictCollection ? 'unknown' : $this->sanitizer->sanitizeReferrerChannel(
            $payload['referrerChannel'] ?? null,
            $payload['referrer'] ?? null,
            (string) $website['domain'],
        );
        $enhancedConsent = !$strictCollection && $privacyPolicy->hasEnhancedConsent($payload['consentState'] ?? null);
        // Organization traffic: only the literal boolean true counts, never a
        // marker name or value or a truthy string. Every standard-profile
        // event records true or false; the strict profile reads no browser
        // storage, so its events carry no value at all.
        $internalTraffic = $strictCollection ? null : ($payload[InternalTrafficSettings::JSON_KEY] ?? false) === true;
        // Resolve the deployment's property policy before either storage
        // path. Client-side consent flags never authorize custom keys.
        // customData is the payload's property object; eventData is its
        // earlier name, read only when customData is absent.
        $eventData = $strictCollection
            ? null
            : $customDataSettings->filterEventData(CustomDataSettings::submittedProperties($payload), $enhancedConsent);
        if ($eventData !== null) {
            unset($eventData[InternalTrafficSettings::JSON_KEY]);
        }
        $submittedGoal = $payload['goalEvent'] ?? null;
        $goalEvent = $this->goalEvents->resolve($submittedGoal, anonymousMode: !$enhancedConsent);
        $goalWasRejected = $this->goalEvents->wasSubmitted($submittedGoal) && $goalEvent === null;
        unset($submittedGoal);
        // The server's bot classification wins over the device class a
        // client submits, so a page cannot relabel a known bot.
        $deviceClass = match (true) {
            $strictCollection => 'unknown',
            $isBot => 'bot',
            default => $this->sanitizer->sanitizeDeviceClass($payload['deviceClass'] ?? null, $userAgent),
        };
        $viewportBucket = $strictCollection ? 'unknown' : $this->sanitizer->sanitizeViewportBucket(
            $payload['viewportBucket'] ?? null,
            $payload['screenWidth'] ?? null,
        );
        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // The resolver returns only a canonical country/continent code. It
        // performs no network calls and never exposes the source address or
        // detailed MMDB record to events, Messenger or logs. The strict
        // profile performs no lookup at all.
        $geoArea = $strictCollection ? null : $geoIpResolver->resolve($ip)?->value();
        unset($ip);

        if (!$enhancedConsent) {
            $failure['mode'] = 'anonymous';
            // Every safe named event is accepted anonymously, but attached
            // identifiers are never copied. Properties and goals must be
            // explicitly approved for collection without consent.
            $this->anonymousRecorder->record(
                websiteToken: $websiteToken,
                eventName: $eventName,
                pagePath: $pagePath,
                referrerChannel: $referrerChannel,
                deviceClass: $deviceClass,
                viewportBucket: $viewportBucket,
                geoArea: $geoArea,
                occurredAt: $occurredAt,
                goalEvent: $goalEvent,
                internalTraffic: $internalTraffic,
                approvedCustomData: $eventData,
            );

            return IngestionResult::recorded('anonymous', $goalWasRejected);
        }

        $failure['mode'] = 'enhanced';
        $this->bus->dispatch(new TrackEventMessage(
            websiteToken: $websiteToken,
            eventName: $eventName,
            pagePath: $pagePath,
            referrerChannel: $referrerChannel,
            deviceClass: $deviceClass,
            viewportBucket: $viewportBucket,
            screenWidth: $this->sanitizer->sanitizeScreenWidth($payload['screenWidth'] ?? null),
            goalEvent: $goalEvent,
            eventData: $eventData ?: null,
            generalizedUserAgent: $this->sanitizer->generalizeUserAgent($userAgent),
            visitorId: $this->sanitizer->sanitizeIdentifier($payload['visitorId'] ?? null),
            sessionId: $this->sanitizer->sanitizeIdentifier($payload['sessionId'] ?? null),
            occurredAt: $occurredAt,
            geoArea: $geoArea,
            internalTraffic: $internalTraffic,
        ));

        return IngestionResult::recorded('enhanced', $goalWasRejected);
    }

    /** @return array<string, mixed>|null the decoded JSON object, or null when the body is not one */
    private function payload(Request $request): ?array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The settings this event is collected under. A website or group file
     * replaces the deployment settings for this token; an unusable scope
     * collects nothing (null), before a rate bucket, website lookup or
     * geography lookup.
     *
     * @param array<string, mixed> $payload
     * @return array{0: PrivacyPolicy, 1: CustomDataSettings, 2: GeoIpResolverInterface}|null
     */
    private function settingsFor(array $payload): ?array
    {
        $settings = [$this->privacyPolicy, $this->customDataSettings, $this->geoIpResolver];
        $websiteToken = is_string($payload['websiteToken'] ?? null) ? trim($payload['websiteToken']) : '';
        if ($this->scopes === null || $websiteToken === '' || strlen($websiteToken) > 191 || preg_match('/\s/', $websiteToken) === 1) {
            return $settings;
        }
        $scoped = $this->scopes->forToken($websiteToken);
        if ($scoped->hasLoadError()) {
            return null;
        }

        return [
            new PrivacyPolicy($scoped),
            new CustomDataSettings($scoped),
            $this->geoLookup !== null ? new ConfiguredGeoIpResolver($scoped, $this->geoLookup) : $this->geoIpResolver,
        ];
    }

    /**
     * Use the saved policy for both privacy modes. Only a missing Origin
     * permits Referer fallback; payload fields cannot grant access. Repeated
     * headers are invalid instead of selecting their first value.
     *
     * @param array<string, mixed> $website
     */
    private function originAllowed(Request $request, array $website): bool
    {
        $origins = $request->headers->all('Origin');
        $referers = $request->headers->all('Referer');

        return $this->domainPolicy->allows(
            $website,
            count($origins) > 1 ? '' : ($origins[0] ?? null),
            count($referers) > 1 ? '' : ($referers[0] ?? null),
        );
    }

    private function reportFailure(\Throwable $e, string $mode, ?string $websiteToken): void
    {
        $this->logger->error('Failed to ingest analytics event', [
            // Do not hand the exception or request metadata to a logger:
            // transport/SQL exceptions can retain payload values.
            'exception_class' => $e::class,
        ]);
        if ($this->trackingFailures === null) {
            return;
        }
        try {
            $this->trackingFailures->recordIngestionFailure($mode, $websiteToken, $e);
        } catch (\Throwable $recordingError) {
            $this->logger->error('Failed to record tracking ingestion failure.', [
                'exception_class' => $recordingError::class,
            ]);
        }
    }
}
