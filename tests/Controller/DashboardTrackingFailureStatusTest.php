<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DashboardController;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\BrandingLogoManager;
use App\Service\TrackingFailureRetryRunner;
use App\Service\TrackingFailureSettings;
use App\Service\WebsiteConfigManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** The Collection controls page loads its tracking failure status from this endpoint. */
final class DashboardTrackingFailureStatusTest extends TestCase
{
    public function testTheLatestFailuresAndRetryAreRenderedForTheCollectionPage(): void
    {
        $runner = $this->createStub(TrackingFailureRetryRunner::class);
        $runner->method('latestFailure')->willReturn([
            'started_at' => new \DateTimeImmutable('2026-10-08 14:05:00 UTC'),
            'subject' => 'site-token',
            'details' => 'An enhanced event could not be written to events (ConnectionException).',
        ]);
        $runner->method('latestIngestionFailure')->willReturn(null);
        $runner->method('latestRetry')->willReturn([
            'started_at' => new \DateTimeImmutable('2026-10-08 15:00:00 UTC'),
            'status' => 'failed',
            'details' => 'Tracking retries stopped (TransportException).',
        ]);

        $response = $this->controller($runner)->trackingFailureStatus();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $html = preg_replace('/\s+/', ' ', (string) json_decode((string) $response->getContent(), true)['html']);
        self::assertStringContainsString('Last failure: 2026-10-08 14:05 UTC (website token site-token): An enhanced event could not be written', $html);
        self::assertStringContainsString('No anonymous/enhanced ingestion failure has been recorded yet.', $html);
        self::assertStringContainsString('Last retry run: 2026-10-08 15:00 UTC, <strong>failed</strong>: Tracking retries stopped', $html);
    }

    public function testAnUnreadableDatabaseIsReportedWithoutDetails(): void
    {
        $runner = $this->createStub(TrackingFailureRetryRunner::class);
        $runner->method('latestFailure')->willThrowException(new \RuntimeException('SQLSTATE[HY000] connection refused'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $response = $this->controller($runner, logger: $logger)->trackingFailureStatus();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'Tracking failure status is unavailable.'], json_decode((string) $response->getContent(), true));
    }

    public function testOnlyAdministratorsCanReadIt(): void
    {
        $runner = $this->createMock(TrackingFailureRetryRunner::class);
        $runner->expects(self::never())->method('latestFailure');

        $this->expectException(AccessDeniedException::class);
        $this->controller($runner, admin: false)->trackingFailureStatus();
    }

    private function controller(TrackingFailureRetryRunner $runner, bool $admin = true, ?LoggerInterface $logger = null): DashboardController
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('isDashboardEnabled')->willReturn(true);
        $controller = new DashboardController(
            $this->createStub(WebsiteConfigManager::class),
            $config,
            new AnalyticsPrivacySettings($this->createStub(AggregateConfigLoader::class)),
            $this->createStub(UserRepository::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
            new BrandingLogoManager(sys_get_temp_dir(), 'test'),
            new AnonymousBiViewManager($this->createStub(Connection::class)),
            new TrackingFailureSettings($config),
            $runner,
        );
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($admin);
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('twig', new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['strict_variables' => true]));
        $controller->setContainer($container);

        return $controller;
    }
}
