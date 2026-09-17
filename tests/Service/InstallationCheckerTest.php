<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\InstallationChecker;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallationCheckerTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['ANONYMOUS_TRACKING_ENABLED'], $_SERVER['ANONYMOUS_TRACKING_ENABLED']);
        $this->projectDir = sys_get_temp_dir().'/aggregate-install-check-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        @unlink($this->projectDir.'/config/aggregate.yaml');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testInstalledConfigurationDoesNotNeedADatabaseQuery(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('count');

        self::assertTrue($this->checker($users, 'installed: true')->isInstalled());
    }

    #[DataProvider('userCounts')]
    public function testExistingUsersDetermineInstallationStatus(int $count, bool $installed): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('count')->with([])->willReturn($count);

        self::assertSame($installed, $this->checker($users)->isInstalled());
    }

    public static function userCounts(): iterable
    {
        yield 'empty users table' => [0, false];
        yield 'existing administrator' => [1, true];
    }

    public function testMissingUsersTableStillAllowsFreshInstallation(): void
    {
        $driverError = new class('Synthetic missing users table') extends \RuntimeException implements DriverException {
            public function getSQLState(): string { return '42S02'; }
        };
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('count')->willThrowException(new TableNotFoundException($driverError, null));

        self::assertFalse($this->checker($users)->isInstalled());
    }

    public function testDatabaseFailureDoesNotReopenInstallation(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('count')->willThrowException(new \RuntimeException('Synthetic database failure'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Synthetic database failure');
        $this->checker($users)->isInstalled();
    }

    public function testMalformedConfigurationDoesNotReopenInstallation(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('count');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Application configuration is invalid.');
        $this->checker($users, 'installed: [')->isInstalled();
    }

    private function checker(UserRepository $users, string $yaml = ''): InstallationChecker
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', $yaml);

        return new InstallationChecker($users, new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
