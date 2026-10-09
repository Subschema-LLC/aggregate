<?php

namespace App\MessageHandler;

use App\Entity\Event;
use App\Message\TrackEventMessage;
use App\Service\TrackingFailureRetryRunner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TrackEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TrackingFailureRetryRunner $trackingFailures,
        private readonly LoggerInterface $logger,
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
            ->setInternalTraffic($msg->internalTraffic)
            ->setCreatedAt($msg->occurredAt);

        try {
            $this->em->persist($event);
            $this->em->flush();
        } catch (\Throwable $error) {
            $this->logger->error('Enhanced event persistence failed.', ['exception_class' => $error::class]);
            try {
                $this->trackingFailures->recordFailure($msg, $error);
            } catch (\Throwable $recordingError) {
                $this->logger->error('Tracking failure recording failed.', ['exception_class' => $recordingError::class]);
            }
            throw $error;
        }
    }
}
