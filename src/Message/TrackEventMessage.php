<?php

namespace App\Message;

/**
 * An enhanced event waiting in the queue for a worker.
 *
 * Several servers on different releases share one queue during an update, so
 * a message written by one release may be read by another. Its fields
 * therefore only ever grow: a new field comes last, with a default, and is
 * never renamed, retyped or removed (MessageContractTest checks this against
 * tests/Fixtures/message-contracts.json). PHP's serializer skips the
 * constructor, so __wakeup() gives a field an older release did not write
 * its default.
 */
class TrackEventMessage
{
    public function __construct(
        public string $websiteToken,
        public string $eventName,
        public string $pagePath,
        public string $referrerChannel,
        public string $deviceClass,
        public string $viewportBucket,
        public ?int $screenWidth,
        public ?string $goalEvent,
        public ?array $eventData,
        public string $generalizedUserAgent,
        public ?string $visitorId,
        public ?string $sessionId,
        public \DateTimeImmutable $occurredAt,
        public ?string $geoArea = null,
        public ?bool $internalTraffic = null,
    ) {}

    public function __wakeup(): void
    {
        foreach ((new \ReflectionMethod($this, '__construct'))->getParameters() as $parameter) {
            $property = new \ReflectionProperty($this, $parameter->getName());
            if (!$property->isInitialized($this) && $parameter->isDefaultValueAvailable()) {
                $property->setValue($this, $parameter->getDefaultValue());
            }
        }
    }
}
