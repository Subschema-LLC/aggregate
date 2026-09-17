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
            'companyStaff' => ['type' => 'boolean', 'consent_required' => false, 'column' => 'staff'],
        ];
        $payload = [
            'websiteToken' => 'example-token',
            'eventName' => 'purchase_completed',
            'pagePath' => '/shop/confirmation?order=private',
            'consentState' => $consent,
            'visitorId' => 'synthetic-visitor',
            'sessionId' => 'synthetic-session',
            'screenWidth' => 1440,
            'internalTraffic' => true,
            // Forged browser rules cannot expand the saved deployment policy.
            'customData' => ['propertyTypes' => [], 'consentFreeProperties' => ['revenue']],
            'eventData' => [
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
                'companyStaff' => false,
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
                $event->setCustomData(['quantity' => 'private-text', 'revenue' => 999, 'companyStaff' => true]);
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
        self::assertSame([...$expected, 'companyStaff' => true], $persisted->getCustomData());
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
            self::assertSame(['quantity' => 1], $event->getCustomData());
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
        $rawJson = '{"websiteToken":"example-token","eventName":"purchase_completed","pagePath":"/shop","consentState":"granted","eventData":{"revenue":1e309,"quantity":1}}';

        $response = $this->ingest([], [
            'revenue' => ['type' => 'double'], 'quantity' => ['type' => 'integer'],
        ], $entityManager, $bus, $rawJson);

        self::assertSame(202, $response->getStatusCode());
    }

    private function ingest(array $payload, array $properties, EntityManagerInterface $entityManager, MessageBusInterface $bus, ?string $rawJson = null): Response
    {
        $values = ['custom_data_properties' => $properties, 'query_parameter_mappings' => [], 'internal_traffic_name' => 'companyStaff'];
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
            new InternalTrafficSettings($config), new CustomDataSettings($config),
        );
    }
}
