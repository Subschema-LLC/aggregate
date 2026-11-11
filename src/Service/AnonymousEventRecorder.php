<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;

/** Writes one identifier-free, hour-bucketed anonymous event during the request. */
final class AnonymousEventRecorder
{
    public function __construct(private readonly EventRowWriter $writer)
    {
    }

    public function record(
        string $websiteToken,
        string $eventName,
        string $pagePath,
        string $referrerChannel,
        string $deviceClass,
        string $viewportBucket,
        ?\DateTimeImmutable $occurredAt = null,
        ?string $geoArea = null,
        ?string $goalEvent = null,
        ?bool $internalTraffic = null,
        ?array $approvedCustomData = null,
    ): void {
        $occurredAt = ($occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'));
        $hourBucket = $occurredAt->setTime((int) $occurredAt->format('G'), 0, 0);

        $event = (new Event())
            ->setWebsiteToken($websiteToken)
            ->setPrivacyMode('anonymous')
            ->setEventName($eventName)
            ->setUrl($pagePath)
            ->setReferrer($referrerChannel)
            ->setDeviceClass($deviceClass)
            ->setViewportBucket($viewportBucket)
            ->setGeoArea($geoArea)
            ->setGeneralizedUserAgent(null)
            ->setScreenWidth(null)
            ->setVisitorId(null)
            ->setSessionId(null)
            ->setConsentState(null)
            ->setGoalEvent($goalEvent)
            ->setApprovedAnonymousCustomData($approvedCustomData)
            ->setInternalTraffic($internalTraffic)
            ->setCreatedAt($hourBucket);

        $this->writer->insert($event);
    }
}
