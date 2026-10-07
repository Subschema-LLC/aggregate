<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ReceiveController;
use App\Entity\Event;
use App\Message\TrackEventMessage;
use App\MessageHandler\TrackEventHandler;
use App\Security\IpRateLimiter;
use App\Service\AggregateConfigLoader;
use App\Service\AnonymousEventRecorder;
use App\Service\CustomDataSettings;
use App\Service\GeoIp\GeoIpResolverInterface;
use App\Service\GoalEventRegistry;
use App\Service\InternalTrafficSettings;
use App\Service\PrivacyPolicy;
use App\Service\PrivacySanitizer;
use App\Service\WebsiteConfigManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReceiveControllerTypedDataTest extends TestCase
{
    #[DataProvider('consentStates')]
    public function testDirectJsonRequestsEnforceTypesBeforeQueueingOrPersistence(mixed $consent, bool $enhanced): void
    {
        $properties = [
            'product_name' => ['type' => 'string', 'consent_required' => false],
            'quantity' => ['type' => 'integer', 'consent_required' => false],
            'unit_price' => ['type' => 'float', 'consent_required' => false],
            'revenue' => ['type' => 'double', 'consent_required' => true],
            'currency' => ['type' => 'string', 'consent_required' => false],
            'active' => ['type' => 'boolean', 'consent_required' => false],
            'nullable' => ['type' => 'integer', 'consent_required' => false],
            'fraction' => ['type' => 'integer', 'consent_required' => false],
            'unsafe_integer' => ['type' => 'integer', 'consent_required' => false],
            'numeric_text' => ['type' => 'double', 'consent_required' => false],
            'boolean_text' => ['type' => 'boolean', 'consent_required' => false],
            'boolean_number' => ['type' => 'float', 'consent_required' => false],
            // A reporting column for the flag does not let a request set it.
            InternalTrafficSettings::JSON_KEY => ['type' => 'boolean', 'consent_required' => false, 'column' => 'staff'],
        ];
        $payload = [
            'websiteToken' => 'example-token',
            'eventName' => 'purchase_completed',
            'pagePath' => '/shop/confirmation?order=private',
            'consentState' => $consent,
            'visitorId' => 'synthetic-visitor',
            'sessionId' => 'synthetic-session',
            'screenWidth' => 1440,
            InternalTrafficSettings::JSON_KEY => true,
            // Forged browser rules cannot expand the saved deployment policy.
            'customData' => ['propertyTypes' => [], 'consentFreeProperties' => ['revenue']],
            'customData' => [
                'product_name' => "Demo product\0",
                'quantity' => 2.0,
                'unit_price' => 12.5,
                'revenue' => 25.0,
                'currency' => 'USD',
                'active' => false,
                'nullable' => null,
                'fraction' => 2.5,
                'unsafe_integer' => 9_007_199_254_740_992,
                'numeric_text' => '12.5',
                'boolean_text' => 'true',
                'boolean_number' => true,
                InternalTrafficSettings::JSON_KEY => false,
            ],
        ];
        $expected = [
            'product_name' => 'Demo product', 'quantity' => 2, 'unit_price' => 12.5,
            ...($enhanced ? ['revenue' => 25.0] : []),
            'currency' => 'USD', 'active' => false, 'nullable' => null,
        ];
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $event) use (&$persisted, $enhanced): void {
            self::assertInstanceOf(Event::class, $event);
            if (!$enhanced) {
                // Later entity assignments cannot replace approved anonymous
                // values with wrongly typed or unapproved properties.
                $event->setCustomData(['quantity' => 'private-text', 'revenue' => 999, InternalTrafficSettings::JSON_KEY => false]);
            }
            $event->enforcePrivacyInvariants();
            $persisted = $event;
        });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        if ($enhanced) {
            $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use ($entityManager, $expected): Envelope {
                self::assertInstanceOf(TrackEventMessage::class, $message);
                self::assertSame($expected, $message->eventData);
                $restored = unserialize(serialize($message));
                (new TrackEventHandler($entityManager))($restored);

                return new Envelope($message);
            });
        } else {
            $bus->expects(self::never())->method('dispatch');
        }

        $response = $this->ingest($payload, $properties, $entityManager, $bus);

        self::assertSame(202, $response->getStatusCode());
        self::assertInstanceOf(Event::class, $persisted);
        self::assertSame([...$expected, InternalTrafficSettings::JSON_KEY => true], $persisted->getCustomData());
        self::assertTrue($persisted->isInternalTraffic());
        self::assertSame('/shop/confirmation', $persisted->getUrl());
        self::assertSame($enhanced ? 'enhanced' : 'anonymous', $persisted->getPrivacyMode());
        self::assertSame($enhanced ? 'granted' : null, $persisted->getConsentState());
        if (!$enhanced) {
            self::assertNull($persisted->getVisitorId());
            self::assertNull($persisted->getSessionId());
            self::assertNull($persisted->getScreenWidth());
            self::assertSame('00:00.000000', $persisted->getCreatedAt()->format('i:s.u'));
        }
    }

    public static function consentStates(): iterable
    {
        yield 'explicit enhanced consent' => ['granted', true];
        yield 'explicit rejection' => ['denied', false];
        yield 'false string is not consent' => ['false', false];
        yield 'unknown consent' => [null, false];
    }

    public function testInvalidSavedTypeFailsClosedEvenWithoutSubmittedProperties(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $response = $this->ingest([
            'websiteToken' => 'example-token', 'eventName' => 'view',
            'pagePath' => '/shop', 'consentState' => 'granted',
        ], ['revenue' => ['type' => 'number']], $entityManager, $bus);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Ingestion failed'], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testOverflowingJsonNumberNeverReachesAnEnhancedQueueOrEntity(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $event): void {
            self::assertInstanceOf(Event::class, $event);
            $event->enforcePrivacyInvariants();
            self::assertSame(['quantity' => 1, InternalTrafficSettings::JSON_KEY => false], $event->getCustomData());
        });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use ($entityManager): Envelope {
            self::assertInstanceOf(TrackEventMessage::class, $message);
            self::assertSame(['quantity' => 1], $message->eventData);
            (new TrackEventHandler($entityManager))($message);

            return new Envelope($message);
        });
        // The JSON decoder can represent a syntactically valid exponent as
        // PHP infinity. Reject it before JSON storage or transport encoding.
        $rawJson = '{"websiteToken":"example-token","eventName":"purchase_completed","pagePath":"/shop","consentState":"granted","customData":{"revenue":1e309,"quantity":1}}';

        $response = $this->ingest([], [
            'revenue' => ['type' => 'double'], 'quantity' => ['type' => 'integer'],
        ], $entityManager, $bus, $rawJson);

        self::assertSame(202, $response->getStatusCode());
    }

    #[DataProvider('pageSequenceRequests')]
    public function testPageSequenceDirectRequestsEnforceOptInBoundsAndPreserveTheAcceptedSnapshot(bool $enabled, string $numberJson, ?int $accepted, bool $enhanced, string $method): void
    {
        $expected = ['utm_medium' => 'email', ...($accepted === null ? [] : ['page_sequence' => $accepted]), ...($enhanced ? ['private' => 'detail'] : [])];
        $stored = [...$expected, InternalTrafficSettings::JSON_KEY => false];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $event) use ($stored, $enhanced): void {
            self::assertInstanceOf(Event::class, $event);
            if (!$enhanced) {
                // The persistence boundary preserves approved values even if
                // a later assignment tries to replace the depth with an ID.
                $event->setCustomData(['page_sequence' => 'private-identifier', 'private' => 'detail']);
            }
            $event->enforcePrivacyInvariants();
            self::assertSame($stored, $event->getCustomData());
            if (!$enhanced) {
                self::assertNull($event->getVisitorId());
                self::assertNull($event->getSessionId());
                self::assertSame('00:00.000000', $event->getCreatedAt()->format('i:s.u'));
            }
        });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        if ($enhanced) {
            $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use ($entityManager, $expected): Envelope {
                self::assertInstanceOf(TrackEventMessage::class, $message);
                self::assertSame($expected, $message->eventData);
                // Queue serialization and asynchronous persistence must use
                // the submitted page count, never recount pages later.
                (new TrackEventHandler($entityManager))(unserialize(serialize($message)));

                return new Envelope($message);
            });
        } else {
            $bus->expects(self::never())->method('dispatch');
        }
        $consent = $enhanced ? 'granted' : 'denied';
        $rawJson = '{"websiteToken":"example-token","eventName":"button_click","pagePath":"/example","consentState":"'.$consent.'",'
            .'"visitorId":"forged-id","sessionId":"forged-session","customData":{"pageSequenceEnabled":true,"pageSequenceMethod":"url_parameter","consentFreeProperties":["page_sequence","private"]},'
            .'"customData":{"utm_medium":"email","page_sequence":'.$numberJson.',"private":"detail"}}';

        $response = $this->ingest([], ['utm_medium' => ['consent_required' => false]], $entityManager, $bus, $rawJson, ['page_sequence_enabled' => $enabled, 'page_sequence_method' => $method]);

        self::assertSame(202, $response->getStatusCode());
    }

    public static function pageSequenceRequests(): iterable
    {
        foreach ([false, true] as $enhanced) {
            foreach ([false, true] as $enabled) {
                foreach (['2' => 2, '2e0' => 2, '20' => 20, '21' => null, '0' => null, '-2' => null, '2.5' => null, '1e309' => null, '"2"' => null, 'true' => null, 'null' => null, '{}' => null, '[]' => null] as $number => $accepted) {
                    foreach (CustomDataSettings::PAGE_SEQUENCE_METHODS as $method) {
                        yield ($enhanced ? 'enhanced' : 'denied').' '.($enabled ? 'enabled' : 'disabled').' '.$number.' '.$method => [$enabled, (string) $number, $enabled ? $accepted : null, $enhanced, $method];
                    }
                }
            }
        }
    }

    #[DataProvider('invalidPageSequenceSettings')]
    public function testMalformedPageSequenceSettingStopsIngestion(array $settings): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $response = $this->ingest([
            'websiteToken' => 'example-token', 'eventName' => 'view', 'pagePath' => '/example',
            'consentState' => 'denied', 'customData' => ['page_sequence' => 2],
        ], [], $entityManager, $bus, configValues: $settings);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Ingestion failed'], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function invalidPageSequenceSettings(): iterable
    {
        yield 'invalid boolean' => [['page_sequence_enabled' => 'false']];
        yield 'invalid enabled method' => [['page_sequence_enabled' => true, 'page_sequence_method' => 'cookie']];
        yield 'invalid disabled method' => [['page_sequence_enabled' => false, 'page_sequence_method' => 'cookie']];
        yield 'null method' => [['page_sequence_method' => null]];
    }

    public function testUrlCounterParameterInAnHttpPayloadCannotSynthesizeAnEventProperty(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $event): void {
            self::assertInstanceOf(Event::class, $event);
            $event->enforcePrivacyInvariants();
            self::assertSame('/example', $event->getUrl());
            self::assertSame([InternalTrafficSettings::JSON_KEY => false], $event->getCustomData());
        });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $response = $this->ingest([
            'websiteToken' => 'example-token', 'eventName' => 'view',
            'pagePath' => '/example?aggregate_page_sequence=2', 'consentState' => 'denied',
            'aggregate_page_sequence' => 2,
            'customData' => ['pageSequenceEnabled' => true, 'pageSequenceMethod' => 'url_parameter'],
        ], [], $entityManager, $bus, configValues: ['page_sequence_enabled' => true, 'page_sequence_method' => 'url_parameter']);

        self::assertSame(202, $response->getStatusCode());
    }

    #[DataProvider('urlCounterPageSources')]
    public function testUrlCounterTransportIsRemovedFromPageInformationBeforeQueueingAndPersistence(string $field, string $page, bool $enhanced): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $event): void {
            self::assertInstanceOf(Event::class, $event);
            $event->enforcePrivacyInvariants();
            self::assertSame('/example', $event->getUrl());
            self::assertSame('internal', $event->getReferrer());
            self::assertSame(['page_sequence' => 2, InternalTrafficSettings::JSON_KEY => false], $event->getCustomData());
            foreach (['aggregate_page_sequence', 'private-query', 'private-fragment'] as $private) {
                self::assertStringNotContainsString($private, serialize($event));
            }
        });
        $entityManager->expects(self::once())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        if ($enhanced) {
            $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use ($entityManager): Envelope {
                self::assertInstanceOf(TrackEventMessage::class, $message);
                self::assertSame('/example', $message->pagePath);
                self::assertSame('internal', $message->referrerChannel);
                self::assertSame(['page_sequence' => 2], $message->eventData);
                foreach (['aggregate_page_sequence', 'private-query', 'private-fragment'] as $private) {
                    self::assertStringNotContainsString($private, serialize($message));
                }
                (new TrackEventHandler($entityManager))(unserialize(serialize($message)));

                return new Envelope($message);
            });
        } else {
            $bus->expects(self::never())->method('dispatch');
        }

        $response = $this->ingest([
            'websiteToken' => 'example-token', 'eventName' => 'button_click',
            $field => $page,
            'referrer' => 'https://example.test/previous?aggregate_page_sequence=1&private-query=value#private-fragment',
            'consentState' => $enhanced ? 'granted' : 'denied',
            'customData' => ['page_sequence' => 2],
        ], [], $entityManager, $bus, configValues: ['page_sequence_enabled' => true, 'page_sequence_method' => 'url_parameter']);

        self::assertSame(202, $response->getStatusCode());
    }

    public static function urlCounterPageSources(): iterable
    {
        $path = '/example?aggregate_page_sequence=2&private-query=value#private-fragment';
        foreach ([false, true] as $enhanced) {
            yield 'pagePath '.($enhanced ? 'enhanced' : 'anonymous') => ['pagePath', $path, $enhanced];
            yield 'legacy url '.($enhanced ? 'enhanced' : 'anonymous') => ['url', 'https://example.test'.$path, $enhanced];
        }
    }

    private function ingest(array $payload, array $properties, EntityManagerInterface $entityManager, MessageBusInterface $bus, ?string $rawJson = null, array $configValues = []): Response
    {
        $values = [...$configValues, 'custom_data_properties' => $properties, 'query_parameter_mappings' => []];
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('all')->willReturn($values);
        $config->method('getBoolWithEnvFallback')->willReturn(true);
        $config->method('getWithEnvFallback')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $values[$key] ?? $default);
        $websites = $this->createStub(WebsiteConfigManager::class);
        $websites->method('findOneByToken')->willReturn(['domain' => 'example.test', 'token' => 'example-token']);
        $limiter = $this->createStub(IpRateLimiter::class);
        $limiter->method('allow')->willReturn(true);
        $geo = $this->createStub(GeoIpResolverInterface::class);
        $request = Request::create('/api/receive', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://example.test',
        ], content: $rawJson ?? json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $sanitizer = new PrivacySanitizer();

        return (new ReceiveController())(
            $request, $websites, $bus, $limiter, $sanitizer,
            new GoalEventRegistry($sanitizer, []), new PrivacyPolicy($config), $geo,
            new AnonymousEventRecorder($entityManager), new NullLogger(),
            new CustomDataSettings($config),
        );
    }
}
