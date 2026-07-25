<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;

/** Persists one identifier-free, hour-bucketed anonymous event synchronously. */
final class AnonymousEventRecorder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
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
            ->setGoalEvent(null)
            ->setCustomData(null)
            ->setCreatedAt($hourBucket);

        $this->entityManager->persist($event);
        $this->entityManager->flush();
    }
}
