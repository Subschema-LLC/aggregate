<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CheckUpdatesCommand;
use App\Command\PullUpdatesCommand;
use App\Service\ApplicationUpdateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdatesCommandTest extends TestCase
{
    public function testCheckUsesCacheAndDoesNotPull(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('check')->with(false)->willReturn($this->updateStatus());
        $updates->expects(self::never())->method('pull');
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Subschema-LLC/aggregate', $tester->getDisplay());
        self::assertStringContainsString('app:updates:pull', $tester->getDisplay());
        self::assertStringContainsString('docs/UPDATES.md', $tester->getDisplay());
        self::assertStringContainsString('app:updates:apply', $tester->getDisplay());
    }

    #[DataProvider('checkStates')]
    public function testRefreshedJsonReportsStatusAndExitCode(string $state, int $exitCode): void
    {
        $status = $this->updateStatus($state);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('check')->with(true)->willReturn($status);
        $updates->expects(self::never())->method('pull');
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame($exitCode, $tester->execute(['--refresh' => true, '--json' => true]));
        self::assertSame($status, json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function checkStates(): iterable
    {
        foreach (['available', 'up_to_date', 'ahead', 'diverged'] as $state) {
            yield $state => [$state, Command::SUCCESS];
        }
        foreach (['unknown', 'error', 'unavailable', 'incompatible', 'disabled'] as $state) {
            yield $state => [$state, Command::FAILURE];
        }
    }

    public function testFailedCheckDoesNotSuggestAPull(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($this->updateStatus('error'));
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('[ERROR]', $tester->getDisplay());
        self::assertStringNotContainsString('app:updates:pull', $tester->getDisplay());
    }

    #[DataProvider('messageBlockStates')]
    public function testCheckPreservesLiteralMessageInEveryBlockStyle(string $state, int $exitCode, bool $decorated): void
    {
        $message = 'Update <info>state</info>.';
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->updateStatus($state), ['message' => $message]));
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame($exitCode, $tester->execute([], ['decorated' => $decorated]));
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    public static function messageBlockStates(): iterable
    {
        foreach ([false, true] as $decorated) {
            yield ['error', Command::FAILURE, $decorated];
            yield ['up_to_date', Command::SUCCESS, $decorated];
            yield ['available', Command::SUCCESS, $decorated];
        }
    }

    public function testMismatchedBranchShowsConfiguredAndInstalledBranchesWithoutSuggestingPull(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn(array_replace($this->updateStatus(), [
            'branch' => 'master',
            'installed_branch' => 'development',
        ]));
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Configured branch', $tester->getDisplay());
        self::assertStringContainsString('Installed branch', $tester->getDisplay());
        self::assertStringContainsString('master', $tester->getDisplay());
        self::assertStringContainsString('development', $tester->getDisplay());
        self::assertStringContainsString('does not match updates_branch', $tester->getDisplay());
        self::assertStringNotContainsString('app:updates:pull', $tester->getDisplay());
    }

    public function testReleaseCheckReportsVersionsAndVerificationWithoutSuggestingGitPull(): void
    {
        $status = array_replace($this->updateStatus(), [
            'installation_type' => 'release',
            'current_version' => '1.0.0',
            'latest_version' => '1.1.0',
            'release_url' => 'https://github.com/Subschema-LLC/aggregate/releases/tag/v1.1.0',
            'package_url' => 'https://github.com/Subschema-LLC/aggregate/releases/download/v1.1.0/aggregate-1.1.0.zip',
            'manifest_url' => 'https://github.com/Subschema-LLC/aggregate/releases/download/v1.1.0/release-manifest.json',
            'signature_url' => 'https://github.com/Subschema-LLC/aggregate/releases/download/v1.1.0/release-manifest.sig',
        ]);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($status);
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Installed version', $tester->getDisplay());
        self::assertStringContainsString('1.0.0', $tester->getDisplay());
        self::assertStringContainsString('1.1.0', $tester->getDisplay());
        foreach (['release_url', 'package_url', 'manifest_url', 'signature_url'] as $key) {
            self::assertStringContainsString($status[$key], $tester->getDisplay());
        }
        self::assertStringContainsString('signature has not been verified', $tester->getDisplay());
        self::assertStringContainsString('app:updates:verify-package', $tester->getDisplay());
        self::assertStringNotContainsString('app:updates:pull', $tester->getDisplay());
    }

    public function testDeploymentCheckReportsTheDeployedCommitAndPendingSteps(): void
    {
        $status = array_replace($this->updateStatus(), [
            'installation_type' => 'deployment',
            'branch' => 'master',
            'commits_behind' => 4,
            'commit_source' => 'repository',
            'deployed_at' => 1789426800,
            'deployment_pending' => true,
            'deployment_repository' => '/var/www/vhosts/example.com/git/aggregate.git',
        ]);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($status);
        $updates->expects(self::never())->method('pull');
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Deployed another way', $display);
        self::assertStringContainsString(str_repeat('a', 40).' (read from the deployment repository)', $display);
        self::assertStringContainsString('Commits behind 4', $display);
        self::assertStringContainsString('files changed since', $display);
        self::assertStringContainsString('/var/www/vhosts/example.com/git/aggregate.git', $display);
        self::assertStringContainsString('may be pending. Run php bin/console app:updates:deployed', $display);
        self::assertStringContainsString('Deploy the newer commits with your deployment tool', $display);
        self::assertStringNotContainsString('app:updates:apply', $display);
        self::assertStringNotContainsString('app:updates:pull', $display);
    }

    public function testSuccessfulPullReportsThatDeploymentStillNeedsCompletion(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('pull')->willReturn([
            'branch' => 'development',
            'previous_commit' => str_repeat('a', 40),
            'current_commit' => str_repeat('b', 40),
            'changed' => true,
        ]);
        $tester = new CommandTester(new PullUpdatesCommand($updates));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('source code was updated', $tester->getDisplay());
        self::assertStringContainsString('Deployment is not complete', $tester->getDisplay());
        self::assertStringContainsString('migrations', $tester->getDisplay());
        self::assertStringContainsString(str_repeat('b', 40), $tester->getDisplay());
    }

    public function testNoOpPullDoesNotClaimCodeWasUpdated(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('pull')->willReturn([
            'branch' => 'development',
            'previous_commit' => str_repeat('a', 40),
            'current_commit' => str_repeat('a', 40),
            'changed' => false,
        ]);
        $tester = new CommandTester(new PullUpdatesCommand($updates));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/source code is\s+unchanged/', $tester->getDisplay());
        self::assertStringNotContainsString('source code was updated', $tester->getDisplay());
    }

    #[DataProvider('outputModes')]
    public function testRejectedPullReturnsFailureAndDoesNotClaimSuccess(bool $decorated): void
    {
        $message = 'Local changes in <info>checkout</info>.';
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('pull')->willThrowException(new \RuntimeException($message));
        $tester = new CommandTester(new PullUpdatesCommand($updates));

        self::assertSame(Command::FAILURE, $tester->execute([], ['decorated' => $decorated]));
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    public static function outputModes(): iterable
    {
        yield 'plain' => [false];
        yield 'ANSI' => [true];
    }

    private function updateStatus(string $state = 'available'): array
    {
        return [
            'state' => $state,
            'installation_type' => 'git',
            'branch' => 'development',
            'installed_branch' => 'development',
            'current_commit' => str_repeat('a', 40),
            'latest_commit' => str_repeat('b', 40),
            'checked_at' => 1789426800,
            'message' => 'Update status: '.$state,
            'compare_url' => 'https://github.com/Subschema-LLC/aggregate/compare/'.str_repeat('a', 40).'...'.str_repeat('b', 40),
        ];
    }
}
