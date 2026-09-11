<?php

namespace App\MessageHandler;

use App\Entity\Event;
use App\Message\TrackEventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TrackEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(TrackEventMessage $msg): void
    {
        $event = (new Event())
            ->setWebsiteToken($msg->websiteToken)
            ->setPrivacyMode('enhanced')
            ->setEventName($msg->eventName)
            ->setUrl($msg->pagePath)
            ->setReferrer($msg->referrerChannel)
            ->setDeviceClass($msg->deviceClass)
            ->setViewportBucket($msg->viewportBucket)
            ->setGeoArea($msg->geoArea ?? null)
            ->setGeneralizedUserAgent($msg->generalizedUserAgent)
            ->setScreenWidth($msg->screenWidth)
            ->setVisitorId($msg->visitorId)
            ->setSessionId($msg->sessionId)
            ->setConsentState('granted')
            ->setGoalEvent($msg->goalEvent)
            ->setCustomData($msg->eventData)
            ->setInternalTraffic($msg->internalTraffic ?? false, $msg->internalTrafficName ?? 'orgInternalTraffic')
            ->setCreatedAt($msg->occurredAt);

        $this->em->persist($event);
        $this->em->flush();
    }
}
