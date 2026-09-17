<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Command\CheckUpdatesCommand;
use App\Command\PullUpdatesCommand;
use App\Command\VerifyReleasePackageCommand;
use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\FeatureFlags;
use App\Service\InstalledRelease;
use App\Service\ReleasePackageVerifier;
use App\Service\ReleaseUpdateService;
use App\Service\UpdateSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\Cache\CacheInterface;

final class UpdateFeatureFlagsTest extends TestCase
{
    #[DataProvider('blockedConfigurations')]
    public function testChecksStopBeforeNetworkCacheOrInstallationAccess(mixed $flags): void
    {
        [$config, $features, $settings] = $this->configuration($flags);
        $client = new MockHttpClient(static function (): never {
            self::fail('A disabled feature must not contact GitHub.');
        });
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('get');
        $clock = new MockClock();
        // No installation exists: disabled status must precede local discovery.
        $project = '/nonexistent-aggregate-feature-flag-test';
        $releases = new ReleaseUpdateService(new InstalledRelease($project), $client, $cache, $clock, $settings, $features);
        $updates = new ApplicationUpdateService($project, $client, $cache, $clock, $features, settings: $settings, releases: $releases);

        foreach ([$updates, $releases] as $service) {
            foreach ([false, true] as $refresh) {
                $result = $service->check($refresh);
                self::assertSame('disabled', $result['state']);
                self::assertNull($result['checked_at']);
                self::assertNull($result['current_commit']);
                self::assertNull($result['branch']);
            }
        }
        $tester = new CommandTester(new CheckUpdatesCommand($updates));
        self::assertSame(Command::FAILURE, $tester->execute(['--refresh' => true, '--json' => true]));
        self::assertSame('disabled', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['state']);
        self::assertSame(0, $client->getRequestsCount());
    }

    #[DataProvider('blockedConfigurations')]
    public function testPullAndVerificationCommandsCannotBypassTheFlag(mixed $flags): void
    {
        [$config, $features, $settings] = $this->configuration($flags);
        $client = new MockHttpClient([]);
        $cache = $this->createStub(CacheInterface::class);
        $project = '/nonexistent-aggregate-feature-flag-test';
        $updates = new ApplicationUpdateService($project, $client, $cache, new MockClock(), $features, settings: $settings);
        $verifier = new ReleasePackageVerifier($config, $settings, $project, $features);

        $pull = new CommandTester(new PullUpdatesCommand($updates));
        self::assertSame(Command::FAILURE, $pull->execute([]));
        self::assertStringContainsString('feature is disabled', $pull->getDisplay());

        $verify = new CommandTester(new VerifyReleasePackageCommand($verifier));
        self::assertSame(Command::FAILURE, $verify->execute([
            'package' => 'https://example.invalid/package.zip',
            'manifest' => 'https://example.invalid/manifest.json',
            'signature' => 'https://example.invalid/manifest.sig',
        ]));
        self::assertStringContainsString('feature is disabled', $verify->getDisplay());
        self::assertSame(0, $client->getRequestsCount());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('feature is disabled');
        $verifier->verifyManifest('invalid manifest', 'invalid signature');
    }

    public static function blockedConfigurations(): iterable
    {
        yield 'disabled but visible' => [['updates' => ['enabled' => false, 'hide_from_navigation' => false]]];
        yield 'disabled and hidden' => [['updates' => ['enabled' => false, 'hide_from_navigation' => true]]];
        yield 'string boolean' => [['updates' => ['enabled' => 'false']]];
        yield 'malformed flag' => [['updates' => null]];
        yield 'malformed mapping' => [null];
        yield 'unknown flag' => [['upddates' => ['enabled' => false]]];
    }

    private function configuration(mixed $flags): array
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('all')->willReturn([
            'dashboard_enabled' => false,
            'feature_flags' => $flags,
        ]);

        return [$config, new FeatureFlags($config), new UpdateSettings($config)];
    }
}
